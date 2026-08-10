package pl.zrodloslowa.app.dors3

import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import pl.zrodloslowa.app.BuildConfig
import java.security.MessageDigest

/**
 * ETAP 7 z dyspozycji: integracja z 3DORS Author (pkt 13).
 *
 * Powłoka wyłącznie ROZPOZNAJE i OTWIERA istniejący link do 3DORS Author —
 * nie wybiera typu podpisu, nie podpisuje, nie przechowuje klucza 3DORS,
 * nie odtwarza fingerprintu, nie otwiera 3DORS Admin i nie zawiera żadnego
 * kodu 3DORS. Link jest w całości tworzony przez istniejący backend
 * (`config/dors3.php`: `author_app_link_base_url` + `/{id}`) i renderowany
 * w stronie WWW — aplikacja jedynie rozpoznaje jego kształt, aby móc go
 * poprawnie otworzyć (App Link do zainstalowanej aplikacji 3DORS Author,
 * albo przeglądarka, jeśli aplikacja nie jest zainstalowana) i wiedzieć,
 * kiedy po powrocie odświeżyć stronę (patrz [Dors3ResultHandler]).
 */
object Dors3AuthorLauncher {

    private const val AUTHOR_APPROVAL_PATH_PREFIX = "/3dors/approve/"

    /**
     * Naprawa błędu z audytu ("Integracja 3DORS nie ogranicza hosta
     * Author"): backend rozróżnia Author i Admin osobnymi hostami App Link
     * (`config/dors3.php`: `author_app_link_base_url` vs
     * `admin_app_link_base_url`, patrz `.env.example`:
     * `author-3dors.*` / `admin-3dors.*`).
     *
     * Naprawa NAPRAWY z niezależnego audytu bezpieczeństwa
     * ("dokładne hosty, nie wildcard/prefiks", DYSPOZYCJA_NAPRAWCZA pkt 1):
     * poprzednia wersja dopuszczała `normalizedHost.startsWith(AUTHOR_HOST_PREFIX)`,
     * co rozpoznawało jako prawdziwy link Author RÓWNIEŻ host phishingowy w
     * rodzaju `author-3dors.attacker.example` (bo on też "zaczyna się od"
     * `author-3dors.`) — to była furtka pozwalająca podszyć się pod link
     * 3DORS Author i uruchomić dowolną, obcą stronę/aplikację przez App Link.
     * Teraz host musi DOKŁADNIE równać się jednemu ze znanych hostów Author
     * (produkcja + jawny host `-dev` z dyspozycji), nigdy prefiksowi.
     *
     * UWAGA (do potwierdzenia przed pilotem): rzeczywisty host produkcyjny
     * jest ustawiany w backendzie per środowisko (`DORS3_AUTHOR_APP_LINK_BASE_URL`,
     * w szablonie `.env.production.example` widnieje tam jawne `CHANGE_ME`) —
     * NIE da się go potwierdzić na podstawie samego kodu tego repozytorium.
     * Dlatego listy hostów są konfigurowalne przez `BuildConfig`
     * ([BuildConfig.DORS3_AUTHOR_HOSTS] / [BuildConfig.DORS3_ADMIN_HOSTS],
     * patrz `app/build.gradle.kts`), z domyślnymi wartościami pokrywającymi
     * jawnie wskazane w dyspozycji nazwy `dors3-author-dev` / `dors3-admin-dev`.
     */
    private val AUTHOR_HOSTS: Set<String> = parseHosts(BuildConfig.DORS3_AUTHOR_HOSTS)
    private val ADMIN_HOSTS: Set<String> = parseHosts(BuildConfig.DORS3_ADMIN_HOSTS)

    private fun parseHosts(raw: String): Set<String> =
        raw.split(',').map { it.trim().lowercase() }.filter { it.isNotBlank() }.toSet()

