package pl.zrodloslowa.app.session

import android.webkit.CookieManager
import android.webkit.WebView
import androidx.compose.runtime.State
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import pl.zrodloslowa.app.referral.ReferralInstallManager

enum class MobileSessionStatus {
    UNKNOWN,
    CHECKING,
    AUTHENTICATED,
    ANONYMOUS,
    UNAVAILABLE,
}

data class MobileSessionSnapshot(
    val status: MobileSessionStatus = MobileSessionStatus.UNKNOWN,
    val userId: Long? = null,
    val primaryRole: String? = null,
    val roles: Set<String> = emptySet(),
    val canWrite: Boolean = false,
    val walletEnabled: Boolean = false,
    val payoutEnabled: Boolean = false,
    val generation: String? = null,
    val sessionVersion: Long? = null,
    val sessionExpiresAt: Long? = null,
) {
    val authenticated: Boolean get() = status == MobileSessionStatus.AUTHENTICATED
    val storageKey: String? get() = if (authenticated && userId != null && generation != null) {
        "$userId:$generation:${sessionVersion ?: 0L}"
    } else {
        null
    }
}

/** Jeden procesowy koordynator cookies i potwierdzonego przez backend stanu sesji Mobile. */
object WebSessionManager {

    const val SESSION_ENDPOINT_PATH = "api/mobile/session"

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    private val refreshJobs = mutableMapOf<String, Job>()
    private val refreshCallbacks = mutableMapOf<String, MutableList<(Boolean) -> Unit>>()
    private val _sessions = mutableStateOf<Map<String, MobileSessionSnapshot>>(emptyMap())
    private val _logoutEpoch = mutableIntStateOf(0)

    val sessions: State<Map<String, MobileSessionSnapshot>> get() = _sessions
    val logoutEpoch: State<Int> get() = _logoutEpoch

    fun init() {
        CookieManager.getInstance().setAcceptCookie(true)
    }

    fun attach(webView: WebView) {
        val cookieManager = CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        cookieManager.setAcceptThirdPartyCookies(webView, false)
    }

    fun persist() {
        CookieManager.getInstance().flush()
    }

    fun snapshot(baseUrl: String): MobileSessionSnapshot =
        _sessions.value[sessionOrigin(baseUrl)] ?: MobileSessionSnapshot()

    fun clearSession(onCleared: () -> Unit = {}) {
        val cookieManager = CookieManager.getInstance()
        cookieManager.removeAllCookies {
            cookieManager.flush()
            markAllAnonymous()
            onCleared()
        }
    }

    fun clearAllWebViewState(webView: WebView?, onCleared: () -> Unit = {}) {
        val cookieManager = CookieManager.getInstance()
        cookieManager.removeAllCookies {
            cookieManager.flush()
            android.webkit.WebStorage.getInstance().deleteAllData()
            webView?.clearCache(true)
            webView?.clearFormData()
            webView?.clearHistory()
            markAllAnonymous()
            onCleared()
        }
    }

    fun hasAnyCookie(baseUrl: String): Boolean =
        !CookieManager.getInstance().getCookie(baseUrl).isNullOrBlank()

    /**
     * Odpytuje wyłącznie kanoniczny JSON `GET /api/mobile/session`.
     * Równoległe wywołania dla tej samej domeny są łączone w jedno żądanie,
     * a wszystkie callbacki otrzymują ten sam, najnowszy wynik.
     */
    fun verifySession(baseUrl: String, onResult: (Boolean) -> Unit) {
        val origin = sessionOrigin(baseUrl)
        refreshCallbacks.getOrPut(origin) { mutableListOf() }.add(onResult)
        if (refreshJobs[origin]?.isActive == true) return

        val previous = snapshot(origin)
        update(origin, previous.copy(status = MobileSessionStatus.CHECKING))
        val cookie = CookieManager.getInstance().getCookie(origin)
        refreshJobs[origin] = scope.launch {
            val result = withContext(Dispatchers.IO) { requestSession(origin, cookie) }
            val effective = if (result.status == MobileSessionStatus.UNAVAILABLE && previous.authenticated) {
                previous.copy(status = MobileSessionStatus.UNAVAILABLE)
            } else {
                result
            }
            update(origin, effective)
            if (effective.authenticated) {
                ReferralInstallManager.onAuthenticatedSession(origin, cookie)
            }
            val callbacks = refreshCallbacks.remove(origin).orEmpty()
            refreshJobs.remove(origin)
            callbacks.forEach { it(effective.authenticated) }
        }
    }

