package pl.zrodloslowa.app.config

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * ETAP 2 z dyspozycji: weryfikacja mapy 6 wersji językowych/domenowych
 * (zgodnie z audytem, pkt 6) oraz allowlisty WebView.
 */
class SiteConfigTest {

    @Test
    fun `zawiera dokladnie szesc jezykow z audytu`() {
        val languageCodes = SiteConfig.sites.map { it.languageCode }
        assertEquals(listOf("pl", "en", "de", "fr", "it", "es"), languageCodes)
    }

    @Test
    fun `kazda wersja jezykowa ma unikalna domene`() {
        val domains = SiteConfig.sites.map { it.domain }
        assertEquals(domains.size, domains.toSet().size)
    }

    @Test
    fun `domyslna wersja to polska`() {
        assertEquals("pl", SiteConfig.defaultSite.languageCode)
        assertEquals("zrodlo-slowa.pl", SiteConfig.defaultSite.domain)
    }

    @Test
    fun `siteForLanguage zwraca wlasciwa domene`() {
        assertEquals("sourceofword.co.uk", SiteConfig.siteForLanguage("en").domain)
        assertEquals("de-wortquelle.de", SiteConfig.siteForLanguage("DE").domain)
    }

    @Test
    fun `siteForLanguage nieznany kod wraca do domyslnej wersji`() {
        assertEquals(SiteConfig.defaultSite, SiteConfig.siteForLanguage("xx"))
    }

    @Test
    fun `baseUrl zawsze uzywa https`() {
        SiteConfig.sites.forEach { site ->
            assertTrue(SiteConfig.baseUrl(site).startsWith("https://"))
        }
    }

    @Test
    fun `allowlistHosts zawiera wszystkie domeny produkcyjne`() {
        assertTrue(SiteConfig.allowlistHosts.containsAll(SiteConfig.sites.map { it.domain }))
    }

    /**
     * Naprawa "dokładne hosty, nie wildcard/prefiks" (niezależny audyt
     * bezpieczeństwa, DYSPOZYCJA_NAPRAWCZA pkt 9): allowlista nie dopuszcza
     * już generycznego `*.domena`, więc wariant `www.` musi być tu wypisany
     * jawnie jako osobny, dokładny host — patrz [pl.zrodloslowa.app.webview.WebViewAllowlist].
     */
    @Test
    fun `allowlistHosts zawiera jawny wariant www dla kazdej domeny`() {
        SiteConfig.sites.forEach { site ->
            assertTrue("www.${site.domain}", SiteConfig.allowlistHosts.contains("www.${site.domain}"))
        }
    }

    @Test
    fun `kazda wersja ma jawnie kontrolowany dwuwierszowy wordmark`() {
        val expected = mapOf(
            "pl" to listOf("ŹRÓDŁO", "SŁOWA"),
            "en" to listOf("SOURCE", "OF WORD"),
            "de" to listOf("WORT", "QUELLE"),
            "fr" to listOf("SOURCE", "DES MOTS"),
            "it" to listOf("FONTE", "DI PAROLE"),
            "es" to listOf("FUENTE", "DE PALABRAS"),
        )

        SiteConfig.sites.forEach { site ->
            assertEquals(expected.getValue(site.languageCode), listOf(site.wordmarkLine1, site.wordmarkLine2))
            assertTrue(!site.wordmarkLine1.contains('\n'))
            assertTrue(!site.wordmarkLine2.contains('\n'))
        }
    }
}
