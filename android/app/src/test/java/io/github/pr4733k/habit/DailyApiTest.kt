package io.github.pr4733k.habit

import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.LocalDate
import java.time.ZoneId

class DailyApiTest {
    @Test fun acceptsOnlyHttpsHabitSiteAddresses() {
        assertEquals("https://tracker.example.org", habitOrigin(" https://Tracker.example.org:443/daily/ "))
        assertEquals("https://tracker.example.org:8443", habitOrigin("https://tracker.example.org:8443/log"))
        for (bad in listOf("http://tracker.example.org", "https://user@tracker.example.org", "https://tracker.example.org/?token=x",
            "https://tracker.example.org/habit/daily", "https://tracker.example.org/#fragment", "tracker.example.org")) {
            assertThrows(IllegalArgumentException::class.java) { habitOrigin(bad) }
        }
    }

    @Test fun doesNotFollowRedirectsWithCredentials() {
        MockWebServer().use { target ->
            MockWebServer().use { site ->
                site.enqueue(MockResponse().setResponseCode(307).addHeader("Location", target.url("/collect")))
                val api = DailyApi(site.url("/").toString().trimEnd('/'))
                assertEquals(307, assertThrows(DailyHttpError::class.java) { api.signIn("local-test-password") }.status)
                assertEquals(0, target.requestCount)
            }
        }
    }

    @Test fun usesDailyLoginAndSavesWithTheSameIdOnRetry() {
        val token = "a".repeat(64) + "." + "b".repeat(64)
        val day = LocalDate.parse("2024-04-12")
        val submission = Submission(day, "Yogurt and 500 mL water")
        val receipt = JSONObject().put("note", JSONObject()
            .put("id", submission.id).put("date", day.toString()).put("parsed", JSONObject.NULL))

        MockWebServer().use { server ->
            server.enqueue(MockResponse().setBody("""{"timeZone":"America/Chicago","publicCalendar":false}"""))
            server.enqueue(MockResponse()
                .addHeader("Set-Cookie", "daily_login_attempts=test; Path=/daily; HttpOnly")
                .addHeader("Set-Cookie", "daily_log_access=$token; Path=/daily; Secure; HttpOnly; SameSite=Strict")
                .setBody("""{"signedIn":true}"""))
            server.enqueue(MockResponse().setResponseCode(404))
            server.enqueue(MockResponse().setBody("""{"entries":[],"goalTargets":[]}"""))
            repeat(2) { server.enqueue(MockResponse().setBody(receipt.toString())) }
            val api = DailyApi(server.url("/").toString().trimEnd('/'))

            assertEquals(ZoneId.of("America/Chicago"), api.timeZone())
            val settings = server.takeRequest()
            assertEquals("/daily/api.php?action=settings", settings.path)
            assertNull(settings.getHeader("Cookie"))
            assertEquals(token, api.signIn("local-test-password"))
            val login = server.takeRequest()
            assertEquals("/daily/api.php?action=login", login.path)
            assertEquals("1", login.getHeader("X-Daily-Request"))
            assertEquals("local-test-password", JSONObject(login.body.readUtf8()).getString("password"))

            assertEquals(day, api.today(day, token).date)
            val widget = server.takeRequest()
            assertEquals("/daily/api.php?action=widget&date=2024-04-12", widget.path)
            assertEquals("daily_log_access=$token", widget.getHeader("Cookie"))
            val month = server.takeRequest()
            assertEquals("GET", month.method)
            assertEquals("/daily/api.php?action=month&month=2024-04", month.path)
            assertEquals("daily_log_access=$token", month.getHeader("Cookie"))

            assertTrue(api.save(submission, token))
            assertTrue(api.save(submission, token))
            val firstSave = server.takeRequest()
            val retry = server.takeRequest()
            assertEquals("POST", firstSave.method)
            assertEquals("/daily/api.php?action=save", firstSave.path)
            assertEquals("1", firstSave.getHeader("X-Daily-Request"))
            assertEquals("daily_log_access=$token", firstSave.getHeader("Cookie"))
            val payload = firstSave.body.readUtf8()
            val body = JSONObject(payload)
            assertEquals(submission.id, body.getString("id"))
            assertEquals(day.toString(), body.getString("date"))
            assertEquals(submission.text, body.getString("text"))
            assertEquals(payload, retry.body.readUtf8())
        }
    }
}
