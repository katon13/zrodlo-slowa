package pl.zrodloslowa.mobile.config

import pl.zrodloslowa.mobile.BuildConfig

/**
 * Konfiguracja środowiska 3DORS Mobile.
 *
 * Zgodnie z DYSPOZYCJA_CODEX_3DORS_MOBILE_ANDROID_V2 pkt 3 i 3.1:
 * - build debug domyślnie korzysta z adresu emulatora Android (10.0.2.2), który
 *   przekierowuje na 127.0.0.1 hosta;
 * - dla testu na fizycznym telefonie należy zbudować debug z właściwością Gradle
 *   `DORS3_DEBUG_API_BASE_URL=http://127.0.0.1:8080/` i uruchomić `adb reverse`.
 *   Alternatywą jest prywatny adres HTTPS z certyfikatem zaufanym przez telefon.
 */
object EnvironmentConfig {

    /** Domyślny adres API skonfigurowany na etapie budowania (per build type). */
    const val DEFAULT_API_BASE_URL: String = BuildConfig.DORS3_API_BASE_URL

    /** Nazwa środowiska pokazywana na ekranach operacji (LOKALNE / TESTOWE / PRODUKCJA). */
    const val ENVIRONMENT_LABEL: String = BuildConfig.DORS3_ENVIRONMENT

    /** Nazwa serwisu pokazywana na ekranach zgodnie z pkt 5.2 dyspozycji. */
    const val SERVICE_NAME: String = "Źródło Słowa"

    /** Czy w tym buildzie wolno obsłużyć deweloperski deep link dors3-dev://. */
    const val DEBUG_DEEP_LINK_ENABLED: Boolean = BuildConfig.DORS3_DEBUG_DEEP_LINK_ENABLED

    /** Maksymalna ważność żądania logowania/operacji w sekundach (pkt 5.2, 6.2). */
    const val REQUEST_TTL_SECONDS: Long = 60L

    /**
     * Wersja protokołu obsługiwana przez ten build klienta. Kody QR/żądania
     * z inną wersją muszą zostać odrzucone (dyspozycja: "walidacja... wersji
     * protokołu").
     */
    const val SUPPORTED_PROTOCOL_VERSION: Int = 1

    /** Oczekiwany host App Link — dodatkowa (poza manifestem) warstwa walidacji. */
    const val EXPECTED_APP_LINK_HOST: String = BuildConfig.DORS3_APP_LINK_HOST

    /**
     * Markery placeholderów konfiguracyjnych, które NIGDY nie mogą trafić do
     * builda release (dyspozycja, pkt "Release safety"): przykładowy adres API
     * (`https://CHANGE_ME/`) i przykładowa domena App Link/origin. Wykrycie
     * któregokolwiek z nich w release ma bezpiecznie zablokować połączenie
     * sieciowe / akceptację App Linku.
     */
    private val PLACEHOLDER_MARKERS = listOf("CHANGE_ME", "przyklad-domeny", "example.com", "example.org")

    fun isPlaceholderValue(value: String): Boolean =
        value.isBlank() || PLACEHOLDER_MARKERS.any { value.contains(it, ignoreCase = true) }

    /** Czy adres API skonfigurowany na etapie budowania jest niewypełnionym placeholderem. */
    val isApiBaseUrlPlaceholder: Boolean get() = isPlaceholderValue(DEFAULT_API_BASE_URL)

    /** Czy host App Link/origin skonfigurowany na etapie budowania jest niewypełnionym placeholderem. */
    val isAppLinkHostPlaceholder: Boolean get() = isPlaceholderValue(EXPECTED_APP_LINK_HOST)

    /** Host origin webowej sesji, do walidacji pola `server_origin` żądań — `null`, gdy niekonfigurowany/placeholder. */
    val expectedServerOriginHost: String? get() = EXPECTED_APP_LINK_HOST.takeIf { !isAppLinkHostPlaceholder }
}
