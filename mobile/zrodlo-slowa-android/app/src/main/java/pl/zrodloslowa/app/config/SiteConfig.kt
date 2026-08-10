package pl.zrodloslowa.app.config

/**
 * Jedna wersja językowa/domenowa serwisu Źródło Słowa (ETAP 2 z dyspozycji).
 * Dane 1:1 z `config/sites.json` backendu — każdy język ma osobną domenę
 * produkcyjną (patrz AUDYT_ZALEZNOSCI_ZRODLO_SLOWA_MOBILE.md, pkt 6).
 */
data class ZrodloSlowaSite(
    val languageCode: String,
    val domain: String,
    val brandName: String,
    val wordmarkLine1: String,
    val wordmarkLine2: String,
)

/**
 * Statyczna konfiguracja 6 wersji językowych/domenowych serwisu oraz
 * allowlisty hostów, do których wolno nawigować w bezpiecznym WebView.
 * Zgodnie z audytem (RYZYKO KLUCZOWE, pkt 6): każda domena ma niezależną
 * sesję — nie ma tu żadnej logiki "kopiowania" sesji między domenami.
 */
object SiteConfig {

    val sites: List<ZrodloSlowaSite> = listOf(
        ZrodloSlowaSite("pl", "zrodlo-slowa.pl", "ŹRÓDŁO SŁOWA", "ŹRÓDŁO", "SŁOWA"),
        ZrodloSlowaSite("en", "sourceofword.co.uk", "SOURCE OF WORD", "SOURCE", "OF WORD"),
        ZrodloSlowaSite("de", "de-wortquelle.de", "WORTQUELLE", "WORT", "QUELLE"),
        ZrodloSlowaSite("fr", "source-des-mots.fr", "SOURCE DES MOTS", "SOURCE", "DES MOTS"),
        ZrodloSlowaSite("it", "fonte-di-parole.it", "FONTE DI PAROLE", "FONTE", "DI PAROLE"),
        ZrodloSlowaSite("es", "fuente-de-palabras.es", "FUENTE DE PALABRAS", "FUENTE", "DE PALABRAS"),
    )

    val defaultSite: ZrodloSlowaSite = sites.first { it.languageCode == "pl" }

    /**
     * Zestaw domen produkcyjnych dopuszczonych w bezpiecznym WebView.
     *
     * Naprawa "dokładne hosty, nie wildcard/prefiks" (niezależny audyt
     * bezpieczeństwa, DYSPOZYCJA_NAPRAWCZA pkt 9): [pl.zrodloslowa.app.webview.WebViewAllowlist]
     * dopuszcza już wyłącznie DOKŁADNE dopasowanie hosta (bez generycznego
     * `*.domena`), więc wariant `www.` każdej domeny musi być tu wypisany
     * jawnie — inaczej blokowalibyśmy legalny link `www.zrodlo-slowa.pl`
     * tym samym mechanizmem, który ma blokować `cokolwiek.zrodlo-slowa.pl`.
     */
    val allowlistHosts: Set<String> = sites.flatMap { listOf(it.domain, "www.${it.domain}") }.toSet()

    fun siteForLanguage(languageCode: String): ZrodloSlowaSite =
        sites.firstOrNull { it.languageCode.equals(languageCode, ignoreCase = true) } ?: defaultSite

    /**
     * Bazowy adres HTTPS danej wersji językowej. W wariancie debug wywołujący
     * może podmienić adres na lokalny serwer deweloperski (patrz
     * `BuildConfig.DEBUG_WEB_BASE_URL` w `app/build.gradle.kts`).
     */
    fun baseUrl(site: ZrodloSlowaSite): String = "https://${site.domain}/"
}
