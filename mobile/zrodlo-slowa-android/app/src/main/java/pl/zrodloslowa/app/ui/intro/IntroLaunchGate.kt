package pl.zrodloslowa.app.ui.intro

import java.util.concurrent.atomic.AtomicBoolean

/**
 * Zasady działania czołówki (dyspozycja z 2026-08-04): pokazujemy ją
 * wyłącznie przy zimnym uruchomieniu aplikacji — nigdy przy powrocie z tła
 * i nigdy po powrocie z 3DORS Author.
 *
 * Prosty, jednorazowy w pamięci procesu wyłącznik: proces Android jest
 * tworzony na nowo wyłącznie przy prawdziwym zimnym starcie (uruchomienie
 * po zabiciu/restarcie procesu), a powrót z tła oraz powrót z 3DORS Author
 * (osobna aplikacja/Activity otwierana przez link, bez zabijania procesu
 * `pl.zrodloslowa.app`) nie tworzą nowego procesu — dzięki temu jedna
 * flaga [AtomicBoolean] na cały czas życia procesu jest wystarczająca i nie
 * wymaga zapisu żadnych danych użytkownika na dysku (pkt "nie zapisuj
 * żadnych danych użytkownika w komponencie intro").
 */
object IntroLaunchGate {

    private val introShown = AtomicBoolean(false)

    /** `true` tylko przy pierwszym wywołaniu w cyklu życia procesu. */
    fun shouldShowIntro(): Boolean = !introShown.get()

    fun markIntroShown() {
        introShown.set(true)
    }
}
