package io.github.pr4733k.habit

import org.json.JSONArray
import org.json.JSONObject
import java.time.LocalDate

internal val macroKeys = listOf("protein", "carbs", "fat")

internal data class DailySummary(
    val date: LocalDate,
    val macros: Map<String, Double>,
    val goals: Map<String, Double>,
    val waterMl: Int?,
    val latest: List<String>,
) {
    fun toJson() = JSONObject().apply {
        put("date", date.toString())
        put("macros", JSONObject(macros))
        put("goals", JSONObject(goals))
        put("waterMl", waterMl ?: JSONObject.NULL)
        put("latest", JSONArray(latest))
    }

    companion object {
        fun fromCache(json: JSONObject) = DailySummary(
            date = LocalDate.parse(json.getString("date")),
            macros = numbers(json.optJSONObject("macros"), macroKeys),
            goals = numbers(json.optJSONObject("goals"), macroKeys + "water_ml"),
            waterMl = json.optInt("waterMl", -1).takeIf { it >= 0 },
            latest = json.optJSONArray("latest")?.let { values ->
                (0 until values.length()).map(values::getString)
            }.orEmpty(),
        )

        fun fromMonth(month: JSONObject, date: LocalDate): DailySummary {
            val dateKey = date.toString()
            val macros = mutableMapOf<String, Double>()
            val items = mutableListOf<String>()
            var waterMl: Int? = null

            for (entry in month.optJSONArray("entries").objects()) {
                if (entry.optString("date") != dateKey) continue
                val log = entry.optJSONObject("parsed") ?: continue
                for (type in listOf("meals", "alcohol", "workouts", "study")) {
                    for (item in log.optJSONArray(type).objects()) {
                        val name = item.optString("name").trim()
                        if (name.isNotEmpty()) items.add(name)
                        if (type == "meals" || type == "alcohol") {
                            for ((key, value) in numbers(item.optJSONObject("nutrition"), macroKeys)) {
                                macros[key] = (macros[key] ?: 0.0) + value
                            }
                        }
                    }
                }
                for (water in log.optJSONArray("water").objects()) {
                    val amount = water.optInt("ml", -1)
                    if (amount < 0) continue
                    waterMl = when (water.optString("mode")) {
                        "total" -> amount
                        "add" -> (waterMl ?: 0) + amount
                        else -> waterMl
                    }
                }
            }

            val goals = month.optJSONArray("goalTargets").objects()
                .filter { it.optString("effectiveDate").let { day -> day.isNotEmpty() && day <= dateKey } }
                .maxByOrNull { it.getString("effectiveDate") }
                ?.optJSONObject("goals")

            return DailySummary(
                date, macros, numbers(goals, macroKeys + "water_ml"), waterMl,
                items.asReversed().distinct().take(2),
            )
        }

        private fun numbers(json: JSONObject?, keys: List<String>) = keys.mapNotNull { key ->
            json?.optDouble(key)?.takeIf { it.isFinite() && it >= 0 }?.let { key to it }
        }.toMap()

        private fun JSONArray?.objects(): List<JSONObject> =
            this?.let { array -> (0 until array.length()).mapNotNull(array::optJSONObject) }.orEmpty()
    }
}
