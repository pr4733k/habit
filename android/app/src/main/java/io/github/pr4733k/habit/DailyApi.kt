package io.github.pr4733k.habit

import org.json.JSONObject
import java.io.IOException
import java.net.HttpCookie
import java.net.HttpURLConnection
import java.net.URI
import java.time.LocalDate
import java.time.YearMonth
import java.time.ZoneId
import java.util.UUID

internal const val sessionCookieName = "daily_log_access"
internal val sessionValuePattern = Regex("[0-9a-f]{64}\\.[0-9a-f]{64}")

internal class DailyHttpError(val status: Int, message: String) : IOException(message)
internal data class Submission(val date: LocalDate, val text: String, val id: String = UUID.randomUUID().toString())

internal fun habitOrigin(input: String): String {
    val uri = URI.create(input.trim())
    require(uri.scheme.equals("https", ignoreCase = true) && uri.host != null && uri.userInfo == null &&
        uri.rawQuery == null && uri.rawFragment == null && uri.path.trimEnd('/') in listOf("", "/daily", "/log", "/daily/log") &&
        (uri.port == -1 || uri.port in 1..65535))
    return URI("https", null, uri.host.lowercase(), uri.port.takeUnless { it == 443 } ?: -1, null, null, null).toString()
}

internal class DailyApi(private val site: String) {
    fun workoutSession(date: LocalDate, session: String): JSONObject = request("workout-session&date=$date", session = session).json

    fun saveExercise(payload: JSONObject, key: String, session: String): JSONObject = try {
        confirmExercise(request("save-exercise", payload, session).json, payload.getString("date"), key)
    } catch (error: IllegalArgumentException) { throw IOException(error.message, error) }


    fun timeZone(): ZoneId {
        val value = request("settings").json.optString("timeZone")
        return runCatching { ZoneId.of(value) }.getOrElse { throw IOException("This address did not return Habit settings.") }
    }

    fun today(date: LocalDate, session: String): DailySummary {
        try {
            return DailySummary.fromWidget(request("widget&date=$date", session = session).json)
        } catch (error: DailyHttpError) {
            if (error.status != 404) throw error
        }
        return DailySummary.fromMonth(request("month&month=${YearMonth.from(date)}", session = session).json, date)
    }

    fun addWater(date: LocalDate, id: String, session: String) {
        val body = JSONObject().put("date", date.toString()).put("id", id)
        val note = request("water", body, session).json.optJSONObject("note")
        if (note?.optString("id") != id || note.optString("date") != date.toString() || note.isNull("parsed")) {
            throw IOException("The tracker did not confirm the water entry.")
        }
    }

    fun signIn(password: String): String {
        val response = request("login", JSONObject().put("password", password))
        val session = response.cookies.firstOrNull {
            it.name == sessionCookieName && sessionValuePattern.matches(it.value)
        }
        if (!response.json.optBoolean("signedIn") || session == null) {
            throw IOException("Habit did not return a sign-in session.")
        }
        return session.value
    }

    fun save(submission: Submission, session: String): Boolean {
        val body = JSONObject()
            .put("id", submission.id)
            .put("date", submission.date.toString())
            .put("text", submission.text)
        val note = request("save", body, session).json.optJSONObject("note")
        if (note == null || note.optString("id") != submission.id ||
            note.optString("date") != submission.date.toString()
        ) {
            throw IOException("Habit did not confirm the note. Tap Save to retry.")
        }
        return note.isNull("parsed")
    }

    private data class Response(val json: JSONObject, val cookies: List<HttpCookie>)

    private fun request(action: String, body: JSONObject? = null, session: String? = null): Response {
        val connection = URI.create("$site/daily/api.php?action=$action").toURL()
            .openConnection() as HttpURLConnection
        try {
            connection.connectTimeout = 12_000
            connection.readTimeout = 15_000
            connection.instanceFollowRedirects = false
            connection.useCaches = false
            connection.setRequestProperty("Accept", "application/json")
            if (session != null) connection.setRequestProperty("Cookie", "$sessionCookieName=$session")
            if (body != null) {
                connection.requestMethod = "POST"
                connection.doOutput = true
                connection.setRequestProperty("Content-Type", "application/json; charset=utf-8")
                connection.setRequestProperty("X-Daily-Request", "1")
                val bytes = body.toString().toByteArray(Charsets.UTF_8)
                connection.setFixedLengthStreamingMode(bytes.size)
                connection.outputStream.use { it.write(bytes) }
            }
            val status = connection.responseCode
            val ok = status in 200..299
            val raw = (if (ok) connection.inputStream else connection.errorStream)
                ?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
            val json = runCatching { JSONObject(raw) }.getOrNull()
            if (!ok) {
                val message = json?.optString("error")?.takeIf { it.isNotBlank() }
                throw DailyHttpError(status, message ?: "Habit is unavailable ($status).")
            }
            if (json == null) throw IOException("Habit returned an unreadable response.")
            val cookies = connection.headerFields.entries
                .filter { it.key.equals("Set-Cookie", ignoreCase = true) }
                .flatMap { entry -> entry.value.flatMap { runCatching { HttpCookie.parse(it) }.getOrDefault(emptyList()) } }
            return Response(json, cookies)
        } finally {
            connection.disconnect()
        }
    }
}
