package pl.zrodloslowa.app.referral

import android.app.Application
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import android.webkit.CookieManager
import androidx.compose.runtime.State
import androidx.compose.runtime.mutableStateOf
import com.android.installreferrer.api.InstallReferrerClient
import com.android.installreferrer.api.InstallReferrerStateListener
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import org.json.JSONObject
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.webview.WebUrlResolver
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest

/**
 * Przechowuje wyłącznie token konkretnego zaproszenia i pseudonim instalacji.
 * Kwota nagrody nigdy nie pochodzi z aplikacji — backend czyta snapshot z zaproszenia.
 */
object ReferralInstallManager {
    private const val PREFERENCES = "app_referral_install"
    private const val TOKEN = "pending_token"
    private const val ORIGIN = "pending_origin"
    private const val REGISTRATION_NONCE = "registration_nonce"
    private const val COMPLETED = "completed"
    private val tokenPattern = Regex("^[A-Za-z0-9_-]{43}$")
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val _pendingRegistrationNonce = mutableStateOf<String?>(null)
    private val completionInProgress = mutableSetOf<String>()
    private val registrationPreparationInProgress = mutableSetOf<String>()
    private lateinit var application: Application

    val pendingRegistrationNonce: State<String?> get() = _pendingRegistrationNonce

    fun init(application: Application) {
        this.application = application
        val preferences = preferences()
        val token = preferences.getString(TOKEN, null)
        if (token != null && tokenPattern.matches(token) && !preferences.getBoolean(COMPLETED, false)) {
            prepareRegistration(preferences.getString(ORIGIN, null) ?: defaultOrigin(), token)
        }
        capturePlayInstallReferrer()
    }

    fun captureIntent(intent: Intent?): Boolean {
        val uri = intent?.data ?: return false
        val token = tokenFromDeepLink(uri) ?: return false
        val origin = originFromDeepLink(uri) ?: defaultOrigin()
        remember(token, origin)
        prepareRegistration(origin, token)
        return true
    }

    fun markRegistrationOpened() {
        preferences().edit().remove(REGISTRATION_NONCE).apply()
        _pendingRegistrationNonce.value = null
    }

    /** Wywoływane dopiero po poprawnej odpowiedzi GET /api/mobile/session. */
    fun onAuthenticatedSession(origin: String, cookie: String?) {
        if (!::application.isInitialized) return
        val preferences = preferences()
        if (preferences.getBoolean(COMPLETED, false)) return
        val token = preferences.getString(TOKEN, null) ?: return
        if (!tokenPattern.matches(token)) return
        // Domknięcie wykonujemy na tej samej domenie, która właśnie potwierdziła
        // sesję. Domeny językowe współdzielą dane, ale nie współdzielą cookies.
        val referralOrigin = origin.trimEnd('/')
        synchronized(completionInProgress) {
            if (!completionInProgress.add(referralOrigin)) return
        }
        scope.launch {
            try {
                if (post(referralOrigin, "api/mobile/referral/install", token, null) == null) return@launch
                val currentCookie = CookieManager.getInstance().getCookie(referralOrigin) ?: cookie
                if (post(referralOrigin, "api/mobile/referral/first-session", token, currentCookie) != null) {
                    preferences.edit().putBoolean(COMPLETED, true).remove(TOKEN).remove(ORIGIN).remove(REGISTRATION_NONCE).apply()
                    _pendingRegistrationNonce.value = null
                }
            } finally {
                synchronized(completionInProgress) { completionInProgress.remove(referralOrigin) }
            }
        }
    }

    internal fun tokenFromDeepLink(uri: Uri): String? {
        return referralTokenFromUrl(uri.toString())
    }

    private fun remember(token: String, origin: String) {
        preferences().edit()
            .putString(TOKEN, token)
            .putString(ORIGIN, origin.trimEnd('/'))
            .remove(REGISTRATION_NONCE)
            .putBoolean(COMPLETED, false)
            .apply()
        _pendingRegistrationNonce.value = null
    }

