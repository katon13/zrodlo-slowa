package pl.zrodloslowa.app.webview

import pl.zrodloslowa.app.BuildConfig
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.config.ZrodloSlowaSite
import java.net.URI

/**
 * Wspólna logika wyznaczania adresu WWW danej wersji językowej dla ekranów
 * opartych o [SecureWebView] (Główna, Artykuły — ETAP 3; Logowanie — ETAP 2).
 * W wariancie debug pozwala podmienić domenę produkcyjną na lokalny adres
 * deweloperski (`BuildConfig.DEBUG_WEB_BASE_URL`), tak samo dla każdego ekranu.
 */
object WebUrlResolver {

    /** Bazowy adres (z ukośnikiem na końcu) dla danej wersji językowej. */
    fun baseUrl(site: ZrodloSlowaSite): String {
        val debugBaseUrl = BuildConfig.DEBUG_WEB_BASE_URL
        return if (BuildConfig.DEBUG && debugBaseUrl.isNotBlank()) debugBaseUrl else SiteConfig.baseUrl(site)
    }

    /** Pełny adres podstrony (`path` bez wiodącego ukośnika, np. "articles"). */
    fun pageUrl(site: ZrodloSlowaSite, path: String = ""): String =
        baseUrl(site).trimEnd('/') + "/" + path.trimStart('/')

    /** Host bazowego adresu — do dopisania do allowlisty WebView (np. host debugowy). */
    fun extraAllowedHosts(baseUrl: String): Set<String> =
        runCatching { URI(baseUrl).host }.getOrNull()?.let { setOf(it) } ?: emptySet()
}
