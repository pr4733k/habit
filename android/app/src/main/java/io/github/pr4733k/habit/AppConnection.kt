package io.github.pr4733k.habit

import android.content.Context
import android.content.Intent
import java.time.LocalDate

internal data class AppConnection(val origin: String, val cookie: String, val identity: String)
internal fun appOrigin(context: Context): String? = LogSession(context).connection()?.origin
internal fun appToday(context: Context): LocalDate = LogSession(context).connection()?.today() ?: LocalDate.now()
internal fun appConnection(context: Context): AppConnection? {
    val session = LogSession(context); val connection = session.connection() ?: return null
    return session.read(connection)?.let { AppConnection(connection.origin, it, connection.id) }
}
internal fun appCookie(context: Context, cookie: String) {
    val session = LogSession(context); val connection = session.connection() ?: return
    if (sessionValuePattern.matches(cookie)) session.write(connection, cookie)
}
internal fun appSignOut(context: Context) { val session = LogSession(context); session.connection()?.let(session::clear) }
internal fun canSwitchSite() = true
internal fun setupIntent(context: Context) = Intent(context, SetupActivity::class.java)
internal fun cacheSummary(context: Context, summary: DailySummary) {
    val session = LogSession(context); session.connection()?.let { DailyCache(context).save(it, summary) }
}
