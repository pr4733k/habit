package io.github.pr4733k.habit

import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test
import java.time.LocalDate
import java.util.UUID

class WaterWidgetTest {
    @Test fun savesStructuredWaterAndReadsRefreshedTotals() {
        val date = LocalDate.parse("2024-04-12")
        val id = UUID.randomUUID().toString()
        MockWebServer().use { server ->
            server.enqueue(MockResponse().setBody("""{"note":{"id":"$id","date":"$date","parsed":{"water":[{"mode":"add","ml":100}]}}}"""))
            server.enqueue(MockResponse().setBody("""{"date":"$date","waterMl":600,"pendingCount":1,"goals":{"water_ml":2000}}"""))
            val api = DailyApi(server.url("/").toString().trimEnd('/'))
            api.addWater(date, id, "session")
            val request = server.takeRequest()
            assertEquals("/daily/api.php?action=water", request.path)
            assertEquals("1", request.getHeader("X-Daily-Request"))
            val body = JSONObject(request.body.readUtf8())
            assertEquals(id, body.getString("id"))
            assertFalse(body.has("text"))
            val summary = api.today(date, "session")
            assertEquals(600, summary.waterMl)
            assertEquals(1, summary.pendingCount)
            assertEquals("daily_log_access=session", server.takeRequest().getHeader("Cookie"))
        }
    }
}
