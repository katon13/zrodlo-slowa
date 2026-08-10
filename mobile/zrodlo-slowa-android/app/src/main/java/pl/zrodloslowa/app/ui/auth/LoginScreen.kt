package pl.zrodloslowa.app.ui.auth

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import pl.zrodloslowa.app.R
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.session.WebSessionManager
import pl.zrodloslowa.app.webview.SecureWebView
import pl.zrodloslowa.app.webview.SessionSecurityState
import pl.zrodloslowa.app.webview.WebUrlResolver

/**
 * Ekran logowania (ETAP 2 z dyspozycji): otwiera prawdziwą stronę `/login`
 * istniejącego serwisu w bezpiecznym WebView — bez żadnej natywnej logiki
 * uwierzytelniania (hasła, 2FA, OAuth pozostają w 100% po stronie serwera,
 * zgodnie z pkt 2.2 dyspozycji).
 *
 * Naprawa błędu z audytu ("Zakończenie logowania jest wykrywane zbyt
 * szeroko"): samo opuszczenie adresu `/login` (np. przejście na publiczną
 * Główną) już NIE jest traktowane jako sukces logowania. Po opuszczeniu tras
 * pośrednich procesu logowania/rejestracji wykonujemy dodatkową, rzeczywistą
 * weryfikację ([WebSessionManager.verifySession]) chronionej trasy serwisu —
 * `onLoggedIn()` wywołujemy tylko, gdy serwer faktycznie nie przekierował do
 * logowania — nigdy przez odczyt treści cookie ani zgadywanie po adresie URL.
 *
 * Doprecyzowanie dyspozycji pkt 4.2: przez cały czas, gdy ten ekran jest
 * widoczny — logowanie, weryfikacja dwuetapowa (2FA), reset hasła —
 * [SessionSecurityState.authFlowActive] jest `true`, więc `FLAG_SECURE` jest
 * włączone od razu, ZANIM serwer w ogóle potwierdzi zalogowanie.
 */
@Composable
fun LoginScreen(
    languageCode: String = "pl",
    onLoggedIn: () -> Unit = {},
    modifier: Modifier = Modifier,
) {
    val site = remember(languageCode) { SiteConfig.siteForLanguage(languageCode) }
    val baseUrl = remember(site) { WebUrlResolver.baseUrl(site) }
    val loginUrl = remember(baseUrl) { WebUrlResolver.pageUrl(site, "login") }
    val extraAllowedHosts = remember(baseUrl) { WebUrlResolver.extraAllowedHosts(baseUrl) }
    val onLoggedInState = rememberUpdatedState(onLoggedIn)
    var verifying by remember(baseUrl) { mutableStateOf(false) }

    DisposableEffect(Unit) {
        SessionSecurityState.authFlowActive = true
        onDispose { SessionSecurityState.authFlowActive = false }
    }

    Column(modifier = modifier.fillMaxSize()) {
        Text(
            text = stringResource(R.string.login_title, site.brandName),
            style = MaterialTheme.typography.titleMedium,
            modifier = Modifier.padding(16.dp),
        )
        SecureWebView(
            url = loginUrl,
            extraAllowedHosts = extraAllowedHosts,
            onPageFinished = { finishedUrl ->
                if (!isAuthFlowUrl(finishedUrl) && !verifying) {
                    verifying = true
                    WebSessionManager.verifySession(baseUrl) { verified ->
                        verifying = false
                        if (verified) {
                            onLoggedInState.value()
                        }
                        // Jeśli serwer nadal przekierowuje do logowania —
                        // pozostajemy na tym ekranie (WebView i tak dalej
                        // pokazuje bieżącą stronę serwisu).
                    }
                }
            },
        )
    }
}

/**
 * Trasy pośrednie procesu logowania/rejestracji (pkt 3.5/9 z dyspozycji) —
 * dotarcie do dowolnej z nich NIE oznacza zakończonego logowania, ponieważ
 * to wciąż część przepływu uwierzytelniania (a nie chroniona treść konta).
 */
private val authFlowPathFragments = listOf(
    "/login",
    "/register",
    "/reset-password",
    "/forgot-password",
    "/password/reset",
    "/2fa",
    "/verify",
    "/dors3",
)

private fun isAuthFlowUrl(url: String): Boolean =
    authFlowPathFragments.any { fragment -> url.contains(fragment, ignoreCase = true) }
