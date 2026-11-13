package io.github.pr4733k.habit

import android.app.Activity
import android.content.Intent
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.text.InputFilter
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.view.inputmethod.InputMethodManager
import android.widget.Button
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.glance.appwidget.updateAll
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.IOException
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlin.math.roundToInt

class QuickLogActivity : Activity() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    private lateinit var connection: HabitConnection
    private lateinit var session: LogSession
    private lateinit var date: LocalDate
    private lateinit var note: EditText
    private lateinit var password: EditText
    private lateinit var saveButton: Button
    private lateinit var status: TextView
    private var lastAttempt: Submission? = null
    private var showKeyboard = true
    private var busy = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        session = LogSession(this)
        val configured = session.connection()
        if (configured == null) {
            startActivity(Intent(this, SetupActivity::class.java))
            finish()
            return
        }
        connection = configured
        val restored = savedInstanceState?.takeIf { it.getString("connection_id") == connection.id }
        date = restored?.getString("date")?.let(LocalDate::parse) ?: connection.today()
        val previousId = restored?.getString("attempt_id")
        val previousText = restored?.getString("attempt_text")
        if (previousId != null && previousText != null) lastAttempt = Submission(date, previousText, previousId)

        val body = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(18), dp(16), dp(18), dp(10))
            background = GradientDrawable().apply {
                cornerRadius = dp(18).toFloat()
                setColor(Color.WHITE)
            }
        }
        val dateLabel = date.format(DateTimeFormatter.ofPattern("EEE, d MMM", Locale.ENGLISH))
        body.addView(TextView(this).apply {
            text = "Habit · $dateLabel"
            textSize = 16f
            typeface = Typeface.DEFAULT_BOLD
            setTextColor(Color.rgb(32, 32, 32))
        })
        note = EditText(this).apply {
            hint = "What did you log?"
            textSize = 16f
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_MULTI_LINE or InputType.TYPE_TEXT_FLAG_CAP_SENTENCES
            gravity = Gravity.TOP or Gravity.START
            minLines = 3
            maxLines = 5
            filters = arrayOf(InputFilter.LengthFilter(4000))
            setText(restored?.getString("draft").orEmpty())
            setSelection(length())
        }
        body.addView(note, matchWidth(top = 8))
        password = EditText(this).apply {
            hint = "Habit password"
            textSize = 16f
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            importantForAutofill = View.IMPORTANT_FOR_AUTOFILL_NO
            isSaveEnabled = false
            visibility = if (session.read(connection) == null) View.VISIBLE else View.GONE
        }
        body.addView(password, matchWidth(top = 4))
        status = TextView(this).apply {
            accessibilityLiveRegion = View.ACCESSIBILITY_LIVE_REGION_POLITE
            textSize = 13f
            setTextColor(Color.rgb(115, 45, 48))
            visibility = View.GONE
        }
        body.addView(status, matchWidth(top = 6))
        saveButton = Button(this).apply {
            text = "Save"
            isAllCaps = false
            setOnClickListener { save() }
        }
        body.addView(saveButton, LinearLayout.LayoutParams(-2, -2).apply { gravity = Gravity.END })

        setContentView(ScrollView(this).apply {
            isFillViewport = true
            addView(body, FrameLayout.LayoutParams(-1, -2))
        })
        window.setLayout((resources.displayMetrics.widthPixels - dp(32)).coerceAtMost(dp(440)), -2)
        window.setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_ADJUST_RESIZE or WindowManager.LayoutParams.SOFT_INPUT_STATE_ALWAYS_VISIBLE)
        note.requestFocus()
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus && showKeyboard && ::note.isInitialized) {
            showKeyboard = false
            note.post {
                getSystemService(InputMethodManager::class.java).showSoftInput(note, InputMethodManager.SHOW_IMPLICIT)
            }
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        if (::note.isInitialized) {
            outState.putString("connection_id", connection.id)
            outState.putString("date", date.toString())
            outState.putString("draft", note.text.toString())
            lastAttempt?.let {
                outState.putString("attempt_id", it.id)
                outState.putString("attempt_text", it.text)
            }
        }
        super.onSaveInstanceState(outState)
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    private fun save() {
        if (busy) return
        val text = note.text.toString().trim()
        if (text.isEmpty()) {
            note.error = "Enter a note first"
            note.requestFocus()
            return
        }
        if (session.connection() != connection) {
            showStatus("Your Habit connection changed. Reopen this note to use the new site.")
            return
        }
        val cookie = session.read(connection)
        val enteredPassword = password.text.toString()
        if (cookie == null && enteredPassword.isBlank()) {
            password.visibility = View.VISIBLE
            password.error = "Enter your log password"
            password.requestFocus()
            return
        }
        val submission = lastAttempt?.takeIf { it.text == text } ?: Submission(date, text).also { lastAttempt = it }
        showStatus(null)
        setBusy(true)
        scope.launch {
            try {
                val pending = withContext(Dispatchers.IO) {
                    val api = DailyApi(connection.origin)
                    val unlocked = cookie ?: api.signIn(enteredPassword).also { session.write(connection, it) }
                    api.save(submission, unlocked)
                }
                password.text.clear()
                DailyRefresh.afterSave(applicationContext, pending)
                Toast.makeText(this@QuickLogActivity, if (pending) "Saved · Pending" else "Saved", Toast.LENGTH_SHORT).show()
                finish()
            } catch (error: CancellationException) {
                throw error
            } catch (error: DailyHttpError) {
                if (error.status == 401) {
                    session.clear(connection)
                    DailyWidget().updateAll(applicationContext)
                    password.text.clear()
                    password.visibility = View.VISIBLE
                    password.post { password.requestFocus() }
                }
                showStatus(error.message)
            } catch (_: IOException) {
                showStatus("Couldn’t confirm the save. Tap Save to retry.")
            } catch (_: java.security.GeneralSecurityException) {
                showStatus("Couldn’t store the sign-in. Tap Save to retry.")
            } finally {
                if (!isFinishing) {
                    if (session.read(connection) != null) {
                        password.text.clear()
                        password.visibility = View.GONE
                    }
                    setBusy(false)
                }
            }
        }
    }

    private fun setBusy(value: Boolean) {
        busy = value
        note.isEnabled = !value
        password.isEnabled = !value
        saveButton.isEnabled = !value
        saveButton.text = if (value) "Saving…" else "Save"
    }

    private fun showStatus(message: String?) {
        status.text = message.orEmpty()
        status.visibility = if (message == null) View.GONE else View.VISIBLE
    }

    private fun matchWidth(top: Int) = LinearLayout.LayoutParams(-1, -2).apply { topMargin = dp(top) }
    private fun dp(value: Int) = (value * resources.displayMetrics.density).roundToInt()
}
