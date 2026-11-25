package io.github.pr4733k.habit

import android.content.Context
import android.content.SharedPreferences
import androidx.glance.appwidget.updateAll
import androidx.work.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.channels.awaitClose
import kotlinx.coroutines.flow.callbackFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.util.UUID
import java.util.concurrent.TimeUnit

internal class WorkoutStore(private val context: Context) {
    private val prefs = context.getSharedPreferences("workout_queue", Context.MODE_PRIVATE)
    private fun scope(origin: String, date: String) = "$origin|$date"
    private fun read(key: String) = runCatching { JSONObject(prefs.getString(key, "{}")!!) }.getOrDefault(JSONObject())
    fun session(origin: String, date: String): JSONObject? = synchronized(lock) { read(scope(origin, date)).optJSONObject("session") }
    fun active(origin: String, date: String) = prefs.getBoolean("active:${scope(origin, date)}", false)
    fun setActive(origin: String, date: String, active: Boolean) { prefs.edit().putBoolean("active:${scope(origin, date)}", active).commit() }
    fun saveSession(origin: String, session: JSONObject) = synchronized(lock) {
        val key = scope(origin, session.getString("date")); val data = read(key)
        data.put("session", session); check(prefs.edit().putString(key, data.toString()).commit())
    }
    fun items(origin: String, date: String): List<JSONObject> = synchronized(lock) { read(scope(origin, date)).optJSONArray("queue").objects() }
    fun draft(origin: String, date: String, exercise: String): JSONObject? = synchronized(lock) { read("draft:${scope(origin, date)}:$exercise").takeIf { it.has("groups") } }
    fun saveDraft(origin: String, date: String, exercise: String, draft: JSONObject) = synchronized(lock) {
        check(prefs.edit().putString("draft:${scope(origin, date)}:$exercise", draft.toString()).commit())
    }
    fun enqueue(origin: String, key: String, payload: JSONObject) = synchronized(lock) {
        require(validGroups(payload.getJSONArray("groups"))) { "Complete the weight, reps and sets first." }
        val date = payload.getString("date"); val bucket = scope(origin, date); val data = read(bucket)
        val queue = data.optJSONArray("queue").objects().filter { it.optString("key") != key }.toMutableList()
        queue.add(JSONObject().put("key", key).put("payload", JSONObject(payload.toString()))
            .put("mutation", UUID.randomUUID().toString()).put("status", "pending"))
        data.put("queue", JSONArray(queue)); check(prefs.edit().putString(bucket, data.toString()).remove("draft:$bucket:$key").commit())
        setActive(origin, date, true)
        schedule(context)
    }
    fun next(origin: String): Pair<String, JSONObject>? = synchronized(lock) {
        prefs.all.keys.filter { it.startsWith("$origin|") }.sorted().firstNotNullOfOrNull { bucket ->
            read(bucket).optJSONArray("queue").objects().firstOrNull { it.optString("status") == "pending" }?.let { bucket to it }
        }
    }
    fun acknowledge(bucket: String, sent: JSONObject, entry: JSONObject) = synchronized(lock) {
        val data = read(bucket)
        val queue = data.optJSONArray("queue").objects().mapNotNull { item ->
            if (item.optString("key") != sent.optString("key")) item
            else if (item.optString("mutation") == sent.optString("mutation")) null
            else item.apply { getJSONObject("payload").put("revision", entry.getInt("revision")) }
        }
        data.optJSONObject("session")?.optJSONArray("exercises").objects().firstOrNull { it.optString("key") == sent.optString("key") }?.put("saved", entry)
        data.put("queue", JSONArray(queue)); check(prefs.edit().putString(bucket, data.toString()).commit())
    }
    fun fail(bucket: String, sent: JSONObject, message: String) = synchronized(lock) {
        val data = read(bucket)
        data.optJSONArray("queue").objects().firstOrNull { it.optString("mutation") == sent.optString("mutation") }
            ?.put("status", "attention")?.put("error", message)
        check(prefs.edit().putString(bucket, data.toString()).commit())
    }
    fun observe() = callbackFlow {
        val listener = SharedPreferences.OnSharedPreferenceChangeListener { _, _ -> trySend(System.nanoTime()) }
        prefs.registerOnSharedPreferenceChangeListener(listener); trySend(System.nanoTime())
        awaitClose { prefs.unregisterOnSharedPreferenceChangeListener(listener) }
    }
    companion object {
        private val lock = Any()
        fun schedule(context: Context) {
            val request = OneTimeWorkRequestBuilder<ExerciseSaveWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.LINEAR, 10, TimeUnit.SECONDS)
                .setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST).build()
            WorkManager.getInstance(context).enqueueUniqueWork("daily-exercise-save", ExistingWorkPolicy.APPEND_OR_REPLACE, request)
        }
    }
}

private val exerciseSaveLock = Mutex()
class ExerciseSaveWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result = exerciseSaveLock.withLock {
        val context = applicationContext; val store = WorkoutStore(context)
        val connection = withContext(Dispatchers.IO) { appConnection(context) } ?: return@withLock Result.success()
        val api = DailyApi(connection.origin)
        while (!isStopped) {
            val (bucket, item) = store.next(connection.origin) ?: break
            if (appConnection(context)?.identity != connection.identity) break
            try {
                val entry = withContext(Dispatchers.IO) { api.saveExercise(item.getJSONObject("payload"), item.getString("key"), connection.cookie) }
                store.acknowledge(bucket, item, entry)
                DailyWidget().updateAll(context)
            } catch (error: DailyHttpError) {
                if (error.status == 401) {
                    if (appConnection(context)?.identity == connection.identity) appSignOut(context)
                    DailyWidget().updateAll(context); return@withLock Result.success()
                }
                if (error.status >= 500 || error.status in listOf(408, 429)) return@withLock Result.retry()
                store.fail(bucket, item, error.message ?: "Review these sets before retrying.")
                DailyWidget().updateAll(context)
            } catch (_: IOException) { return@withLock Result.retry() }
        }
        DailyRefresh.now(context, userRequested = true)
        Result.success()
    }
}
