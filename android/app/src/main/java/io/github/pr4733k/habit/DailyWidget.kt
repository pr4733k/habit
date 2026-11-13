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
import androidx.glance.action.ActionParameters
import androidx.glance.action.clickable
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver
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

class RefreshDailyWidget : ActionCallback {
    override suspend fun onAction(context: Context, glanceId: GlanceId, parameters: ActionParameters) {
        DailyRefresh.now(context, userRequested = true)
    }
}

internal class DailyWidget : GlanceAppWidget() {
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
    val context = LocalContext.current
    val setup = Intent(context, SetupActivity::class.java)
    val dayLink = if (day.signedIn && day.origin != null) {
        Intent(Intent.ACTION_VIEW, Uri.parse("${day.origin}/daily/?date=$date"))
    } else setup
    val quickLog = if (day.signedIn) Intent(context, QuickLogActivity::class.java) else setup
    Column(
        GlanceModifier.fillMaxSize().background(Color(0xFFFFFFFF)).cornerRadius(18.dp).padding(9.dp),
    ) {
        Row(
            GlanceModifier.fillMaxWidth().clickable(actionStartActivity(dayLink)),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Image(ImageProvider(rings(date, summary)), "Date ${date.dayOfMonth}, macro progress", GlanceModifier.size(49.dp))
            Column(GlanceModifier.defaultWeight().padding(start = 8.dp)) {
                val primary = if (summary == null) {
                    when {
                        day.origin == null -> "Connect your Habit site"
                        !day.signedIn -> "Sign in to Habit"
                        day.failed -> "Habit is unavailable"
                        else -> "Loading today…"
                    }
                } else {
                    val totals = summary.macros
                    "P ${grams(totals["protein"])}  C ${grams(totals["carbs"])}  F ${grams(totals["fat"])}"
                }
                Text(primary, style = TextStyle(color = ink, fontSize = 12.sp, fontWeight = FontWeight.Medium), maxLines = 1)
                if (summary != null) {
                    val water = summary.waterMl?.let { liters(it.toDouble()) }
                    val target = summary.goals["water_ml"]?.let(::liters)
                    val waterLine = if (water == null) "Water —" else "Water $water${target?.let { " / $it" }.orEmpty()} L"
                    Text(waterLine, style = TextStyle(color = muted, fontSize = 12.sp), maxLines = 1)
                }
            }
        }
        if (summary != null) {
            Spacer(GlanceModifier.height(3.dp))
            Text(
                summary.latest.joinToString(" · ").ifEmpty { "No food or activity logged yet" },
                modifier = GlanceModifier.fillMaxWidth().clickable(actionStartActivity(dayLink)),
                style = TextStyle(color = muted, fontSize = 11.sp),
                maxLines = 1,
            )
        }
        Spacer(GlanceModifier.height(5.dp))
        Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(
                if (day.signedIn) "Add a note" else "Connect Habit",
                modifier = GlanceModifier.defaultWeight()
                    .background(Color(0xFFEFF4FF)).cornerRadius(8.dp)
                    .clickable(actionStartActivity(quickLog))
                    .padding(horizontal = 10.dp, vertical = 6.dp),
                style = TextStyle(color = ColorProvider(Color(0xFF2458D6)), fontSize = 12.sp, fontWeight = FontWeight.Medium),
            )
            Box(
                modifier = GlanceModifier.size(width = 40.dp, height = 32.dp)
                    .cornerRadius(8.dp).clickable(actionRunCallback<RefreshDailyWidget>()),
                contentAlignment = Alignment.Center,
            ) {
                Image(ImageProvider(R.drawable.ic_refresh), "Refresh today’s logs", GlanceModifier.size(20.dp))
            }
        }
    }
}

private val ink = ColorProvider(Color(0xFF202020))
private val muted = ColorProvider(Color(0xFF595959))
private val ringColors = intArrayOf(0xff8550cf.toInt(), 0xffce7423.toInt(), 0xff168367.toInt())

private fun grams(value: Double?) = value?.let { "${formatted(it, 1)}g" } ?: "—"
private fun liters(value: Double) = formatted(value / 1000, 2)
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
