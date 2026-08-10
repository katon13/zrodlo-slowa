package pl.zrodloslowa.mobile.deeplink

import android.net.Uri
import pl.zrodloslowa.mobile.BuildConfig
import pl.zrodloslowa.mobile.config.EnvironmentConfig

/**
 * Obsługa przepływu B — "Strona i aplikacja na tym samym telefonie" (pkt 0.1, 5.3).
 *
 * Link zawiera WYŁĄCZNIE publiczny identyfikator żądania. Aplikacja pobiera
 * pełne, zweryfikowane szczegóły z backendu (nigdy nie ufa payloadowi z URI) —
 * zgodnie z krokami 1-3 z pkt 5.3.
 */
object Dors3DeepLink {

    private const val APP_LINK_PATH_PREFIX = "/3dors/approve/"
    private const val DEV_HOST = "approve"

    /** Wyciąga publiczny identyfikator żądania z obsługiwanego linku, jeśli to możliwe. */
    fun extractRequestPublicId(uri: Uri): String? {
        return when (uri.scheme) {
            "https" -> extractFromAppLink(uri)
            BuildConfig.DORS3_DEBUG_LINK_SCHEME -> extractFromDebugScheme(uri)
            else -> null
        }
    }

    /**
     * Czysta, testowalna reguła bezpieczeństwa (dyspozycja, pkt "Release
     * safety"): jeśli build release nie ma poprawnie skonfigurowanego hosta
     * App Link (wciąż przykładowa domena placeholder), NIGDY nie akceptujemy
     * App Linku — bezpieczna blokada zamiast przypadkowego zaufania
     * nieistniejącej domenie.
     */
    fun shouldBlockAppLink(isDebugBuild: Boolean): Boolean =
        !isDebugBuild && EnvironmentConfig.isAppLinkHostPlaceholder

    fun acceptsDebugScheme(scheme: String?): Boolean =
        EnvironmentConfig.DEBUG_DEEP_LINK_ENABLED && scheme == BuildConfig.DORS3_DEBUG_LINK_SCHEME

    private fun extractFromAppLink(uri: Uri): String? {
        if (shouldBlockAppLink(BuildConfig.DEBUG)) return null

        // Dodatkowa (poza manifestem/Digital Asset Links) warstwa walidacji hosta
        // App Linku (dyspozycja, ETAP 2: "walidacja hosta, środowiska, TTL i
        // wersji protokołu") — manifest sam w sobie chroni system operacyjny,
        // ale nie chroni przed np. ręcznie skonstruowanym Intentem w testach.
        val expectedHost = EnvironmentConfig.EXPECTED_APP_LINK_HOST
        if (expectedHost.isNotBlank() && uri.host != expectedHost) return null

        val path = uri.path ?: return null
        if (!path.startsWith(APP_LINK_PATH_PREFIX)) return null
        return path.removePrefix(APP_LINK_PATH_PREFIX).takeIf { it.isNotBlank() }
    }

    private fun extractFromDebugScheme(uri: Uri): String? {
        // Deweloperski schemat jest dopuszczony wyłącznie w wariancie debug
        // (pkt 5.3: "działa wyłącznie w wariancie debug"; produkcja go odrzuca).
        if (!acceptsDebugScheme(uri.scheme)) return null
        if (uri.host != DEV_HOST) return null
        // dors3-admin-dev://approve/ID albo dors3-author-dev://approve/ID
        val segments = uri.pathSegments
        return segments.firstOrNull()?.takeIf { it.isNotBlank() }
    }
}
