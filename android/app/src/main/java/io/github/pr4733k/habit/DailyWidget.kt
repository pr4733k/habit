package io.github.pr4733k.habit

import android.appwidget.AppWidgetManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.RectF
import android.graphics.Typeface
import android.net.Uri
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.Image
import androidx.glance.ImageProvider
import androidx.glance.LocalContext
import androidx.glance.LocalSize
import androidx.glance.action.ActionParameters
import androidx.glance.action.clickable
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver
import androidx.glance.appwidget.LinearProgressIndicator
import androidx.glance.appwidget.SizeMode
import androidx.glance.appwidget.action.ActionCallback
import androidx.glance.appwidget.action.actionRunCallback
import androidx.glance.appwidget.action.actionStartActivity
import androidx.glance.appwidget.cornerRadius
import androidx.glance.appwidget.provideContent
import androidx.glance.background
import androidx.glance.layout.Alignment
import androidx.glance.layout.Box
import androidx.glance.layout.Column
import androidx.glance.layout.Row
import androidx.glance.layout.Spacer
import androidx.glance.layout.fillMaxSize
import androidx.glance.layout.fillMaxWidth
import androidx.glance.layout.height
import androidx.glance.layout.padding
import androidx.glance.layout.size
import androidx.glance.text.FontWeight
import androidx.glance.text.Text
import androidx.glance.text.TextStyle
import androidx.glance.unit.ColorProvider
import java.text.NumberFormat
import java.time.LocalDate
import java.util.Locale

class DailyWidgetReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = DailyWidget()

    override fun onReceive(context: Context, intent: Intent) {
        super.onReceive(context, intent)
        if (intent.action == Intent.ACTION_MY_PACKAGE_REPLACED) {
            val provider = ComponentName(context, DailyWidgetReceiver::class.java)
            if (AppWidgetManager.getInstance(context).getAppWidgetIds(provider).isNotEmpty()) {
                DailyRefresh.start(context)
            }
        }
    }

    override fun onEnabled(context: Context) {
        super.onEnabled(context)
        DailyRefresh.start(context)
    }

    override fun onDisabled(context: Context) {
        super.onDisabled(context)
        DailyRefresh.stop(context)
    }
}

class AddWaterAction : ActionCallback {
    override suspend fun onAction(context: Context, glanceId: GlanceId, parameters: ActionParameters) {
        DailyRefresh.addWater(context)
    }
}

class RefreshDailyWidget : ActionCallback {
    override suspend fun onAction(context: Context, glanceId: GlanceId, parameters: ActionParameters) {
        DailyRefresh.now(context, userRequested = true)
    }
}

internal class DailyWidget : GlanceAppWidget() {
    override val sizeMode = SizeMode.Exact

    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val cache = DailyCache(context)
        provideContent {
            val day by remember(cache) { cache.observe() }.collectAsState(cache.current())
            DailyWidgetContent(day)
        }
    }
}

