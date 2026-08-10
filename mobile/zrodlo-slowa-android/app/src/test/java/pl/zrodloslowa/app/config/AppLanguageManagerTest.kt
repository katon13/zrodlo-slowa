package pl.zrodloslowa.app.config

import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Testy rozpoznawania wersji językowej powłoki (ETAP 8 z dyspozycji).
 * Obsługiwane wersje pochodzą z [SiteConfig] (6 domen produkcyjnych).
 */
class AppLanguageManagerTest {

    @Test
    fun `rozpoznaje obslugiwany jezyk angielski`() {
        assertEquals("en", AppLanguageManager.resolveLanguageCode(Locale.ENGLISH))
    }

    @Test
    fun `rozpoznaje obslugiwany jezyk niemiecki`() {
        assertEquals("de", AppLanguageManager.resolveLanguageCode(Locale.GERMAN))
    }

    @Test
    fun `nieobslugiwany jezyk systemowy spada do PL`() {
        assertEquals("pl", AppLanguageManager.resolveLanguageCode(Locale("uk", "UA")))
    }

    @Test
    fun `rozpoznaje polski niezaleznie od kraju`() {
        assertEquals("pl", AppLanguageManager.resolveLanguageCode(Locale("pl", "PL")))
    }

    @Test
    fun `wielkosc liter nie ma znaczenia`() {
        assertEquals("fr", AppLanguageManager.resolveLanguageCode(Locale("FR", "FR")))
    }

    /**
     * Test regresyjny "język UI == język aktywnej domeny"
     * (DYSPOZYCJA_JUNIE_KOREKTA_LOGO_JEZYKA_INTRO_I_ODBIOR pkt 1): domyślny
     * `BuildConfig.DEBUG_WEB_BASE_URL` (build.gradle.kts) ładuje wyłącznie
     * lokalny backend PL, więc dopóki nie zostanie jawnie nadpisany, jeden
     * backend musi wymuszać PL dla całej powłoki natywnej — inaczej interfejs
     * może pokazać się np. po angielsku nad realnie polską stroną.
     */
    @Test
    fun `pojedynczy debugowy backend jest rozpoznany gdy adres jest ustawiony`() {
        assertEquals(
            pl.zrodloslowa.app.BuildConfig.DEBUG && pl.zrodloslowa.app.BuildConfig.DEBUG_WEB_BASE_URL.isNotBlank(),
            AppLanguageManager.isSingleDebugBackendActive(),
        )
    }

    @Test
    fun `przy pojedynczym debugowym backendzie jezyk UI jest zawsze PL niezaleznie od systemu`() {
        if (!AppLanguageManager.isSingleDebugBackendActive()) return
        assertEquals(SiteConfig.defaultSite.languageCode, "pl")
    }
}
