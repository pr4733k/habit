package io.github.pr4733k.habit

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.time.LocalDate

class DailySummaryTest {
    private val today = LocalDate.parse("2024-04-12")
    private val month = JSONObject(
        """
        {
          "entries": [
            {"date":"2024-04-11","parsed":{"meals":[{"name":"Yesterday","nutrition":{"protein":999}}]}},
            {"date":"2024-04-12","parsed":{
              "meals":[{"name":"Sample breakfast","nutrition":{"protein":17,"carbs":39,"fat":9}}],
              "water":[{"mode":"total","ml":2200}]
            }},
            {"date":"2024-04-12","parsed":{
              "alcohol":[{"name":"Sample drink","nutrition":{"protein":0.5,"carbs":12.2,"fat":0}}],
              "workouts":[{"name":"Sample run"}],
              "water":[{"mode":"total","ml":1600},{"mode":"add","ml":150}]
            }}
          ],
          "goalTargets":[
            {"effectiveDate":"2024-04-06","goals":{"protein":110,"carbs":215,"fat":65,"water_ml":1750}},
            {"effectiveDate":"2024-05-01","goals":{"protein":105,"carbs":220,"fat":70,"water_ml":2250}}
          ]
        }
        """.trimIndent(),
    )

    @Test fun onlyTodaysEntriesAndHistoricalGoalsAreUsed() {
        val day = DailySummary.fromMonth(month, today)
        assertEquals(17.5, day.macros.getValue("protein"), 0.0001)
        assertEquals(51.2, day.macros.getValue("carbs"), 0.0001)
        assertEquals(9.0, day.macros.getValue("fat"), 0.0001)
        assertEquals(1750, day.waterMl)
        assertEquals(110.0, day.goals.getValue("protein"), 0.0001)
        assertEquals(listOf("Sample run", "Sample drink"), day.latest)
        assertEquals(day, DailySummary.fromCache(day.toJson()))
    }

    @Test fun unloggedWaterAndMacrosStayMissing() {
        val day = DailySummary.fromMonth(month, today.plusDays(1))
        assertEquals(emptyMap<String, Double>(), day.macros)
        assertNull(day.waterMl)
        assertNull(DailySummary.fromCache(day.toJson()).waterMl)
    }
}