@Composable
private fun DailyWidgetContent(day: DailyCache.State) {
    val date = day.date
    val summary = day.summary
    val compact = LocalSize.current.height < 184.dp
    val context = LocalContext.current
    val setup = Intent(context, SetupActivity::class.java)
    val dayLink = if (day.signedIn && day.origin != null) {
        Intent(Intent.ACTION_VIEW, Uri.parse("${day.origin}/daily/?date=$date"))
    } else setup
    val quickLog = if (day.signedIn) Intent(context, QuickLogActivity::class.java) else setup
    val waterAction = if (day.signedIn) actionRunCallback<AddWaterAction>() else actionStartActivity(setup)
    val pad = if (compact) 6.dp else 9.dp
    val gap = if (compact) 3.dp else 5.dp
    Column(GlanceModifier.fillMaxSize().background(Color.White).cornerRadius(20.dp).padding(pad)) {
        Row(
            GlanceModifier.fillMaxWidth().clickable(actionStartActivity(dayLink)),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            val dialSize = if (compact) 39.dp else 61.dp
            Image(ImageProvider(remember(date, summary) { rings(date, summary) }),
                "Date ${date.dayOfMonth}, macro progress", GlanceModifier.size(dialSize))
            if (summary == null) {
                Text(
                    when {
                        day.origin == null -> "Connect your Habit site"
                        !day.signedIn -> "Sign in to Habit"
                        day.failed -> "Habit is unavailable"
                        else -> "Loading today…"
                    },
                    modifier = GlanceModifier.padding(start = 8.dp),
                    style = TextStyle(color = ink, fontSize = 13.sp),
                    maxLines = 1,
                )
            } else if (compact) {
                Row(GlanceModifier.defaultWeight().padding(start = 7.dp)) {
                    macroKeys.forEachIndexed { index, key ->
                        Column(GlanceModifier.defaultWeight()) {
                            Text(macroLabels[index], style = TextStyle(color = ColorProvider(Color(ringColors[index])), fontSize = 10.sp))
                            Text(grams(summary.macros[key]), style = TextStyle(color = ink, fontSize = 12.sp, fontWeight = FontWeight.Medium), maxLines = 1)
                        }
                    }
                }
            } else {
                Column(GlanceModifier.defaultWeight().padding(start = 11.dp)) {
                    macroKeys.forEachIndexed { index, key ->
                        Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Text(macroLabels[index], modifier = GlanceModifier.defaultWeight(),
                                style = TextStyle(color = ColorProvider(Color(ringColors[index])), fontSize = 12.sp))
                            val target = summary.goals[key]?.let { " / ${formatted(it, 1)} g" }.orEmpty()
                            Text(grams(summary.macros[key]) + target, style = TextStyle(color = ink, fontSize = 13.sp, fontWeight = FontWeight.Medium), maxLines = 1)
                        }
                    }
                }
            }
        }
        Spacer(GlanceModifier.height(gap))
        val water = summary?.waterMl
        val goal = summary?.goals?.get("water_ml")?.takeIf { it > 0 }
        val waterLabel = (water?.let { "${formatted(it / 1000.0, 2)} L" } ?: "—") +
            (goal?.let { " / ${formatted(it / 1000, 2)} L" } ?: "")
        val waterHeight = if (compact) 34.dp else 40.dp
        Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Box(
                GlanceModifier.defaultWeight().height(waterHeight).cornerRadius(9.dp),
                contentAlignment = Alignment.Center,
            ) {
                LinearProgressIndicator(
                    progress = if (goal == null) 0f else ((water ?: 0) / goal).coerceIn(0.0, 1.0).toFloat(),
                    modifier = GlanceModifier.fillMaxSize(),
                    color = ColorProvider(Color(0xFFD9E8FF)),
                    backgroundColor = ColorProvider(Color(0xFFF1F5FD)),
                )
                Row(GlanceModifier.fillMaxWidth().padding(horizontal = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("Water", modifier = GlanceModifier.defaultWeight(), style = TextStyle(color = blue, fontSize = 12.sp))
                    Text(waterLabel, style = TextStyle(color = blue, fontSize = 12.sp, fontWeight = FontWeight.Medium), maxLines = 1)
                }
            }
            Box(GlanceModifier.padding(start = 4.dp).size(44.dp, waterHeight).cornerRadius(9.dp)
                .background(Color(0xFFEAF1FF)).clickable(waterAction), contentAlignment = Alignment.Center) {
                Image(ImageProvider(R.drawable.ic_plus), "Add 100 milliliters of water", GlanceModifier.size(20.dp))
            }
        }
        val pending = (summary?.pendingCount ?: 0) > 0
        val detail = when {
            day.failed && summary != null -> "Couldn’t refresh · Tap ↻"
            pending -> "Saved · Waiting for sync"
            !compact -> summary?.latest?.joinToString(" · ")?.takeIf { it.isNotEmpty() }
            else -> null
        }
        if (detail != null && (!compact || LocalSize.current.height >= 141.dp)) {
            Spacer(GlanceModifier.height(gap))
            Text(detail, modifier = GlanceModifier.fillMaxWidth().clickable(actionStartActivity(dayLink)),
                style = TextStyle(color = muted, fontSize = 11.sp), maxLines = 1)
        }
        Spacer(GlanceModifier.defaultWeight())
        Spacer(GlanceModifier.height(gap))
        Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            val buttonHeight = if (compact) 30.dp else 36.dp
            Box(GlanceModifier.defaultWeight().height(buttonHeight).cornerRadius(9.dp)
                .background(Color(0xFFEAF1FF)).clickable(actionStartActivity(quickLog)), contentAlignment = Alignment.Center) {
                Text(if (day.signedIn) "Add a note" else "Connect Habit",
                    style = TextStyle(color = blue, fontSize = 12.sp, fontWeight = FontWeight.Medium))
            }
            Box(GlanceModifier.padding(start = 4.dp).size(44.dp, buttonHeight).cornerRadius(9.dp)
                .background(Color(0xFFF1F5FD)).clickable(actionRunCallback<RefreshDailyWidget>()), contentAlignment = Alignment.Center) {
                Image(ImageProvider(R.drawable.ic_refresh), "Refresh today’s logs", GlanceModifier.size(18.dp))
            }
        }
    }
}

private val macroLabels = listOf("Protein", "Carbs", "Fat")
private val blue = ColorProvider(Color(0xFF2454B0))

private val ink = ColorProvider(Color(0xFF202020))
private val muted = ColorProvider(Color(0xFF595959))
private val ringColors = intArrayOf(0xff8550cf.toInt(), 0xffce7423.toInt(), 0xff168367.toInt())

private fun grams(value: Double?) = value?.let { "${formatted(it, 1)} g" } ?: "—"
private fun formatted(value: Double, digits: Int): String =
    NumberFormat.getNumberInstance(Locale.ENGLISH).apply { maximumFractionDigits = digits }.format(value)

private fun rings(date: LocalDate, summary: DailySummary?): Bitmap {
    val size = 156
    val center = size / 2f
    val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
    val canvas = Canvas(bitmap)
    val paint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        style = Paint.Style.STROKE
        strokeWidth = 8f
        strokeCap = Paint.Cap.ROUND
    }
    for ((index, key) in macroKeys.withIndex()) {
        val inset = 7f + index * 12f
        val circle = RectF(inset, inset, size - inset, size - inset)
        val color = ringColors[index]
        paint.color = (color and 0x00ffffff) or 0x26000000
        canvas.drawOval(circle, paint)
        val goal = summary?.goals?.get(key)?.takeIf { it > 0 }
        val value = summary?.macros?.get(key)
        if (goal != null && value != null && value > 0) {
            paint.color = color
            val sweep = (value / goal).coerceAtMost(1.0).toFloat() * 360f
            canvas.drawArc(circle, -90f, sweep, false, paint)
        }
    }
    paint.apply {
        style = Paint.Style.FILL
        color = 0xff202020.toInt()
        textAlign = Paint.Align.CENTER
        textSize = 43f
        typeface = Typeface.create("sans-serif-medium", Typeface.NORMAL)
    }
    canvas.drawText(date.dayOfMonth.toString(), center, center - (paint.ascent() + paint.descent()) / 2, paint)
    return bitmap
}
