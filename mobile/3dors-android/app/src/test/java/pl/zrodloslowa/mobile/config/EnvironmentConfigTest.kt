package pl.zrodloslowa.mobile.config

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Testy wykrywania placeholderów konfiguracyjnych (dyspozycja, pkt "Release
 * safety"): build release NIE MOŻE działać z `https://CHANGE_ME/` ani z
 * przykładową domeną App Link.
 */
class EnvironmentConfigTest {

    @Test
    fun `wykrywa CHANGE_ME jako placeholder`() {
        assertTrue(EnvironmentConfig.isPlaceholderValue("https://CHANGE_ME/"))
    }

    @Test
    fun `wykrywa przykladowa domene App Link jako placeholder`() {
        assertTrue(EnvironmentConfig.isPlaceholderValue("https://3dors.przyklad-domeny.pl/3dors/approve"))
    }

    @Test
    fun `pusta wartosc jest placeholderem`() {
        assertTrue(EnvironmentConfig.isPlaceholderValue(""))
        assertTrue(EnvironmentConfig.isPlaceholderValue("   "))
    }

    @Test
    fun `prawidlowo skonfigurowany adres nie jest placeholderem`() {
        assertFalse(EnvironmentConfig.isPlaceholderValue("https://api.zrodloslowa.pl/"))
        assertFalse(EnvironmentConfig.isPlaceholderValue("panel.zrodloslowa.pl"))
    }

    @Test
    fun `wykrywanie placeholdera nie zalezy od wielkosci liter`() {
        assertTrue(EnvironmentConfig.isPlaceholderValue("https://change_me.example/"))
    }
}
