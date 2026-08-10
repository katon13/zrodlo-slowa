package pl.zrodloslowa.app.ui.auth

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import kotlinx.coroutines.delay
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.session.MobileSessionSnapshot
import pl.zrodloslowa.app.session.MobileSessionStatus
import pl.zrodloslowa.app.session.WebSessionManager
import pl.zrodloslowa.app.ui.common.OfflineErrorScreen
import pl.zrodloslowa.app.webview.SessionSecurityState
import pl.zrodloslowa.app.webview.WebUrlResolver

/** Brama wszystkich ekranów konta oparta o jeden kanoniczny stan sesji Mobile. */
@Composable
fun AuthGate(
    languageCode: String = "pl",
    content: @Composable (onLogoutDetected: () -> Unit) -> Unit,
) {
    val site = remember(languageCode) { SiteConfig.siteForLanguage(languageCode) }
    val baseUrl = remember(site) { WebUrlResolver.baseUrl(site) }
    val allSessions by WebSessionManager.sessions
    val session = allSessions[baseUrl.trimEnd('/')] ?: MobileSessionSnapshot()

    LaunchedEffect(baseUrl) {
        WebSessionManager.verifySession(baseUrl) {}
    }

    LaunchedEffect(session.status) {
        when (session.status) {
            MobileSessionStatus.AUTHENTICATED -> SessionSecurityState.sessionConfirmed = true
            MobileSessionStatus.ANONYMOUS -> SessionSecurityState.sessionConfirmed = false
            else -> Unit
        }
    }

    LaunchedEffect(baseUrl, session.status, session.sessionExpiresAt) {
        val expiresAt = session.sessionExpiresAt
        if (session.status == MobileSessionStatus.AUTHENTICATED && expiresAt != null) {
            delay(sessionExpiryProbeDelayMillis(expiresAt, System.currentTimeMillis()))
            WebSessionManager.verifySession(baseUrl) {}
        }
    }

    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner, baseUrl) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) {
                WebSessionManager.verifySession(baseUrl) {}
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    when (session.status) {
        MobileSessionStatus.UNKNOWN,
        MobileSessionStatus.CHECKING,
        -> Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator()
        }

        MobileSessionStatus.AUTHENTICATED -> content {
            SessionSecurityState.sessionConfirmed = false
        }

        MobileSessionStatus.ANONYMOUS -> LoginScreen(languageCode = languageCode)

        MobileSessionStatus.UNAVAILABLE -> OfflineErrorScreen(
            onRetry = { WebSessionManager.verifySession(baseUrl) {} },
        )
    }
}

internal fun sessionExpiryProbeDelayMillis(sessionExpiresAtSeconds: Long, nowMillis: Long): Long {
    val expiresAtMillis = if (sessionExpiresAtSeconds > Long.MAX_VALUE / 1_000L) {
        Long.MAX_VALUE
    } else {
        sessionExpiresAtSeconds * 1_000L
    }
    val remaining = if (expiresAtMillis <= nowMillis) 0L else expiresAtMillis - nowMillis
    return remaining.coerceAtMost(Long.MAX_VALUE - SESSION_EXPIRY_PROBE_GRACE_MILLIS) +
        SESSION_EXPIRY_PROBE_GRACE_MILLIS
}

private const val SESSION_EXPIRY_PROBE_GRACE_MILLIS = 250L