    private fun requestSession(origin: String, cookie: String?): MobileSessionSnapshot {
        var connection: HttpURLConnection? = null
        return try {
            connection = URL(origin.trimEnd('/') + "/" + SESSION_ENDPOINT_PATH)
                .openConnection() as HttpURLConnection
            connection.instanceFollowRedirects = false
            connection.connectTimeout = 8_000
            connection.readTimeout = 8_000
            connection.requestMethod = "GET"
            connection.setRequestProperty("Accept", "application/json")
            connection.setRequestProperty("X-Requested-With", "ZrodloSlowaMobile")
            if (!cookie.isNullOrBlank()) connection.setRequestProperty("Cookie", cookie)
            val code = connection.responseCode
            if (code !in 200..299) return MobileSessionSnapshot(MobileSessionStatus.UNAVAILABLE)
            val body = connection.inputStream.bufferedReader(Charsets.UTF_8).use { it.readText() }
            parseMobileSessionResponse(body)
        } catch (_: Exception) {
            MobileSessionSnapshot(MobileSessionStatus.UNAVAILABLE)
        } finally {
            connection?.disconnect()
        }
    }

    private fun update(origin: String, snapshot: MobileSessionSnapshot) {
        _sessions.value = _sessions.value.toMutableMap().apply { put(origin, snapshot) }
    }

    private fun markAllAnonymous() {
        refreshJobs.values.forEach { it.cancel() }
        refreshJobs.clear()
        refreshCallbacks.values.flatten().forEach { it(false) }
        refreshCallbacks.clear()
        _sessions.value = _sessions.value.mapValues {
            MobileSessionSnapshot(status = MobileSessionStatus.ANONYMOUS)
        }
        _logoutEpoch.intValue += 1
    }

    private fun sessionOrigin(baseUrl: String): String = baseUrl.trimEnd('/')
}

internal fun parseMobileSessionResponse(raw: String): MobileSessionSnapshot {
    return runCatching {
        val root = JSONObject(raw)
        if (!root.optBoolean("ok", false)) {
            return MobileSessionSnapshot(MobileSessionStatus.UNAVAILABLE)
        }
        if (!root.optBoolean("authenticated", false)) {
            return MobileSessionSnapshot(MobileSessionStatus.ANONYMOUS)
        }
        val session = root.getJSONObject("session")
        val user = root.getJSONObject("user")
        val generation = session.getString("generation")
        val userId = user.getLong("id")
        val sessionVersion = session.getLong("version")
        val sessionExpiresAt = session.getLong("session_expires_at")
        if (
            !generation.matches(Regex("^[a-f0-9]{32}$"))
            || userId <= 0L
            || sessionVersion < 0L
            || sessionExpiresAt <= 0L
        ) {
            return MobileSessionSnapshot(MobileSessionStatus.UNAVAILABLE)
        }
        val rolesJson = user.optJSONArray("roles")
        val roles = buildSet {
            if (rolesJson != null) {
                for (index in 0 until rolesJson.length()) add(rolesJson.getString(index))
            }
        }
        MobileSessionSnapshot(
            status = MobileSessionStatus.AUTHENTICATED,
            userId = userId,
            primaryRole = user.optString("primary_role", "reader"),
            roles = roles,
            canWrite = user.getBoolean("can_write"),
            walletEnabled = user.getBoolean("wallet_enabled"),
            payoutEnabled = user.getBoolean("payout_enabled"),
            generation = generation,
            sessionVersion = sessionVersion,
            sessionExpiresAt = sessionExpiresAt,
        )
    }.getOrElse {
        MobileSessionSnapshot(MobileSessionStatus.UNAVAILABLE)
    }
}
