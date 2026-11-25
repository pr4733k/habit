package io.github.pr4733k.habit

import android.app.Activity
import android.content.Intent
import android.graphics.Color
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.webkit.*
import android.widget.*
import androidx.webkit.WebViewCompat
import androidx.webkit.WebViewFeature
import androidx.glance.appwidget.updateAll
import kotlinx.coroutines.*
import org.json.JSONObject
import java.net.URI
import java.time.LocalDate
import java.io.File
import android.provider.MediaStore
import androidx.core.content.FileProvider

class MainActivity : Activity() {
    private lateinit var web: WebView
    private lateinit var status: TextView
    private lateinit var origin: String
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    private var first = true
    private var lastCookie: String? = null
    private lateinit var unlockButton: Button
    private var photoCallback: ValueCallback<Array<Uri>>? = null
    private var captureUri: Uri? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        origin = appOrigin(this) ?: run { startActivity(setupIntent(this)); finish(); return }
        val body = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setBackgroundColor(Color.WHITE) }
        body.setOnApplyWindowInsetsListener { view, insets ->
            view.setPadding(insets.systemWindowInsetLeft, insets.systemWindowInsetTop, insets.systemWindowInsetRight, insets.systemWindowInsetBottom); insets
        }
        status = TextView(this).apply { text = "Opening Daily…"; setPadding(24, 12, 24, 12); setTextColor(Color.DKGRAY)
            setOnClickListener { web.reload() } }
        body.addView(status)
        web = WebView(this).apply {
            setBackgroundColor(Color.WHITE)
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.allowFileAccess = false
            settings.allowContentAccess = false
            settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            settings.setSupportMultipleWindows(false)
            CookieManager.getInstance().setAcceptThirdPartyCookies(this, false)
            webChromeClient = object : WebChromeClient() {
                override fun onShowFileChooser(view: WebView, callback: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
                    if (!trusted(view.url ?: "")) return false
                    photoCallback?.onReceiveValue(null); photoCallback = callback
                    val chooser = if (params.isCaptureEnabled) {
                        val directory = File(cacheDir, "label-captures").apply { mkdirs() }
                        captureUri = FileProvider.getUriForFile(this@MainActivity, "$packageName.label-files", File.createTempFile("label-", ".jpg", directory))
                        Intent(MediaStore.ACTION_IMAGE_CAPTURE).putExtra(MediaStore.EXTRA_OUTPUT, captureUri)
                            .addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
                            .apply { clipData = android.content.ClipData.newRawUri("Nutrition label", captureUri) }
                    } else {
                        captureUri = null
                        Intent(Intent.ACTION_OPEN_DOCUMENT).setType("image/*").addCategory(Intent.CATEGORY_OPENABLE)
                    }
                    try { startActivityForResult(chooser, 410) }
                    catch (_: android.content.ActivityNotFoundException) { photoCallback?.onReceiveValue(null); photoCallback = null; Toast.makeText(this@MainActivity, "No photo app is available", Toast.LENGTH_SHORT).show() }
                    return true
                }
            }
            webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                    if (!request.isForMainFrame) return !trusted(request.url.toString())
                    if (trusted(request.url.toString())) return false
                    if (request.url.scheme in listOf("https", "http")) runCatching { startActivity(Intent(Intent.ACTION_VIEW, request.url)) }
                    return true
                }
                override fun onPageFinished(view: WebView, url: String) {
                    if (!trusted(url)) return
                    status.visibility = View.GONE
                    captureCookie()
                    view.evaluateJavascript("""
                        (() => {
                          if (!window.DailyNative) return;
                          if (!window.__dailyNativeEvents) {
                            window.__dailyNativeEvents = true;
                            window.addEventListener('daily:updated', () => DailyNative.postMessage('updated'));
                            window.addEventListener('daily:workout-session', e => DailyNative.postMessage(JSON.stringify({active:!!e.detail?.active})));
                          }
                          DailyNative.postMessage(JSON.stringify({active:!!document.querySelector('.habit-workout-session')}));
                        })();
                    """.trimIndent(), null)
                }
                override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                    if (request.isForMainFrame) { status.text = "Couldn’t connect · Tap to retry"; status.visibility = View.VISIBLE }
                }
            }
        }
        if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            WebViewCompat.addWebMessageListener(web, "DailyNative", setOf(origin)) { _, message, sourceOrigin, isMainFrame, _ ->
                if (!isMainFrame || !trusted(sourceOrigin.toString())) return@addWebMessageListener
                if (message.data == "updated") { DailyRefresh.afterSave(this, true); return@addWebMessageListener }
                val json = runCatching { JSONObject(message.data ?: "") }.getOrNull() ?: return@addWebMessageListener
                if (json.has("active")) scope.launch {
                    withContext(Dispatchers.IO) { WorkoutStore(this@MainActivity).setActive(origin, appToday(this@MainActivity).toString(), json.optBoolean("active")) }
                    refreshWidgets()
                }
            }
        }
        body.addView(web, LinearLayout.LayoutParams(-1, 0, 1f))
        val actions = LinearLayout(this)
        actions.addView(Button(this).apply { text = "Add a note"; isAllCaps = false; setOnClickListener { startActivity(Intent(this@MainActivity, QuickLogActivity::class.java)) } }, LinearLayout.LayoutParams(0, -2, 1f))
        unlockButton = Button(this).apply { text = "Unlock logging"; isAllCaps = false; setOnClickListener { startActivity(setupIntent(this@MainActivity)) } }
        actions.addView(unlockButton, LinearLayout.LayoutParams(0, -2, 1f))
        body.addView(actions); setContentView(body)
        seedCookie()
        if (savedInstanceState == null || web.restoreState(savedInstanceState) == null) web.loadUrl(destination(intent))
        DailyRefresh.start(this)
    }
    private suspend fun refreshWidgets() { DailyWidget().updateAll(this) }
    private fun trusted(url: String): Boolean = runCatching { val uri = URI(url); val base = URI(origin)
        uri.scheme == "https" && uri.host == base.host && uri.port == base.port && uri.rawUserInfo == null }.getOrDefault(false)
    private fun destination(intent: Intent): String {
        val date = intent.getStringExtra("date")?.let { runCatching { LocalDate.parse(it).toString() }.getOrNull() }
        if (intent.getBooleanExtra("log", false)) return "$origin/daily/log?date=${date ?: appToday(this)}&food=${if (intent.getStringExtra("food") == "repeat") "repeat" else "label"}"
        return "$origin/daily/" + if (intent.getBooleanExtra("workout", false)) "?tab=workout" + if (intent.getBooleanExtra("start", false)) "&start=1" else ""
            else date?.let { "?date=$it" }.orEmpty()
    }
    private fun seedCookie() { appConnection(this)?.takeIf { it.origin == origin }?.let { CookieManager.getInstance().setCookie("$origin/daily/", "$sessionCookieName=${it.cookie}; Path=/daily; Secure; HttpOnly; SameSite=Strict"); CookieManager.getInstance().flush() } }
    private fun captureCookie() { if (appOrigin(this) != origin) return; CookieManager.getInstance().getCookie("$origin/daily/")?.split(';')?.firstOrNull { it.trim().startsWith("$sessionCookieName=") }
        ?.trim()?.substringAfter('=')?.takeIf(sessionValuePattern::matches)?.let { appCookie(this, it) } }
    override fun onResume() {
        super.onResume(); if (!::web.isInitialized) return
        if (appOrigin(this) != origin) { recreate(); return }
        val nextCookie = appConnection(this)?.cookie
        unlockButton.visibility = if (nextCookie == null || canSwitchSite()) View.VISIBLE else View.GONE
        unlockButton.text = if (nextCookie == null) "Unlock logging" else "Connection"
        seedCookie(); WorkoutStore.schedule(this)
        if (!first) {
            web.evaluateJavascript("window.dispatchEvent(new Event('daily:updated'));window.dispatchEvent(new Event('daily:refresh-session'));", null)
            // A first native sign-in needs to mount the authenticated controls.
            if (lastCookie == null && nextCookie != null) web.reload()
            DailyRefresh.now(this, true)
        }
        first = false
        lastCookie = nextCookie
    }
    override fun onNewIntent(intent: Intent) { super.onNewIntent(intent); setIntent(intent); seedCookie(); web.loadUrl(destination(intent)) }
    override fun onSaveInstanceState(outState: Bundle) { web.saveState(outState); super.onSaveInstanceState(outState) }
    @Deprecated("Activity back compatibility") override fun onBackPressed() { if (web.canGoBack()) web.goBack() else super.onBackPressed() }
    @Deprecated("Activity result compatibility") override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != 410) return
        val selected = if (resultCode == RESULT_OK) captureUri ?: data?.data else null
        photoCallback?.onReceiveValue(selected?.takeIf { it.scheme == "content" }?.let { arrayOf(it) })
        photoCallback = null; captureUri = null
    }
    override fun onDestroy() { scope.cancel(); photoCallback?.onReceiveValue(null); if (::web.isInitialized) web.destroy(); File(cacheDir,"label-captures").listFiles()?.forEach { it.delete() }; super.onDestroy() }
}
