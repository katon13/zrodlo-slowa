package pl.zrodloslowa.app.ui.intro

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import pl.zrodloslowa.app.config.SiteConfig

/**
 * Czołówka ma korzystać z tego samego kontrolowanego lockupu co AppTopBar,
 * bez automatycznego i zależnego od szerokości dzielenia pełnej nazwy.
 */
class SourceIntroScreenWordmarkTest {

    @Test
    fun `uzywa oficjalnych wierszy polskiego wordmarku`() {
        assertEquals(
            listOf("ŹRÓDŁO", "SŁOWA"),
            introWordmarkLines(SiteConfig.siteForLanguage("pl")),
        )
    }

    @Test
    fun `uzywa oficjalnych wierszy angielskiego wordmarku`() {
        assertEquals(
            listOf("SOURCE", "OF WORD"),
            introWordmarkLines(SiteConfig.siteForLanguage("en")),
        )
    }

    @Test
    fun `kazda wersja ma dokladnie dwa niepuste wiersze`() {
        SiteConfig.sites.forEach { site ->
            val lines = introWordmarkLines(site)
            assertEquals(2, lines.size)
            assertTrue(lines.all(String::isNotBlank))
        }
    }
}