    private fun prepareRegistration(origin: String, token: String) {
        val normalizedOrigin = origin.trimEnd('/')
        val key = "$normalizedOrigin|$token"
        synchronized(registrationPreparationInProgress) {
            if (!registrationPreparationInProgress.add(key)) return
        }
        scope.launch {
            try {
                if (post(normalizedOrigin, "api/mobile/referral/install", token, null) == null) return@launch
                val response = post(normalizedOrigin, "api/mobile/referral/registration-nonce", token, null)
                    ?: return@launch
                val nonce = response.optString("registration_nonce")
                    .takeIf(tokenPattern::matches)
                    ?: return@launch
                preferences().edit().putString(REGISTRATION_NONCE, nonce).apply()
                _pendingRegistrationNonce.value = nonce
            } finally {
                synchronized(registrationPreparationInProgress) { registrationPreparationInProgress.remove(key) }
            }
        }
    }

    private fun post(origin: String, path: String, token: String, cookie: String?): JSONObject? {
        var connection: HttpURLConnection? = null
        return try {
            connection = URL(origin.trimEnd('/') + "/" + path).openConnection() as HttpURLConnection
            connection.instanceFollowRedirects = false
            connection.connectTimeout = 8_000
            connection.readTimeout = 8_000
            connection.requestMethod = "POST"
            connection.doOutput = true
            connection.setRequestProperty("Accept", "application/json")
            connection.setRequestProperty("Content-Type", "application/json; charset=UTF-8")
            connection.setRequestProperty("X-Requested-With", "ZrodloSlowaMobile")
            if (!cookie.isNullOrBlank()) connection.setRequestProperty("Cookie", cookie)
            val payload = JSONObject()
                .put("token", token)
                .put("device_id", installationPseudonym())
                .toString()
                .toByteArray(Charsets.UTF_8)
            connection.outputStream.use { it.write(payload) }
            val code = connection.responseCode
            if (code !in 200..299) return null
            val response = connection.inputStream.bufferedReader(Charsets.UTF_8).use { it.readText() }
            JSONObject(response).takeIf { it.optBoolean("ok", false) }
        } catch (_: Exception) {
            null
        } finally {
            connection?.disconnect()
        }
    }

    private fun capturePlayInstallReferrer() {
        val client = InstallReferrerClient.newBuilder(application).build()
        client.startConnection(object : InstallReferrerStateListener {
            override fun onInstallReferrerSetupFinished(responseCode: Int) {
                try {
                    if (responseCode != InstallReferrerClient.InstallReferrerResponse.OK) return
                    val raw = client.installReferrer.installReferrer
                    val token = Uri.parse("https://referrer.invalid/?$raw")
                        .getQueryParameter("referral_token")
                        ?.takeIf(tokenPattern::matches)
                        ?: return
                    remember(token, defaultOrigin())
                    prepareRegistration(defaultOrigin(), token)
                } catch (_: Exception) {
                    // Brak referrera oznacza zwykłą instalację, nie błąd aplikacji.
                } finally {
                    client.endConnection()
                }
            }

            override fun onInstallReferrerServiceDisconnected() = Unit
        })
    }

    private fun originFromDeepLink(uri: Uri): String? {
        if (!uri.scheme.equals("https", ignoreCase = true) || uri.host !in SiteConfig.allowlistHosts) return null
        return "https://${uri.host}"
    }

    private fun defaultOrigin(): String = WebUrlResolver.baseUrl(SiteConfig.defaultSite).trimEnd('/')

    private fun installationPseudonym(): String {
        val androidId = Settings.Secure.getString(application.contentResolver, Settings.Secure.ANDROID_ID)
            ?: "unavailable-${application.packageName}"
        return MessageDigest.getInstance("SHA-256")
            .digest("zrodlo-referral|$androidId".toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }
    }

    private fun preferences() = application.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
}

internal fun referralTokenFromUrl(raw: String): String? {
    val uri = runCatching { java.net.URI(raw) }.getOrNull() ?: return null
    val segments = uri.path.orEmpty().trim('/').split('/').filter(String::isNotEmpty)
    val candidate = when {
        uri.scheme.equals("zrodloslowa", ignoreCase = true) && uri.host == "referral" && segments.size == 1 -> segments[0]
        uri.scheme.equals("https", ignoreCase = true)
            && uri.host in SiteConfig.allowlistHosts
            && segments.size == 3
            && segments[0] == "app"
            && segments[1] == "referral" -> segments[2]
        else -> null
    }
    return candidate?.takeIf { it.matches(Regex("^[A-Za-z0-9_-]{43}$")) }
}
