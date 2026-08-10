package pl.zrodloslowa.app.webview

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Naprawa błędu z audytu ("Wylogowanie nie czyści całego stanu WebView"):
 * przed rozpoczęciem czyszczenia cookies/WebStorage/cache i resetu
 * [pl.zrodloslowa.app.ui.auth.AuthGate] trzeba niezawodnie rozpoznać
 * nawigację do rzeczywistej trasy wylogowania serwisu (`POST /logout`,
 * `views/layouts/main.php`) — bez fałszywych trafień na innych trasach
 * zawierających "logout" jedynie jako fragment.
 */
class SecureWebViewLogoutTest {

    @Test
    fun `rozpoznaje trase wylogowania dla kazdej wersji jezykowej`() {
        listOf(
            "https://zrodlo-slowa.pl/logout",
            "https://sourceofword.co.uk/logout",
            "https://de-wortquelle.de/logout",
            "http://10.0.2.2:8000/logout",
        ).forEach { url -> assertTrue(url, isLogoutNavigationUrl(url)) }
    }

    @Test
    fun `ignoruje koncowy ukosnik oraz query i fragment`() {
        assertTrue(isLogoutNavigationUrl("https://zrodlo-slowa.pl/logout/"))
        assertTrue(isLogoutNavigationUrl("https://zrodlo-slowa.pl/logout?redirect=1"))
        assertTrue(isLogoutNavigationUrl("https://zrodlo-slowa.pl/logout#footer"))
    }

    @Test
    fun `odrzuca inne trasy w tym takie zawierajace logout jako fragment`() {
        assertFalse(isLogoutNavigationUrl("https://zrodlo-slowa.pl/login"))
        assertFalse(isLogoutNavigationUrl("https://zrodlo-slowa.pl/account/settings"))
        assertFalse(isLogoutNavigationUrl("https://zrodlo-slowa.pl/articles/jak-sie-nie-wylogowac"))
        assertFalse(isLogoutNavigationUrl("https://zrodlo-slowa.pl/logout-info"))
    }

    @Test
    fun `odrzuca null`() {
        assertFalse(isLogoutNavigationUrl(null))
    }
}
