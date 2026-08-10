package pl.zrodloslowa.app.ui.intro

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Testy czołówki (naprawa błędu z audytu: "wyłączone animacje pokazują
 * puste tło"). [IntroTiming.FADE_OUT] to faza wygaszająca — przy
 * `progress == FADE_OUT.start` (wartość używana jako statyczny stan przy
 * wyłączonych animacjach systemowych, patrz [SourceIntroScreen]) faza
 * wygaszenia NIE MOŻE jeszcze zakrywać logo, a wszystkie wcześniejsze fazy
 * (linia, znak, logo, nazwa, motto) muszą być w pełni zakończone (postęp 1f).
 */
class IntroTimingTest {

    @Test
    fun `faza wygaszenia jest zerowa na starcie statycznego stanu bez animacji`() {
        val staticProgress = IntroTiming.FADE_OUT.start
        val fadeProgress = IntroTiming.phaseProgress(staticProgress, IntroTiming.FADE_OUT)
        assertEquals(0f, fadeProgress, 0.0001f)
    }

    @Test
    fun `faza wygaszenia w progresie 1f jest pelna (regresja bledu z audytu)`() {
        // To jest dokładnie błąd zgłoszony w audycie: przy progress = 1f
        // (poprzednia, błędna wartość statyczna) faza FADE_OUT jest w pełni
        // ukończona i rysuje kryjące tło na całym ekranie.
        val fadeProgress = IntroTiming.phaseProgress(1f, IntroTiming.FADE_OUT)
        assertEquals(1f, fadeProgress, 0.0001f)
    }

    @Test
    fun `wszystkie wczesniejsze fazy sa zakonczone w statycznym stanie`() {
        val staticProgress = IntroTiming.FADE_OUT.start
        assertEquals(1f, IntroTiming.phaseProgress(staticProgress, IntroTiming.LINE), 0.0001f)
        assertEquals(1f, IntroTiming.phaseProgress(staticProgress, IntroTiming.STREAM), 0.0001f)
        assertEquals(1f, IntroTiming.phaseProgress(staticProgress, IntroTiming.LOGO_FORM), 0.0001f)
        assertEquals(1f, IntroTiming.phaseProgress(staticProgress, IntroTiming.WORDMARK), 0.0001f)
        assertEquals(1f, IntroTiming.phaseProgress(staticProgress, IntroTiming.MOTTO), 0.0001f)
    }

    @Test
    fun `phaseProgress jest zawsze w zakresie od zera do jednego`() {
        for (i in 0..20) {
            val progress = i / 20f
            assertTrue(IntroTiming.phaseProgress(progress, IntroTiming.FADE_OUT) in 0f..1f)
        }
    }
}