    /**
     * Naprawa P0-1 z audytu ("Lokalna integracja z 3DORS Author nadal nie
     * odpowiada istniejącemu kontraktowi"): rzeczywisty kontrakt debug
     * (patrz `mobile/3dors-android`, `Dors3DeepLink.kt`) NIE jest linkiem
     * HTTPS z hostem zaczynającym się od `dors3-author-dev` — to osobny,
     * CUSTOM SCHEME `dors3-author-dev://approve/{id}` (analogicznie
     * `dors3-admin-dev://approve/{id}`), gdzie `approve` to zawsze host
     * URI, a `{id}` to pierwszy segment ścieżki. Poprzednia wersja tego
     * pliku traktowała `dors3-author-dev` jako WARTOŚĆ HOSTA linku HTTPS,
     * więc: (1) nigdy nie rozpoznawała prawdziwego linku deweloperskiego,
     * (2) `WebViewAllowlist.isAllowedExternalScheme` blokowała go jeszcze
     * wcześniej, bo to w ogóle nie schemat `https`. Schemat deweloperski
     * jest aktywny WYŁĄCZNIE w wariancie debug (`BuildConfig.DEBUG`) —
     * release go nigdy nie rozpoznaje ani nie akceptuje.
     */
    private const val DEV_SCHEME_HOST = "approve"
    private val AUTHOR_DEV_SCHEME: String = BuildConfig.DORS3_AUTHOR_DEV_SCHEME.lowercase()
    private val ADMIN_DEV_SCHEME: String = BuildConfig.DORS3_ADMIN_DEV_SCHEME.lowercase()

    /** Pakiet istniejącej aplikacji 3DORS Author — link jest do niej jawnie przypięty (nie do przeglądarki). */
    const val AUTHOR_PACKAGE_NAME = "pl.zrodloslowa.dors3.author"

    /**
     * Dyspozycja pkt 4.3 ("sprawdzenie SHA-256 certyfikatu zainstalowanej
     * aplikacji; osobny fingerprint debug i release"): przypięcie do
     * [AUTHOR_PACKAGE_NAME] (`setPackage`) chroni przed tym, by System
     * podstawił POD TĄ SAMĄ NAZWĄ inną aplikację zainstalowaną z Google
     * Play/sklepu trzeciego — ale nie chroni przed aplikacją WGRANĄ RĘCZNIE
     * (sideload) pod tę samą nazwę pakietu, ale podpisaną INNYM kluczem
     * (np. spreparowaną). Ten fingerprint jest sprawdzany DODATKOWO, tuż
     * przed uruchomieniem — jeśli się nie zgadza, aplikacja jest traktowana
     * jak niezainstalowana (ten sam komunikat co przy braku pakietu), a nie
     * jest w ogóle uruchamiana.
     */
    private val EXPECTED_AUTHOR_CERT_SHA256: String =
        (if (BuildConfig.DEBUG) BuildConfig.DORS3_AUTHOR_CERT_SHA256_DEBUG else BuildConfig.DORS3_AUTHOR_CERT_SHA256_RELEASE)
            .replace(":", "")
            .uppercase()

    /**
     * Czy zainstalowana aplikacja [AUTHOR_PACKAGE_NAME] jest podpisana
     * oczekiwanym certyfikatem. W debug, dopóki fingerprint nie został
     * jawnie skonfigurowany (`ZRODLOSLOWA_DORS3_AUTHOR_CERT_SHA256_DEBUG`,
     * wartość domyślna `DEBUG_UNPINNED`), sprawdzenie jest pomijane — podpis
     * debugowy różni się między maszynami deweloperskimi/CI. W release
     * fingerprint jest zawsze wymagany (patrz `app/build.gradle.kts`,
     * build release nie powstanie bez jego jawnej konfiguracji).
     */
    fun isAuthorAppSignatureTrusted(context: Context): Boolean {
        if (BuildConfig.DEBUG && EXPECTED_AUTHOR_CERT_SHA256 == "DEBUG_UNPINNED") return true
        val actual = signingCertificateSha256(context, AUTHOR_PACKAGE_NAME) ?: return false
        return actual == EXPECTED_AUTHOR_CERT_SHA256
    }

