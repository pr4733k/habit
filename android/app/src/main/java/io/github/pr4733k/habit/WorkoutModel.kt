package io.github.pr4733k.habit

import org.json.JSONArray
import org.json.JSONObject

internal fun JSONArray?.objects(): List<JSONObject> = this?.let { a -> (0 until a.length()).mapNotNull(a::optJSONObject) }.orEmpty()
internal fun JSONObject.objectOrNull(key: String) = optJSONObject(key)
internal fun JSONObject.stringOrNull(key: String): String? = if (isNull(key)) null else optString(key).takeIf { it.isNotBlank() }
internal fun exerciseGroups(exercise: JSONObject): JSONArray = exercise.optJSONObject("saved")?.optJSONArray("groups")
    ?: exercise.optJSONObject("previous")?.optJSONArray("groups") ?: JSONArray()

internal fun validGroups(groups: JSONArray): Boolean = groups.length() in 1..20 && groups.objects().size == groups.length() &&
    groups.objects().all { g ->
        val basis = g.stringOrNull("weight_basis")
        val reps = g.optDouble("reps", Double.NaN)
        val sets = g.optDouble("sets", Double.NaN)
        val weight = g.optDouble("weight", Double.NaN)
        reps.isFinite() && reps in 1.0..1000.0 && reps % 1 == 0.0 && sets in 1.0..30.0 && sets % 1 == 0.0 &&
            (basis == "bodyweight" && g.isNull("weight") && g.isNull("weight_unit") ||
                basis in listOf("total", "per_dumbbell", "per_side", "assistance", "added_weight") &&
                weight.isFinite() && weight in 0.0..2000.0 && g.optString("weight_unit") in listOf("kg", "lb"))
    } && groups.objects().sumOf { it.optInt("sets") } <= 100

internal fun groupText(g: JSONObject): String {
    val basis = g.stringOrNull("weight_basis")
    val load = if (basis == "bodyweight") "Bodyweight" else listOf(
        g.optDouble("weight", Double.NaN).takeIf { it.isFinite() }?.let { if (it % 1 == 0.0) it.toInt().toString() else it.toString() } ?: "—",
        g.stringOrNull("weight_unit") ?: "", when (basis) {
            "per_dumbbell" -> "each"; "per_side" -> "per side"; "assistance" -> "assist"; "added_weight" -> "added"; else -> ""
        }).filter(String::isNotEmpty).joinToString(" ")
    return "$load × ${g.stringOrNull("reps") ?: "—"} reps × ${g.optInt("sets", 1)} sets"
}

internal fun exercisePayload(date: String, session: JSONObject, exercise: JSONObject, groups: JSONArray): JSONObject = JSONObject()
    .put("date", date).put("name", exercise.getString("name")).put("venue", session.opt("venue") ?: JSONObject.NULL)
    .put("revision", exercise.optJSONObject("saved")?.opt("revision") ?: JSONObject.NULL).put("groups", groups)

internal fun confirmExercise(response: JSONObject, date: String, key: String): JSONObject {
    val entry = response.optJSONObject("entry")
    require(entry != null && entry.optString("date") == date && entry.optString("key") == key && entry.optInt("revision") > 0 &&
        entry.optJSONArray("groups") != null && entry.optString("id").isNotBlank()) { "Daily did not confirm this exercise." }
    return entry
}
