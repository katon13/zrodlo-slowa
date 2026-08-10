package pl.zrodloslowa.app.webview

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import pl.zrodloslowa.app.BuildConfig
import pl.zrodloslowa.app.config.SiteConfig

/**
 * Testy jednostkowe [WebUrlResolver] (ETAP 3 z dyspozycji: adresy ekranów
 * Główna/Artykuły). W wariancie `debug` (`gradlew testDebugUnitTest`) aktywny
 * jest domyślny adres deweloperski z `build.gradle.kts`
 * (`DEBUG_WEB_BASE_URL`), dlatego oczekiwana wartość jest wyliczana z tego
 * samego `BuildConfig`, zamiast zakładać na sztywno adres produkcyjny.
 */
class WebUrlResolverTest {

    private fun expectedBaseUrl(site: pl.zrodloslowa.app.config.ZrodloSlowaSite): String =
        if (BuildConfig.DEBUG && BuildConfig.DEBUG_WEB_BASE_URL.isNotBlank()) {
            BuildConfig.DEBUG_WEB_BASE_URL
        } else {
            SiteConfig.baseUrl(site)
        }

    @Test
    fun `baseUrl matches BuildConfig override in debug, production domain otherwise`() {
        val site = SiteConfig.siteForLanguage("pl")
        assertEquals(expectedBaseUrl(site), WebUrlResolver.baseUrl(site))
    }

    @Test
    fun `pageUrl appends path without duplicated slashes`() {
        val site = SiteConfig.siteForLanguage("pl")
        val base = expectedBaseUrl(site).trimEnd('/')
        assertEquals("$base/articles", WebUrlResolver.pageUrl(site, "articles"))
        assertEquals("$base/articles", WebUrlResolver.pageUrl(site, "/articles"))
    }

    @Test
    fun `pageUrl with empty path equals baseUrl without trailing duplication`() {
        val site = SiteConfig.siteForLanguage("pl")
        assertEquals(WebUrlResolver.baseUrl(site), WebUrlResolver.pageUrl(site))
    }

    @Test
    fun `extraAllowedHosts extracts host from base url`() {
        assertEquals(setOf("10.0.2.2"), WebUrlResolver.extraAllowedHosts("http://10.0.2.2:8080/"))
    }

    @Test
    fun `extraAllowedHosts returns empty set for invalid url`() {
        assertTrue(WebUrlResolver.extraAllowedHosts("not a url").isEmpty())
    }
}
