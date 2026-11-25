package io.github.pr4733k.habit

import android.content.Context
import android.content.Intent
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.glance.*
import androidx.glance.action.*
import androidx.glance.appwidget.action.*
import androidx.glance.appwidget.cornerRadius
import androidx.glance.appwidget.lazy.LazyColumn
import androidx.glance.appwidget.updateAll
import androidx.glance.layout.*
import androidx.glance.text.*
import androidx.glance.unit.ColorProvider
import org.json.JSONObject

private val textInk = ColorProvider(Color(0xFF202020))
private val textMuted = ColorProvider(Color(0xFF666666))
private val purpleInk = ColorProvider(Color(0xFF7544BF))
private val exerciseKeyParam = ActionParameters.Key<String>("exercise")
private val dateParam = ActionParameters.Key<String>("date")
private val snapshotParam = ActionParameters.Key<String>("snapshot")

internal fun exerciseSnapshot(exercise: JSONObject): String = JSONObject().put("groups", exerciseGroups(exercise))
    .put("revision", exercise.optJSONObject("saved")?.opt("revision") ?: JSONObject.NULL).toString()

class LogWidgetSets : ActionCallback {
    override suspend fun onAction(context: Context, glanceId: GlanceId, parameters: ActionParameters) {
        val connection = appConnection(context) ?: return
        val date = parameters[dateParam] ?: return; val key = parameters[exerciseKeyParam] ?: return
        val store = WorkoutStore(context); val session = store.session(connection.origin, date) ?: return
        val exercise = session.optJSONArray("exercises").objects().firstOrNull { it.optString("key") == key } ?: return
        if (date == appToday(context).toString() && exercise.optJSONObject("saved") == null &&
            store.items(connection.origin, date).none { it.optString("key") == key } &&
            parameters[snapshotParam] == exerciseSnapshot(exercise) && exercise.optJSONObject("previous")?.optBoolean("complete") == true && validGroups(exerciseGroups(exercise))) {
            store.enqueue(connection.origin, key, exercisePayload(date, session, exercise, exerciseGroups(exercise)))
        }
        DailyWidget().updateAll(context)
    }
}

class FinishWidgetWorkout : ActionCallback {
    override suspend fun onAction(context: Context, glanceId: GlanceId, parameters: ActionParameters) {
        val origin = appOrigin(context) ?: return
        WorkoutStore(context).setActive(origin, appToday(context).toString(), false)
        DailyWidget().updateAll(context)
    }
}

