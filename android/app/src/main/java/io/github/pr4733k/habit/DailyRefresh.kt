package io.github.pr4733k.habit

import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import androidx.glance.appwidget.updateAll
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.OutOfQuotaPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.channels.awaitClose
import kotlinx.coroutines.flow.callbackFlow
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.IOException
import java.time.LocalDate
import java.util.UUID
import java.util.concurrent.TimeUnit

internal class DailyCache(context: Context) {
    private val prefs = context.getSharedPreferences("widget_day", Context.MODE_PRIVATE)
    private val session = LogSession(context)

    data class State(val date: LocalDate, val summary: DailySummary?, val failed: Boolean, val origin: String?, val signedIn: Boolean)

    fun current(): State {
        val connection = session.connection()
        val date = connection?.today() ?: LocalDate.now()
        val signedIn = connection != null && session.isSignedIn()
        val active = signedIn && prefs.getString("connection", null) == connection?.id
        val summary = prefs.getString("summary", null)?.takeIf { active }?.let {
            runCatching { DailySummary.fromCache(JSONObject(it)) }.getOrNull()?.takeIf { day -> day.date == date }
        }
        return State(date, summary, active && prefs.getString("failed", null) == date.toString(), connection?.origin, signedIn)
    }

    fun observe() = callbackFlow {
        val listener = SharedPreferences.OnSharedPreferenceChangeListener { _, _ -> trySend(current()) }
        prefs.registerOnSharedPreferenceChangeListener(listener)
        session.preferences.registerOnSharedPreferenceChangeListener(listener)
        trySend(current())
        awaitClose {
            prefs.unregisterOnSharedPreferenceChangeListener(listener)
            session.preferences.unregisterOnSharedPreferenceChangeListener(listener)
        }
    }.distinctUntilChanged()

    fun save(connection: HabitConnection, summary: DailySummary) {
        if (session.connection() != connection) return
        prefs.edit().putString("connection", connection.id)
            .putString("summary", summary.toJson().toString()).remove("failed").apply()
    }

    fun fail(connection: HabitConnection, date: LocalDate) {
        if (session.connection() != connection) return
        val editor = prefs.edit()
        if (prefs.getString("connection", null) != connection.id) editor.remove("summary")
        editor.putString("connection", connection.id).putString("failed", date.toString()).apply()
    }
}

private val refreshLock = Mutex()

class DailyRefreshWorker(context: Context, parameters: WorkerParameters) : CoroutineWorker(context, parameters) {

    override suspend fun doWork(): Result = refreshLock.withLock {
        val followUp = inputData.getBoolean("follow_up", false)
        val session = LogSession(applicationContext)
        val connection = session.connection() ?: return@withLock Result.success()
        val cookie = withContext(Dispatchers.IO) { session.read(connection) }
        if (cookie == null) {
            session.clear(connection)
            DailyWidget().updateAll(applicationContext)
            return@withLock Result.success()
        }
        val day = connection.today()
        val cache = DailyCache(applicationContext)
        val updated = try {
            val summary = withContext(Dispatchers.IO) { DailyApi(connection.origin).today(day, cookie) }
            cache.save(connection, summary)
            !followUp || summary.pendingCount == 0
        } catch (error: IOException) {
            if (error is DailyHttpError && error.status == 401) {
                session.clear(connection)
                true
            } else {
                cache.fail(connection, day)
                false
            }
        }
        DailyWidget().updateAll(applicationContext)
        if (updated || (followUp && runAttemptCount >= 5)) Result.success() else Result.retry()
    }
}

class AddWaterWorker(context: Context, parameters: WorkerParameters) : CoroutineWorker(context, parameters) {
    override suspend fun doWork(): Result = refreshLock.withLock {
        val context = applicationContext
        val date = inputData.getString("date")?.let(LocalDate::parse) ?: return@withLock Result.failure()
        val id = inputData.getString("note_id") ?: return@withLock Result.failure()
        val session = LogSession(context)
        val connection = session.connection()?.takeIf { it.id == inputData.getString("connection") }
            ?: return@withLock Result.failure()
        val cookie = withContext(Dispatchers.IO) { session.read(connection) }
        if (cookie == null) {
            DailyWidget().updateAll(context)
            return@withLock Result.failure()
        }
        val cache = DailyCache(context)
        val api = DailyApi(connection.origin)
        try {
            withContext(Dispatchers.IO) { api.addWater(date, id, cookie) }
        } catch (error: DailyHttpError) {
            if (error.status == 401) session.clear(connection)
            if (error.status in 400..499 && error.status !in listOf(408, 429)) {
                cache.fail(connection, date)
                DailyWidget().updateAll(context)
                return@withLock Result.failure()
            }
            return@withLock Result.retry()
        } catch (_: IOException) {
            return@withLock Result.retry()
        }
        try {
            val summary = withContext(Dispatchers.IO) { api.today(connection.today(), cookie) }
            cache.save(connection, summary)
            DailyWidget().updateAll(context)
        } catch (_: IOException) {
            DailyRefresh.now(context, userRequested = true)
        }
        Result.success()
    }
}

internal object DailyRefresh {
    private const val periodic = "daily-widget-periodic"
    private const val immediate = "daily-widget-immediate"
    private const val followUp = "daily-widget-follow-up"
    private val connected = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    fun start(context: Context) {
        val work = PeriodicWorkRequestBuilder<DailyRefreshWorker>(15, TimeUnit.MINUTES)
            .setInitialDelay(15, TimeUnit.MINUTES)
            .setConstraints(connected)
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(periodic, ExistingPeriodicWorkPolicy.UPDATE, work)
        now(context)
    }

    fun now(context: Context, userRequested: Boolean = false) {
        val work = OneTimeWorkRequestBuilder<DailyRefreshWorker>().setConstraints(connected)
        if (userRequested && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            work.setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
        }
        val policy = if (userRequested) ExistingWorkPolicy.REPLACE else ExistingWorkPolicy.KEEP
        WorkManager.getInstance(context).enqueueUniqueWork(immediate, policy, work.build())
    }

    fun afterSave(context: Context, pending: Boolean) {
        now(context, userRequested = true)
        if (!pending) return
        val work = OneTimeWorkRequestBuilder<DailyRefreshWorker>()
            .setInputData(workDataOf("follow_up" to true))
            .setConstraints(connected)
            .setInitialDelay(8, TimeUnit.SECONDS)
            .setBackoffCriteria(BackoffPolicy.LINEAR, 10, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(followUp, ExistingWorkPolicy.REPLACE, work)
    }

    suspend fun addWater(context: Context) {
        val connection = LogSession(context).connection() ?: return
        val data = workDataOf("date" to connection.today().toString(), "note_id" to UUID.randomUUID().toString(),
            "connection" to connection.id)
        val work = OneTimeWorkRequestBuilder<AddWaterWorker>()
            .setInputData(data).setConstraints(connected)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            work.setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
        }
        withContext(Dispatchers.IO) { WorkManager.getInstance(context).enqueue(work.build()).result.get() }
    }

    fun stop(context: Context) {
        WorkManager.getInstance(context).cancelUniqueWork(periodic)
        WorkManager.getInstance(context).cancelUniqueWork(immediate)
        WorkManager.getInstance(context).cancelUniqueWork(followUp)
    }
}
