package pl.zrodloslowa.app.config

import android.content.Context
import java.util.Locale
import pl.zrodloslowa.app.BuildConfig

/**
 * ETAP 8 z dyspozycji: rozpoznawanie wersji językowej/domenowej serwisu
 * ([SiteConfig] — 6 osobnych domen, patrz audyt pkt 6) na podstawie języka
 * systemowego urządzenia, z bezpiecznym fallbackiem do PL, gdy język
 * systemu nie jest jedną z obsługiwanych wersji.
 *
 * To wyłącznie wybór, KTÓRĄ z istniejących, gotowych domen backendu
 * wyświetlić (`SiteConfig.siteForLanguage`) — sam kurs TT oraz treści
 * PL/EN i pozostałych wersji są w całości renderowane przez backend
 * (zgodnie z audytem: "tt_rate_label liczony wyłącznie po stronie
 * backendu"), bez żadnej natywnej logiki tłumaczeń treści serwisu.
 */
object AppLanguageManager {

    /**
     * Zwraca kod języka jednej z obsługiwanych wersji [SiteConfig.sites] na
     * podstawie podanego locale, albo kod domyślny (PL), jeśli język
     * systemowy nie jest obsługiwany.
     */
    fun resolveLanguageCode(locale: Locale = Locale.getDefault()): String {
        val systemLanguage = locale.language.lowercase(Locale.ROOT)
        return SiteConfig.sites
            .firstOrNull { it.languageCode.equals(systemLanguage, ignoreCase = true) }
            ?.languageCode
            ?: SiteConfig.defaultSite.languageCode
    }

    /**
     * Wariant kontekstowy respektujący kolejność wyboru z pkt 7.2
     * dyspozycji: 1) ostatni jawny wybór użytkownika zapisany lokalnie
     * ([LanguagePreferenceStore]), 2) w przeciwnym razie — język systemu
     * Android (z fallbackiem do PL) via [resolveLanguageCode].
     */
    fun resolveEffectiveLanguageCode(context: Context, locale: Locale = Locale.getDefault()): String {
        // Naprawa DYSPOZYCJA_JUNIE_KOREKTA_LOGO_JEZYKA_INTRO_I_ODBIOR pkt 1:
        // w wariancie debug jeden lokalny backend (`BuildConfig.DEBUG_WEB_BASE_URL`)
        // obsługuje TYLKO treść PL — [pl.zrodloslowa.app.webview.WebUrlResolver]
        // ignoruje wybraną wersję i zawsze ładuje ten sam adres. Dawniej Compose
        // wybierał język systemu (np. EN), więc interfejs natywny pokazywał się
        // po angielsku nad realnie polską treścią strony — dokładnie błąd z
        // dyspozycji. Dopóki debugowy adres jest ustawiony, wymuszamy PL dla
        // CAŁEJ aplikacji (zasoby, marka, adres, menu, onboarding), niezależnie
        // od języka systemu i wcześniejszego ręcznego wyboru, tak aby interfejs
        // nigdy nie pokazywał się w innym języku niż faktycznie załadowana strona.
        if (isSingleDebugBackendActive()) {
            return SiteConfig.defaultSite.languageCode
        }
        val manualChoice = LanguagePreferenceStore.getManualLanguage(context)
        if (manualChoice != null && SiteConfig.sites.any { it.languageCode.equals(manualChoice, ignoreCase = true) }) {
            return manualChoice
        }
        return resolveLanguageCode(locale)
    }

    /**
     * `true`, gdy build jest debugowy i skonfigurowano pojedynczy lokalny
     * adres backendu wspólny dla wszystkich wersji językowych (patrz
     * [pl.zrodloslowa.app.webview.WebUrlResolver.baseUrl]) — w takim wypadku
     * ręczna zmiana języka i tak nie zmieni faktycznie ładowanej treści.
     */
    fun isSingleDebugBackendActive(): Boolean =
        BuildConfig.DEBUG && BuildConfig.DEBUG_WEB_BASE_URL.isNotBlank()
}
