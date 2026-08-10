package pl.zrodloslowa.app.ui.intro

/**
 * Programowa czołówka "Źródło Słowa" — dyspozycja z 2026-08-04.
 *
 * Jeden, wspólny stan czasu animacji (0f..1f, znormalizowany do całkowitego
 * czasu trwania czołówki [TOTAL_DURATION_MS]) — zamiast wielu niezależnych
 * opóźnień rozsianych po kodzie. Wszystkie fazy (linia, spływanie znaku,
 * powstanie logo, nazwa, motto, wygaszenie) czytają swój postęp z tego
 * samego `progress` przez [phaseProgress].
 */
object IntroTiming {
    const val TOTAL_DURATION_MS: Int = 2200

    /** 0,00–0,35 s — cienka czerwona linia u góry po prawej. */
    val LINE = 0.00f..0.16f

    /** 0,25–0,95 s — pionowa sekwencja elementów znaku spływa z góry. */
    val STREAM = 0.11f..0.43f

    /** 0,85–1,30 s — scalenie w logo (czerwony kwadrat + biały znak). */
    val LOGO_FORM = 0.39f..0.59f

    /** 1,15–1,70 s — wejście napisu "ŹRÓDŁO SŁOWA". */
    val WORDMARK = 0.52f..0.77f

    /** 1,60–1,95 s — motto pod logo. */
    val MOTTO = 0.73f..0.89f

    /** 1,95–2,20 s — wygaszenie / przejście do pierwszego ekranu. */
    val FADE_OUT = 0.89f..1.00f

    /**
     * Zwraca postęp (0f..1f) danej fazy [range] dla globalnego [progress]
     * (0f..1f), z płynnym wygaszeniem poza zakresem.
     */
    fun phaseProgress(progress: Float, range: ClosedFloatingPointRange<Float>): Float {
        val span = range.endInclusive - range.start
        if (span <= 0f) return 0f
        val local = (progress - range.start) / span
        return local.coerceIn(0f, 1f)
    }
}
