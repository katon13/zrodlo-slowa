package pl.zrodloslowa.app.ui.account

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.ui.auth.AuthGate
import pl.zrodloslowa.app.webview.SecureWebView
import pl.zrodloslowa.app.webview.WebUrlResolver

/**
 * Ekran Konto / Panel autora (ETAP 6 z dyspozycji): `GET /account/settings`
 * wyświetlane w bezpiecznym [SecureWebView] (ETAP 2). Serwis renderuje w tym
 * miejscu odnośnik do Panelu autora (`GET /author`) wyłącznie dla kont z
 * uprawnieniem `can_write` — bez żadnej natywnej logiki uprawnień w
 * aplikacji, zgodnie z wnioskami z audytu (ETAP 0). Wymaga zalogowania —
 * bramkuje treść przez [AuthGate] (ETAP 2).
 */
@Composable
fun AccountScreen(languageCode: String = "pl", modifier: Modifier = Modifier) {
    AuthGate(languageCode = languageCode) { onLogoutDetected ->
        val site = remember(languageCode) { SiteConfig.siteForLanguage(languageCode) }
        val accountUrl = remember(site) { WebUrlResolver.pageUrl(site, "account/settings") }
        val extraAllowedHosts = remember(site) { WebUrlResolver.extraAllowedHosts(WebUrlResolver.baseUrl(site)) }

        SecureWebView(
            url = accountUrl,
            modifier = modifier.fillMaxSize(),
            extraAllowedHosts = extraAllowedHosts,
            onLogoutDetected = onLogoutDetected,
        )
    }
}
