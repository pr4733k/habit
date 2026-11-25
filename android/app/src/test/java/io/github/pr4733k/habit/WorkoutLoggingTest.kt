package io.github.pr4733k.habit

import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.time.LocalDate

class WorkoutLoggingTest {
    private fun groups() = JSONArray("""[{"weight":15,"weight_unit":"kg","weight_basis":"per_dumbbell","reps":8,"sets":2}]""")
    @Test fun unknownHistoryCannotBeSavedWithOneTap() {
        val g = groups(); assertTrue(validGroups(g))
        g.getJSONObject(0).put("reps", JSONObject.NULL); assertFalse(validGroups(g))
        g.getJSONObject(0).put("reps", 8).put("weight_basis", JSONObject.NULL); assertFalse(validGroups(g))
        g.getJSONObject(0).put("weight_basis", "per_dumbbell").put("weight_unit", JSONObject.NULL); assertFalse(validGroups(g))
    }
    @Test fun bodyweightAndDifferentLoadsRemainSeparate() {
        val g = JSONArray("""[{"weight":null,"weight_unit":null,"weight_basis":"bodyweight","reps":4,"sets":3},{"weight":5,"weight_unit":"kg","weight_basis":"added_weight","reps":3,"sets":1}]""")
        assertTrue(validGroups(g)); assertTrue(groupText(g.getJSONObject(0)).startsWith("Bodyweight"))
        assertTrue(groupText(g.getJSONObject(1)).contains("added"))
        g.getJSONObject(0).put("weight", 70); assertFalse(validGroups(g))
    }
    @Test fun prefillUsesCompletedHistoryRatherThanSuggestedLoad() {
        val item = JSONObject().put("planned", JSONObject().put("load", "100 kg"))
        assertEquals(0, exerciseGroups(item).length())
        item.put("previous", JSONObject().put("groups", groups()))
        assertEquals(15.0, exerciseGroups(item).getJSONObject(0).getDouble("weight"), 0.01)
    }
    @Test fun snapshotChangesWhenSetsOrRevisionChange() {
        val item = JSONObject().put("previous", JSONObject().put("groups", groups()))
        val first = exerciseSnapshot(item)
        item.put("saved", JSONObject().put("groups", groups()).put("revision", 2))
        assertNotEquals(first, exerciseSnapshot(item))
    }
    @Test fun malformedAndExcessiveSetsAreRejected() {
        assertFalse(validGroups(JSONArray()))
        val g = groups(); g.getJSONObject(0).put("sets", 1.5); assertFalse(validGroups(g))
        g.getJSONObject(0).put("sets", 31); assertFalse(validGroups(g))
        g.getJSONObject(0).put("sets", 2).put("weight", -5); assertFalse(validGroups(g))
    }
    @Test fun exerciseSaveKeepsRevisionAndLoadBasisAndRequiresConfirmation() {
        MockWebServer().use { server ->
            server.enqueue(MockResponse().setBody("""{"entry":{"id":"one","date":"2026-11-25","key":"dumbbell chest press","revision":3,"groups":[]}}"""))
            val payload = JSONObject().put("date", "2026-11-25").put("name", "DB chest press").put("revision",2).put("groups", groups())
            val entry = DailyApi(server.url("/").toString().trimEnd('/')).saveExercise(payload,"dumbbell chest press","private-cookie")
            assertEquals(3, entry.getInt("revision"))
            val request = server.takeRequest(); assertEquals("/daily/api.php?action=save-exercise", request.path)
            assertEquals("1", request.getHeader("X-Daily-Request")); assertEquals("daily_log_access=private-cookie", request.getHeader("Cookie"))
            val sent = JSONObject(request.body.readUtf8()); assertEquals(2, sent.getInt("revision"))
            assertEquals("per_dumbbell", sent.getJSONArray("groups").getJSONObject(0).getString("weight_basis"))
        }
    }
    @Test fun conflictIsReturnedForReviewRatherThanRetriedAsNewExercise() {
        MockWebServer().use { server ->
            server.enqueue(MockResponse().setResponseCode(409).setBody("""{"error":"Changed elsewhere"}"""))
            try { DailyApi(server.url("/").toString().trimEnd('/')).saveExercise(JSONObject().put("date","2026-11-25"),"press","cookie"); fail("Expected conflict") }
            catch(e: DailyHttpError) { assertEquals(409, e.status) }
            assertEquals(1, server.requestCount)
        }
    }
    @Test fun widgetResponsePersistsWorkoutWithoutChangingNutrition() {
        val json = JSONObject("""{"date":"2026-11-25","totals":{"protein":25},"goals":{"protein":130},"waterMl":300,"workoutSession":{"date":"2026-11-25","exercises":[]}}""")
        val restored = DailySummary.fromCache(DailySummary.fromWidget(json).toJson())
        assertEquals(25.0,restored.macros["protein"]!!,0.01); assertEquals(300,restored.waterMl)
        assertEquals("2026-11-25",restored.workoutSession!!.getString("date"))
    }
}
