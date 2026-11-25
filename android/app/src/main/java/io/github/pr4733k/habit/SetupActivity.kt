package io.github.pr4733k.habit

import android.app.Activity
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.text.InputType
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import androidx.glance.appwidget.updateAll
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.IOException
import java.security.GeneralSecurityException
import java.time.LocalDate
import kotlin.math.roundToInt

class SetupActivity : Activity() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val session = LogSession(this)
        val form = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(18), dp(16), dp(18), dp(12))
            background = GradientDrawable().apply {
                cornerRadius = dp(18).toFloat()
                setColor(Color.WHITE)
            }
        }
        form.addView(TextView(this).apply {
            text = "Connect Habit"
            textSize = 18f
            typeface = Typeface.DEFAULT_BOLD
        })
        val address = EditText(this).apply {
            hint = "https://your-habit.example.com"
            contentDescription = "Habit site address"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI
            setSingleLine()
            id = android.view.View.generateViewId()
            setText(session.connection()?.origin.orEmpty())
        }
        form.addView(address, -1, -2)
        val password = EditText(this).apply {
            hint = "Habit password"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            importantForAutofill = View.IMPORTANT_FOR_AUTOFILL_NO
            isSaveEnabled = false
        }
        form.addView(password, -1, -2)
        val status = TextView(this).apply {
            accessibilityLiveRegion = View.ACCESSIBILITY_LIVE_REGION_POLITE
            setPadding(0, dp(6), 0, 0)
            text = if (session.isSignedIn()) "Connected. Reconnect here to change sites." else "Use the HTTPS address of your Habit install."
        }
        form.addView(status, -1, -2)
        val connect = Button(this).apply { text = "Connect"; isAllCaps = false }
        form.addView(connect, -1, -2)
        connect.setOnClickListener {
            val origin = runCatching { habitOrigin(address.text.toString()) }.getOrElse {
                address.error = "Enter your Habit site’s HTTPS address"
                return@setOnClickListener
            }
            val enteredPassword = password.text.toString()
            if (enteredPassword.isBlank()) {
                password.error = "Enter your Habit password"
                return@setOnClickListener
            }
            address.isEnabled = false
            password.isEnabled = false
            connect.isEnabled = false
            status.text = "Connecting…"
            scope.launch {
                try {
                    withContext(Dispatchers.IO) {
                        val api = DailyApi(origin)
                        val zone = api.timeZone()
                        val cookie = api.signIn(enteredPassword)
                        val summary = api.today(LocalDate.now(zone), cookie)
                        val connection = session.connect(origin, zone, cookie)
                        DailyCache(this@SetupActivity).save(connection, summary)
                    }
                    password.text.clear()
                    status.text = "Connected. Add Habit from your home screen’s widget picker."
                    DailyWidget().updateAll(applicationContext)
                    DailyRefresh.now(applicationContext, userRequested = true)
                    WorkoutStore.schedule(applicationContext)
                    startActivity(android.content.Intent(this@SetupActivity, MainActivity::class.java))
                    finish()
                } catch (error: CancellationException) {
                    throw error
                } catch (error: IOException) {
                    status.text = if (error is DailyHttpError) error.message else "Couldn’t connect. Check the address and try again."
                } catch (_: GeneralSecurityException) {
                    status.text = "Couldn’t store the sign-in on this device."
                } finally {
                    address.isEnabled = true
                    password.isEnabled = true
                    connect.isEnabled = true
                }
            }
        }
        setContentView(ScrollView(this).apply { addView(form) })
        window.setLayout((resources.displayMetrics.widthPixels - dp(32)).coerceAtMost(dp(440)), -2)
        form.isFocusableInTouchMode = true
        form.requestFocus()
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    private fun dp(value: Int) = (value * resources.displayMetrics.density).roundToInt()
}