    @Suppress("DEPRECATION")
    private fun signingCertificateSha256(context: Context, packageName: String): String? {
        return try {
            val packageManager = context.packageManager
            val signature = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                val info = packageManager.getPackageInfo(packageName, PackageManager.GET_SIGNING_CERTIFICATES)
                val signingInfo = info.signingInfo ?: return null
                val history = if (signingInfo.hasMultipleSigners()) {
                    signingInfo.apkContentsSigners
                } else {
                    signingInfo.signingCertificateHistory
                }
                history?.firstOrNull()
            } else {
                val info = packageManager.getPackageInfo(packageName, PackageManager.GET_SIGNATURES)
                info.signatures?.firstOrNull()
            } ?: return null
            val digest = MessageDigest.getInstance("SHA-256").digest(signature.toByteArray())
            digest.joinToString("") { "%02X".format(it) }
        } catch (_: Exception) {
            null
        }
    }

    /**
     * Czy podany URI to istniejący link zatwierdzenia 3DORS Author —
     * wyłącznie ten wariant (ETAP 7 dotyczy tylko przepływu autora); link do
     * 3DORS Admin jest rozpoznawany i jawnie odrzucany, nigdy otwierany.
     */
    fun isApprovalLink(uri: Uri): Boolean = isApprovalLink(scheme = uri.scheme, host = uri.host, path = uri.path)

    /**
     * Czysta, testowalna (bez zależności od Androida) wersja reguły powyżej —
     * analogicznie do [pl.zrodloslowa.app.webview.WebViewAllowlist].
     */
    fun isApprovalLink(scheme: String?, host: String?, path: String?): Boolean {
        val normalizedScheme = scheme?.lowercase()
        if (normalizedScheme == AUTHOR_DEV_SCHEME && BuildConfig.DEBUG) {
            return isDebugApprovalShape(host, path)
        }
        if (normalizedScheme != "https") return false
        if (path == null) return false
        if (!path.startsWith(AUTHOR_APPROVAL_PATH_PREFIX) || path.removePrefix(AUTHOR_APPROVAL_PATH_PREFIX).isBlank()) {
            return false
        }
        val normalizedHost = host?.lowercase() ?: return false
        if (normalizedHost in ADMIN_HOSTS) return false
        return normalizedHost in AUTHOR_HOSTS
    }

    /**
     * Kształt identyczny dla `dors3-author-dev://approve/{id}` i
     * `dors3-admin-dev://approve/{id}` — rozróżnia je WYŁĄCZNIE schemat,
     * dokładnie tak jak w [pl.zrodloslowa.mobile.deeplink.Dors3DeepLink]
     * (`mobile/3dors-android`).
     */
    private fun isDebugApprovalShape(host: String?, path: String?): Boolean {
        if (host?.lowercase() != DEV_SCHEME_HOST) return false
        val firstSegment = path?.trimStart('/')?.substringBefore('/')
        return !firstSegment.isNullOrBlank()
    }

    /** Czy podany URI to link zatwierdzenia 3DORS Admin — do jawnego odrzucenia. */
    fun isAdminLink(uri: Uri): Boolean = isAdminLink(scheme = uri.scheme, host = uri.host, path = uri.path)

    /**
     * Czysta, testowalna (bez zależności od Androida) wersja reguły powyżej —
     * analogicznie do [isApprovalLink].
     */
    fun isAdminLink(scheme: String?, host: String?, path: String?): Boolean {
        val normalizedScheme = scheme?.lowercase()
        if (normalizedScheme == ADMIN_DEV_SCHEME && BuildConfig.DEBUG) {
            return isDebugApprovalShape(host, path)
        }
        if (normalizedScheme != "https") return false
        val normalizedPath = path ?: return false
        val normalizedHost = host?.lowercase() ?: return false
        return normalizedPath.startsWith(AUTHOR_APPROVAL_PATH_PREFIX) && normalizedHost in ADMIN_HOSTS
    }
}
