package pl.zrodloslowa.mobile.deeplink

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import pl.zrodloslowa.mobile.BuildConfig

/**
 * Test reguły bezpieczeństwa App Linku (dyspozycja, pkt "Release safety"):
 * release NIE MOŻE akceptować App Linku, jeśli host wciąż jest przykładową
 * domeną placeholder (`EnvironmentConfig.isAppLinkHostPlaceholder`).
 *
 * Domyślna konfiguracja testowa (`DORS3_APP_LINK_HOST`) w tym module jest
 * wciąż przykładową domeną — dlatego w "trybie release" App Link musiałby
 * zostać zablokowany.
 */
class Dors3DeepLinkTest {

    @Test
    fun `w trybie release z placeholderowym hostem App Link jest blokowany`() {
        assertTrue(Dors3DeepLink.shouldBlockAppLink(isDebugBuild = false))
    }

    @Test
    fun `w trybie debug placeholderowy host App Link jest dopuszczony (lokalne testy)`() {
        assertFalse(Dors3DeepLink.shouldBlockAppLink(isDebugBuild = true))
    }

    @Test
    fun `debug przyjmuje tylko schemat przypisany do skompilowanego wariantu`() {
        assertTrue(Dors3DeepLink.acceptsDebugScheme(BuildConfig.DORS3_DEBUG_LINK_SCHEME))
        val otherVariant = if (BuildConfig.DORS3_APPLICATION_VARIANT == "admin") {
            "dors3-author-dev"
        } else {
            "dors3-admin-dev"
        }
        assertFalse(Dors3DeepLink.acceptsDebugScheme(otherVariant))
        assertFalse(Dors3DeepLink.acceptsDebugScheme("dors3-dev"))
    }
}
