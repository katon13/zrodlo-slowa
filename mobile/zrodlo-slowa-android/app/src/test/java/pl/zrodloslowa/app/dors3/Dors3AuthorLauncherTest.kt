package pl.zrodloslowa.app.dors3

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Testy czystej logiki rozpoznawania linku zatwierdzenia 3DORS Author
 * (ETAP 7, pkt 13 z dyspozycji, uzupełnione o naprawę z audytu: allowlista
 * hosta). Kształt linku pochodzi z istniejącego `config/dors3.php`
 * (`author_app_link_base_url` / `admin_app_link_base_url` + `/{id}`,
 * `.env.example`: `author-3dors.*` / `admin-3dors.*`).
 */
class Dors3AuthorLauncherTest {

    @Test
    fun `rozpoznaje poprawny link zatwierdzenia Author`() {
        assertTrue(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "https",
                host = "author-3dors.zrodlo-slowa.pl",
                path = "/3dors/approve/abc123",
            ),
        )
    }

    @Test
    fun `odrzuca link bez identyfikatora`() {
        assertFalse(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "https",
                host = "author-3dors.zrodlo-slowa.pl",
                path = "/3dors/approve/",
            ),
        )
    }

    @Test
    fun `odrzuca inna sciezke`() {
        assertFalse(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "https",
                host = "author-3dors.zrodlo-slowa.pl",
                path = "/articles/some-slug",
            ),
        )
    }

    @Test
    fun `odrzuca schemat inny niz https`() {
        assertFalse(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "http",
                host = "author-3dors.zrodlo-slowa.pl",
                path = "/3dors/approve/abc123",
            ),
        )
    }

    @Test
    fun `odrzuca brak sciezki`() {
        assertFalse(
            Dors3AuthorLauncher.isApprovalLink(scheme = "https", host = "author-3dors.zrodlo-slowa.pl", path = null),
        )
    }

    @Test
    fun `odrzuca link bez rozpoznanego hosta`() {
        assertFalse(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "https",
                host = "example.com",
                path = "/3dors/approve/abc123",
            ),
        )
    }

    @Test
    fun `nigdy nie rozpoznaje linku do 3DORS Admin jako Author`() {
        assertFalse(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "https",
                host = "admin-3dors.zrodlo-slowa.pl",
                path = "/3dors/approve/abc123",
            ),
        )
    }

    /**
     * Regresja luki bezpieczeństwa wykrytej w niezależnym audycie
     * (DYSPOZYCJA_NAPRAWCZA pkt 1, "dokładne hosty, nie wildcard/prefiks"):
     * wcześniejsza wersja dopuszczała `host.startsWith("author-3dors.")`,
     * więc phishingowy host "author-3dors.attacker.example" (obcy właściciel
     * domeny, jedynie zaczynający się od tego samego prefiksu) był błędnie
     * rozpoznawany jako prawdziwy link 3DORS Author.
     */
    @Test
    fun `odrzuca phishingowy host bedacy jedynie prefiksem prawdziwego hosta Author`() {
        assertFalse(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "https",
                host = "author-3dors.attacker.example",
                path = "/3dors/approve/abc123",
            ),
        )
    }

    @Test
    fun `odrzuca phishingowy host bedacy jedynie prefiksem prawdziwego hosta Admin`() {
        assertFalse(
            Dors3AuthorLauncher.isAdminLink(
                scheme = "https",
                host = "admin-3dors.attacker.example",
                path = "/3dors/approve/abc123",
            ),
        )
    }

    @Test
    fun `rozpoznaje jawny dev host Author z dyspozycji`() {
        assertTrue(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "https",
                host = "dors3-author-dev",
                path = "/3dors/approve/abc123",
            ),
        )
    }

    @Test
    fun `blokuje jawny dev host Admin z dyspozycji`() {
        assertTrue(
            Dors3AuthorLauncher.isAdminLink(
                scheme = "https",
                host = "dors3-admin-dev",
                path = "/3dors/approve/abc123",
            ),
        )
    }

    /**
     * Naprawa P0-1 z audytu: rzeczywisty kontrakt debug 3DORS
     * (`mobile/3dors-android`, `Dors3DeepLink.kt`) to CUSTOM SCHEME
     * `dors3-author-dev://approve/{id}`, nie link HTTPS z takim hostem.
     */
    @Test
    fun `rozpoznaje prawdziwy custom scheme Author z kontraktu 3DORS`() {
        assertTrue(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "dors3-author-dev",
                host = "approve",
                path = "/abc123",
            ),
        )
    }

    @Test
    fun `odrzuca custom scheme Author bez identyfikatora`() {
        assertFalse(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "dors3-author-dev",
                host = "approve",
                path = "/",
            ),
        )
    }

    @Test
    fun `blokuje prawdziwy custom scheme Admin z kontraktu 3DORS`() {
        assertTrue(
            Dors3AuthorLauncher.isAdminLink(
                scheme = "dors3-admin-dev",
                host = "approve",
                path = "/abc123",
            ),
        )
        assertFalse(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "dors3-admin-dev",
                host = "approve",
                path = "/abc123",
            ),
        )
    }

    @Test
    fun `nie myli hosta custom scheme z innym slowem`() {
        assertFalse(
            Dors3AuthorLauncher.isApprovalLink(
                scheme = "dors3-author-dev",
                host = "reject",
                path = "/abc123",
            ),
        )
    }
}
