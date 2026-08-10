package pl.zrodloslowa.app.webview

import pl.zrodloslowa.app.BuildConfig
import pl.zrodloslowa.app.config.SiteConfig

/**
 * Czysta logika decyzyjna bezpiecznego WebView (ETAP 2 z dyspozycji):
 * nawigacja wewnątrz WebView jest dozwolona wyłącznie do 6 produkcyjnych
 * domen serwisu (`SiteConfig.allowlistHosts`) oraz — tylko w wariancie
 * debug — do lokalnego adresu deweloperskiego. Każdy inny host (OAuth
 * Google/Apple, płatności zewnętrzne, linki w treści artykułu itp.) ma
 * zostać otwarty poza aplikacją (przeglądarka/Custom Tabs), a nie wewnątrz
 * WebView serwisu. Wydzielone do osobnej klasy bez zależności od Androida,
 * aby dało się to przetestować jednostkowo.
 */
object WebViewAllowlist {

    /**
     * Naprawa "dokładne hosty, nie wildcard/prefiks" (niezależny audyt
     * bezpieczeństwa, DYSPOZYCJA_NAPRAWCZA pkt 9): wcześniejsza wersja
     * dopuszczała też DOWOLNY subdomenowy wariant (`normalized.endsWith(".$allowed")`),
     * np. `cokolwiek.zrodlo-slowa.pl`. Żaden z realnie używanych adresów
     * ([SiteConfig.allowlistHosts], host debugowy z [extraAllowedHosts]) nie
     * wymaga takiej subdomeny — dopuszczamy więc wyłącznie dokładne
     * dopasowanie hosta.
     */
    fun isAllowedHost(host: String?, extraAllowedHosts: Set<String> = emptySet()): Boolean {
        if (host.isNullOrBlank()) return false
        val normalized = host.lowercase()
        val allHosts = SiteConfig.allowlistHosts + extraAllowedHosts
        return allHosts.any { allowed -> normalized == allowed.lowercase() }
    }

    /**
     * Naprawa pkt 3.8/12 z dyspozycji: w release dopuszczamy wyłącznie
     * `https`. `http` wolno przepuścić jedynie w wariancie debug (lokalne
     * adresy testowe, np. emulator) — [allowInsecureHttp] przekazuje to
     * wywołujący na podstawie `BuildConfig.DEBUG`.
     */
    fun isAllowedScheme(scheme: String?, allowInsecureHttp: Boolean = false): Boolean =
        scheme.equals("https", ignoreCase = true) ||
            (allowInsecureHttp && scheme.equals("http", ignoreCase = true))

    /**
     * Naprawa "Blokuj /admin i wszystkie trasy administracyjne w publicznej
     * aplikacji" (DYSPOZYCJA_NAPRAWCZA pkt 8): nawet w obrębie dozwolonego
     * hosta serwisu WWW, ścieżki panelu administracyjnego nie mają prawa
     * być ładowane w publicznej aplikacji mobilnej — niezależnie od tego,
     * czy backend i tak wymaga tam osobnego uprawnienia (obrona w głąb).
     */
    fun isBlockedAdminPath(path: String?): Boolean {
        if (path.isNullOrBlank()) return false
        val normalized = path.lowercase().trimStart('/')
        return normalized == "admin" || normalized.startsWith("admin/")
    }

    /**
     * Allowlista schematów, dla których w ogóle rozważamy otwarcie
     * ZEWNĘTRZNEJ intencji poza aplikacją (DYSPOZYCJA_NAPRAWCZA pkt 2):
     * `https` (OAuth, płatności, 3DORS Author/Admin — dalsza logika i tak
     * rozróżnia je osobno), `mailto`, `tel` oraz — wyłącznie w wariancie
     * debug — `http` (lokalny adres deweloperski 3DORS Author, patrz
     * `BuildConfig.DEBUG_WEB_BASE_URL`). WSZYSTKIE inne schematy
     * (`intent`, `file`, `content`, `javascript` i jakikolwiek nieznany)
     * są tu jawnie odrzucane — nigdy nie trafiają do
     * `Intent.ACTION_VIEW` uruchamianego poza aplikacją.
     */
    fun isAllowedExternalScheme(scheme: String?, allowInsecureHttp: Boolean = false): Boolean {
        val normalized = scheme?.lowercase() ?: return false
        return normalized == "https" ||
            normalized == "mailto" ||
            normalized == "tel" ||
            (allowInsecureHttp && normalized == "http") ||
            // Naprawa P0-1: custom scheme 3DORS Author debug
            // (`dors3-author-dev://approve/{id}`) musi przejść tę samą
            // allowlistę schematów, zanim w ogóle trafi do rozpoznania linku
            // 3DORS ([pl.zrodloslowa.app.dors3.Dors3AuthorLauncher]) —
            // wyłącznie w wariancie debug, nigdy w release. Schemat Admin
            // debug jest tu celowo NIE dodawany do "dozwolonych" — jest
            // rozpoznawany osobno wyłącznie po to, by go zablokować
            // (patrz [Dors3AuthorLauncher.isAdminLink] w miejscu wywołania).
            (allowInsecureHttp && normalized == BuildConfig.DORS3_AUTHOR_DEV_SCHEME.lowercase()) ||
            (allowInsecureHttp && normalized == BuildConfig.DORS3_ADMIN_DEV_SCHEME.lowercase())
    }

    /**
     * Naprawa P2-3 z audytu ("Allowlista nie ogranicza portu"): dotychczas
     * sprawdzany był wyłącznie host, więc adres typu
     * `https://zrodlo-slowa.pl:4443` (niestandardowy port, może wskazywać
     * na serwer atakującego za tym samym hostem/DNS) byłby uznany za
     * dozwolony. `-1` oznacza brak jawnego portu w URI (domyślny dla
     * danego schematu) — to jedyna dozwolona wartość dla `https`/`http`,
     * poza wariantem debug — tam lokalny serwer deweloperski (np. emulator
     * `10.0.2.2:8000`) może celowo używać niestandardowego portu, więc
     * kontrola portu jest tam pomijana ([allowInsecureHttp] == `true`).
     */
    fun isAllowedPort(port: Int, allowInsecureHttp: Boolean = false): Boolean =
        port == -1 || allowInsecureHttp
}
