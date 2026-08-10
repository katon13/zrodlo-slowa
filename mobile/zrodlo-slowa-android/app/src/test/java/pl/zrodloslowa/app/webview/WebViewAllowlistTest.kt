package pl.zrodloslowa.app.webview

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * ETAP 2 z dyspozycji: bezpieczny WebView musi ograniczać nawigację do
 * allowlisty domen produkcyjnych (+ ewentualnego hosta debug), a każdy inny
 * host (OAuth, treści zewnętrzne) ma zostać uznany za niedozwolony wewnątrz
 * WebView serwisu.
 */
class WebViewAllowlistTest {

    @Test
    fun `dopuszcza wszystkie szesc domen produkcyjnych`() {
        listOf(
            "zrodlo-slowa.pl",
            "sourceofword.co.uk",
            "de-wortquelle.de",
            "source-des-mots.fr",
            "fonte-di-parole.it",
            "fuente-de-palabras.es",
        ).forEach { domain ->
            assertTrue(domain, WebViewAllowlist.isAllowedHost(domain))
        }
    }

    @Test
    fun `dopuszcza subdomeny domen produkcyjnych`() {
        assertTrue(WebViewAllowlist.isAllowedHost("www.zrodlo-slowa.pl"))
    }

    @Test
    fun `odrzuca host spoza allowlisty`() {
        assertFalse(WebViewAllowlist.isAllowedHost("accounts.google.com"))
        assertFalse(WebViewAllowlist.isAllowedHost("evil-zrodlo-slowa.pl.attacker.com"))
    }

    /**
     * Regresja luki bezpieczeństwa wykrytej w niezależnym audycie
     * (DYSPOZYCJA_NAPRAWCZA pkt 9, "dokładne hosty, nie wildcard/prefiks"):
     * poprzednia wersja dopuszczała DOWOLNĄ subdomenę (`normalized.endsWith(".$allowed")`),
     * więc host w pełni kontrolowany przez atakującego w rodzaju
     * "cokolwiek.zrodlo-slowa.pl" (np. gdyby ktoś uzyskał wpis DNS/subdomenę)
     * był błędnie traktowany jak zaufany host serwisu. Dozwolony jest teraz
     * tylko jawnie wypisany w [pl.zrodloslowa.app.config.SiteConfig.allowlistHosts] wariant `www.`.
     */
    @Test
    fun `odrzuca dowolna inna subdomene niz jawnie dozwolone www`() {
        assertFalse(WebViewAllowlist.isAllowedHost("cokolwiek.zrodlo-slowa.pl"))
        assertFalse(WebViewAllowlist.isAllowedHost("cdn.zrodlo-slowa.pl"))
        assertFalse(WebViewAllowlist.isAllowedHost("admin.zrodlo-slowa.pl"))
    }

    @Test
    fun `blokuje sciezki panelu administracyjnego niezaleznie od wielkosci liter`() {
        assertTrue(WebViewAllowlist.isBlockedAdminPath("/admin"))
        assertTrue(WebViewAllowlist.isBlockedAdminPath("/admin/users"))
        assertTrue(WebViewAllowlist.isBlockedAdminPath("/Admin/Dashboard"))
        assertFalse(WebViewAllowlist.isBlockedAdminPath("/administrator-info"))
        assertFalse(WebViewAllowlist.isBlockedAdminPath("/articles"))
        assertFalse(WebViewAllowlist.isBlockedAdminPath(null))
    }

    @Test
    fun `odrzuca null i pusty host`() {
        assertFalse(WebViewAllowlist.isAllowedHost(null))
        assertFalse(WebViewAllowlist.isAllowedHost(""))
    }

    @Test
    fun `dopuszcza dodatkowy host debug tylko gdy przekazany`() {
        assertFalse(WebViewAllowlist.isAllowedHost("10.0.2.2"))
        assertTrue(WebViewAllowlist.isAllowedHost("10.0.2.2", extraAllowedHosts = setOf("10.0.2.2")))
    }

    @Test
    fun `dopuszcza https zawsze, http tylko w debug`() {
        assertTrue(WebViewAllowlist.isAllowedScheme("https"))
        assertTrue(WebViewAllowlist.isAllowedScheme("HTTPS", allowInsecureHttp = false))
        assertFalse(WebViewAllowlist.isAllowedScheme("http"))
        assertFalse(WebViewAllowlist.isAllowedScheme("HTTP", allowInsecureHttp = false))
        assertTrue(WebViewAllowlist.isAllowedScheme("http", allowInsecureHttp = true))
        assertFalse(WebViewAllowlist.isAllowedScheme("intent"))
        assertFalse(WebViewAllowlist.isAllowedScheme(null))
    }

    /**
     * Regresja luki bezpieczeństwa wykrytej w niezależnym audycie
     * (DYSPOZYCJA_NAPRAWCZA pkt 2, "Zablokuj dowolne zewnętrzne intencje"):
     * jedynie `https`, `mailto`, `tel` (i `http` wyłącznie w wariancie debug)
     * mogą w ogóle trafić do zewnętrznej intencji — `intent`, `file`,
     * `content` i inne nieznane schematy są całkowicie zablokowane.
     */
    @Test
    fun `dopuszcza tylko https mailto tel jako schematy zewnetrzne`() {
        assertTrue(WebViewAllowlist.isAllowedExternalScheme("https"))
        assertTrue(WebViewAllowlist.isAllowedExternalScheme("mailto"))
        assertTrue(WebViewAllowlist.isAllowedExternalScheme("tel"))
        assertFalse(WebViewAllowlist.isAllowedExternalScheme("http"))
        assertTrue(WebViewAllowlist.isAllowedExternalScheme("http", allowInsecureHttp = true))
        assertFalse(WebViewAllowlist.isAllowedExternalScheme("intent"))
        assertFalse(WebViewAllowlist.isAllowedExternalScheme("file"))
        assertFalse(WebViewAllowlist.isAllowedExternalScheme("content"))
        assertFalse(WebViewAllowlist.isAllowedExternalScheme("javascript"))
        assertFalse(WebViewAllowlist.isAllowedExternalScheme(null))
    }
}