@Composable
internal fun WorkoutWidget(modifier: GlanceModifier) {
    val context = LocalContext.current
    val origin = appConnection(context)?.origin
    val date = appToday(context).toString()
    val store = WorkoutStore(context)
    val session = origin?.let { store.session(it, date) }
    val items = session?.optJSONArray("exercises").objects()
    val pending = origin?.let { store.items(it, date) }.orEmpty()
    val active = origin?.let { store.active(it, date) } == true
    val remaining = items.filter { it.optJSONObject("saved") == null && pending.none { p -> p.optString("key") == it.optString("key") } }
    val current = remaining.firstOrNull()
    val openWorkout = Intent(context, MainActivity::class.java).putExtra("workout", true)
    Column(modifier.fillMaxWidth()) {
        Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(if (active) "Workout" else "Today’s workout", GlanceModifier.defaultWeight(), TextStyle(color = textInk, fontSize = 17.sp, fontWeight = FontWeight.Bold), maxLines = 1)
            if (items.isNotEmpty()) Text("${items.count { it.optJSONObject("saved") != null }}/${items.size}", style = TextStyle(color = textMuted, fontSize = 12.sp))
        }
        if (pending.isNotEmpty()) {
            Spacer(GlanceModifier.height(5.dp))
            val attention = pending.any { it.optString("status") == "attention" }
            Text(if (attention) "Sets need review · Tap exercise" else "${pending.size} waiting for connection", style = TextStyle(color = purpleInk, fontSize = 11.sp), maxLines = 1)
        }
        Spacer(GlanceModifier.height(8.dp))
        if (session == null || items.isEmpty()) {
            Text(when (session?.stringOrNull("kind")) { "rest" -> "Rest day"; "run" -> "Run planned"; else -> "Your plan and exercises will appear here." }, style = TextStyle(color = textMuted, fontSize = 13.sp), maxLines = 2)
            Spacer(GlanceModifier.defaultWeight())
            WidgetButton("Open workout →", actionStartActivity(openWorkout))
        } else {
            if (active && current != null) {
                Column(GlanceModifier.fillMaxWidth().background(Color(0xFFF6F2FC)).cornerRadius(12.dp).padding(10.dp)) {
                    Text(current.getString("name"), style = TextStyle(color = textInk, fontSize = 15.sp, fontWeight = FontWeight.Medium), maxLines = 2)
                    val groups = exerciseGroups(current)
                    groups.objects().take(3).forEach { Text(groupText(it), style = TextStyle(color = textMuted, fontSize = 12.sp), maxLines = 1) }
                    val source = current.optJSONObject("previous")?.stringOrNull("date")
                    if (source != null) Text("Last logged · $source", style = TextStyle(color = textMuted, fontSize = 10.sp), maxLines = 1)
                    current.stringOrNull("progression")?.let { Text(it, style = TextStyle(color = purpleInk, fontSize = 11.sp), maxLines = 2) }
                    Spacer(GlanceModifier.height(8.dp))
                    Row(GlanceModifier.fillMaxWidth()) {
                        if (validGroups(groups) && groups.length() <= 3 && current.optJSONObject("previous")?.optBoolean("complete") == true) {
                            WidgetButton("Log these sets", actionRunCallback<LogWidgetSets>(actionParametersOf(exerciseKeyParam to current.getString("key"), dateParam to date, snapshotParam to exerciseSnapshot(current))), GlanceModifier.defaultWeight())
                            Spacer(GlanceModifier.width(5.dp))
                        }
                        WidgetButton("Edit sets", actionStartActivity(editorIntent(context, date, current.getString("key"))), GlanceModifier.defaultWeight())
                    }
                }
                Spacer(GlanceModifier.height(7.dp))
            }
            LazyColumn(GlanceModifier.defaultWeight().fillMaxWidth()) {
                items(items.size) { index ->
                    val exercise = items[index]; val key = exercise.getString("key")
                    val queue = pending.firstOrNull { it.optString("key") == key }
                    val state = when { queue?.optString("status") == "attention" -> "Review"; queue != null -> "Sending…"; exercise.optJSONObject("saved") != null -> "✓"; else -> "" }
                    Row(GlanceModifier.fillMaxWidth().padding(vertical = 9.dp).clickable(actionStartActivity(editorIntent(context, date, key))), verticalAlignment = Alignment.CenterVertically) {
                        Text(exercise.getString("name"), GlanceModifier.defaultWeight(), TextStyle(color = if (state == "✓") textMuted else textInk, fontSize = 12.sp), maxLines = 1)
                        Text(state, style = TextStyle(color = purpleInk, fontSize = 11.sp), maxLines = 1)
                    }
                }
            }
            Spacer(GlanceModifier.height(6.dp))
            if (active) WidgetButton("Finish session", actionRunCallback<FinishWidgetWorkout>())
            else WidgetButton(if (items.any { it.optJSONObject("saved") != null }) "Resume workout →" else "Start workout →", actionStartActivity(openWorkout.putExtra("start", true)))
        }
    }
}

private fun editorIntent(context: Context, date: String, key: String) = Intent(context, ExerciseLogActivity::class.java).putExtra("date", date).putExtra("exercise_key", key)

@Composable
private fun WidgetButton(label: String, action: Action, modifier: GlanceModifier = GlanceModifier.fillMaxWidth()) {
    Box(modifier.height(36.dp).background(Color(0xFFEBE4F7)).cornerRadius(9.dp).clickable(action), contentAlignment = Alignment.Center) {
        Text(label, style = TextStyle(color = purpleInk, fontSize = 12.sp, fontWeight = FontWeight.Medium), maxLines = 1)
    }
}
