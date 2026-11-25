package io.github.pr4733k.habit

import android.app.Activity
import android.graphics.Color
import android.os.Bundle
import android.text.InputType
import android.view.Gravity
import android.widget.*
import androidx.glance.appwidget.updateAll
import kotlinx.coroutines.*
import org.json.JSONArray
import org.json.JSONObject
import java.time.LocalDate

class ExerciseLogActivity : Activity() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    private lateinit var connection: AppConnection
    private lateinit var body: LinearLayout
    private lateinit var status: TextView
    private lateinit var date: String
    private lateinit var key: String
    private var session: JSONObject? = null
    private var exercise: JSONObject? = null
    private var restoring = false
    private val rows = mutableListOf<SetRow>()
    private data class SetRow(val view: LinearLayout, val weight: EditText, val reps: EditText, val sets: EditText, val basis: Spinner)
    private val bases = listOf("total", "per_dumbbell", "per_side", "bodyweight", "assistance", "added_weight")
    private val options = listOf("Choose load type", "kg total", "kg each dumbbell", "kg per side", "Bodyweight", "kg assistance", "kg added", "lb total", "lb each dumbbell", "lb per side", "lb assistance", "lb added")

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        connection = appConnection(this) ?: run { startActivity(setupIntent(this)); finish(); return }
        date = intent.getStringExtra("date")?.let { runCatching { LocalDate.parse(it).toString() }.getOrNull() } ?: appToday(this).toString()
        key = intent.getStringExtra("exercise_key") ?: run { finish(); return }
        body = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(20), dp(20), dp(20), dp(12)); setBackgroundColor(Color.WHITE) }
        status = TextView(this).apply { text = "Loading sets…"; setTextColor(Color.DKGRAY); setPadding(0, dp(6), 0, dp(8)) }
        body.addView(status)
        setContentView(ScrollView(this).apply { addView(body) })
        window.setLayout((resources.displayMetrics.widthPixels - dp(24)).coerceAtMost(dp(480)), -2)
        scope.launch {
            val store = WorkoutStore(this@ExerciseLogActivity)
            val fresh = try { withContext(Dispatchers.IO) { DailyApi(connection.origin).workoutSession(LocalDate.parse(date), connection.cookie) }.also { store.saveSession(connection.origin, it) } }
            catch (_: java.io.IOException) { store.session(connection.origin, date) }
            if (fresh == null) { status.text = "Couldn’t load this workout. Open Daily and try again."; return@launch }
            session = fresh; exercise = fresh.optJSONArray("exercises").objects().firstOrNull { it.optString("key") == key }
            if (exercise == null) { status.text = "This exercise is no longer in the plan. Open Daily to choose one."; return@launch }
            val pending = store.items(connection.origin, date).firstOrNull { it.optString("key") == key }
            val draft = savedInstanceState?.getString("groups")?.let { runCatching { JSONArray(it) }.getOrNull() }
                ?: store.draft(connection.origin, date, key)?.optJSONArray("groups") ?: pending?.optJSONObject("payload")?.optJSONArray("groups") ?: exerciseGroups(exercise!!)
            render(draft, pending?.optString("status") == "attention")
        }
    }
    private fun render(groups: JSONArray, conflict: Boolean) {
        body.removeAllViews(); rows.clear(); val item = exercise!!
        body.addView(TextView(this).apply { text = item.getString("name"); textSize = 21f; setTextColor(Color.rgb(32,32,32)); setTypeface(null, android.graphics.Typeface.BOLD) })
        val source = item.optJSONObject("previous")?.optString("date")
        body.addView(TextView(this).apply { text = if (item.optJSONObject("saved") != null) "Saved today" else source?.let { "Last logged · $it" } ?: "Enter your sets"; textSize = 13f; setPadding(0, dp(6), 0, dp(10)) })
        item.stringOrNull("progression")?.let { hint -> body.addView(TextView(this).apply { text = hint; textSize = 13f; setTextColor(Color.rgb(117,68,191)); setPadding(0,0,0,dp(12)) }) }
        body.addView(status)
        status.text = if (conflict) "Changed elsewhere or needs review. Saving will use the latest revision and replace the saved sets." else "Weight · Reps · Sets"
        if (item.optJSONObject("saved")?.optBoolean("external") == true) {
            status.text = "Already logged with another note. Edit the original note in Daily to change it."
            return
        }
        restoring = true
        (groups.objects().takeIf { it.isNotEmpty() } ?: listOf(JSONObject().put("sets", 1))).forEach(::addRow)
        restoring = false
        body.addView(Button(this).apply { text = "+ Different sets"; isAllCaps = false; setOnClickListener { addRow(JSONObject().put("sets", 1)); persistDraft() } })
        body.addView(Button(this).apply { text = if (conflict) "Replace saved sets" else "Save exercise"; isAllCaps = false; setOnClickListener { save() } })
    }
    private fun addRow(group: JSONObject) {
        val box = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(0, dp(6), 0, dp(6)) }
        val fields = LinearLayout(this)
        fun number(hint: String, field: String, decimal: Boolean = false) = EditText(this).apply {
            this.hint = hint; textSize = 17f; inputType = InputType.TYPE_CLASS_NUMBER or if (decimal) InputType.TYPE_NUMBER_FLAG_DECIMAL else 0
            setSingleLine(true); setText(group.stringOrNull(field).orEmpty()); setSelectAllOnFocus(true)
        }
        val weight = number("kg", "weight", true); val reps = number("Reps", "reps"); val sets = number("Sets", "sets")
        listOf(weight, reps, sets).forEach { fields.addView(it, LinearLayout.LayoutParams(0, dp(52), 1f)) }
        val remove = Button(this).apply { text = "−"; contentDescription = "Remove set group"; setOnClickListener { if (rows.size > 1) { rows.removeAll { it.view == box }; body.removeView(box); persistDraft() } } }
        fields.addView(remove, LinearLayout.LayoutParams(dp(48), dp(52))); box.addView(fields)
        val spinner = Spinner(this).apply { adapter = ArrayAdapter(this@ExerciseLogActivity, android.R.layout.simple_spinner_dropdown_item, options) }
        val basis = group.stringOrNull("weight_basis"); val unit = group.stringOrNull("weight_unit")
        val label = when { basis == "bodyweight" -> "Bodyweight"; basis == null || unit == null -> "Choose load type"; basis == "per_dumbbell" -> "$unit each dumbbell"; basis == "per_side" -> "$unit per side"; basis == "assistance" -> "$unit assistance"; basis == "added_weight" -> "$unit added"; else -> "$unit total" }
        spinner.setSelection(options.indexOf(label).coerceAtLeast(0)); box.addView(spinner)
        rows.add(SetRow(box, weight, reps, sets, spinner))
        val index = body.indexOfChild(status) + rows.size
        body.addView(box, index)
    }
    private fun groups(): JSONArray = JSONArray(rows.map { row ->
        val option = options[row.basis.selectedItemPosition]
        val basis = when { option == "Choose load type" -> null; option == "Bodyweight" -> "bodyweight"; option.contains("dumbbell") -> "per_dumbbell"; option.contains("side") -> "per_side"; option.contains("assistance") -> "assistance"; option.contains("added") -> "added_weight"; else -> "total" }
        JSONObject().put("weight", if (basis == "bodyweight") JSONObject.NULL else row.weight.text.toString().toDoubleOrNull() ?: JSONObject.NULL)
            .put("reps", row.reps.text.toString().toIntOrNull() ?: JSONObject.NULL).put("sets", row.sets.text.toString().toIntOrNull() ?: JSONObject.NULL)
            .put("weight_unit", if (basis == null || basis == "bodyweight") JSONObject.NULL else if (option.startsWith("lb")) "lb" else "kg").put("weight_basis", basis ?: JSONObject.NULL)
    })
    private fun persistDraft() { if (!restoring && ::connection.isInitialized && rows.isNotEmpty()) WorkoutStore(this).saveDraft(connection.origin, date, key, JSONObject().put("groups", groups())) }
    private fun save() {
        val groups = groups()
        if (!validGroups(groups)) { status.text = "Enter the weight, reps and sets for every row. Choose Bodyweight when appropriate."; return }
        try {
            WorkoutStore(this).enqueue(connection.origin, key, exercisePayload(date, session!!, exercise!!, groups))
            scope.launch { DailyWidget().updateAll(this@ExerciseLogActivity); Toast.makeText(this@ExerciseLogActivity, "Sets kept · Sending to Daily", Toast.LENGTH_SHORT).show(); finish() }
        } catch (_: Exception) { status.text = "Couldn’t keep these sets on your phone. Try Save again." }
    }
    override fun onPause() { if (!isFinishing) persistDraft(); super.onPause() }
    override fun onSaveInstanceState(outState: Bundle) { outState.putString("groups", groups().toString()); super.onSaveInstanceState(outState) }
    override fun onDestroy() { scope.cancel(); super.onDestroy() }
    private fun dp(n: Int) = (resources.displayMetrics.density * n).toInt()
}
