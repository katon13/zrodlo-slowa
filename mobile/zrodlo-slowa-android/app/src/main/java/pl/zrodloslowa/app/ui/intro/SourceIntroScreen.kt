package pl.zrodloslowa.app.ui.intro

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import pl.zrodloslowa.app.R
import pl.zrodloslowa.app.config.AppLanguageManager
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.config.ZrodloSlowaSite
import android.provider.Settings

/**
 * Programowa czołówka "Źródło Słowa" (dyspozycja z 2026-08-04) — wyłącznie
 * natywna animacja Compose/Canvas (bez MP4/GIF/Lottie/animowanego WebView).
 *
 * Sterowana jednym stanem czasu [Animatable] (0f..1f, [IntroTiming.TOTAL_DURATION_MS]);
 * po zakończeniu wywołuje [onFinished]. Jeśli użytkownik wyłączył animacje
 * systemowe (pkt "Zasady działania"), pokazujemy od razu statyczne logo
 * (progres = 1f) i przechodzimy dalej niemal natychmiast, bez pełnej
 * animacji 2,2 s.
 */
@Composable
fun SourceIntroScreen(
    onFinished: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val animationsEnabled = remember(context) { areSystemAnimationsEnabled(context) }
    // Uwaga: gdy animacje są wyłączone NIE ustawiamy progresu na 1f — faza
    // FADE_OUT przy progresie 1f rysuje pełne, kryjące tło (przejście do
    // kolejnego ekranu) i całkowicie zakryłaby logo. Zamiast tego zatrzymujemy
    // się na końcu fazy MOTTO (tuż przed FADE_OUT), czyli w pełni uformowane,
    // statyczne logo + nazwa + motto, bez jakiegokolwiek wygaszenia.
    val staticProgress = IntroTiming.FADE_OUT.start
    val progressState = remember { Animatable(if (animationsEnabled) 0f else staticProgress) }
    val motto = stringResource(R.string.intro_motto)

    // Decyzja dot. marki w czołówce: aplikacja NIE ma jednej stałej nazwy
    // wpisanej na trwałe — tak samo jak nagłówek (AppTopBar) i cała reszta
    // powłoki, czołówka pokazuje nazwę marki właściwą dla aktualnie wybranej
    // wersji językowej/domenowej z [SiteConfig] (efektywny wybór: ostatni
    // jawny wybór użytkownika, a w przeciwnym razie język systemu — patrz
    // [AppLanguageManager.resolveEffectiveLanguageCode]). To pozostaje jedna
    // marka koncepcyjnie ("Źródło Słowa"), tylko z lokalnym wordmarkiem —
    // zgodnie z tym, jak marka jest już traktowana w SiteConfig.brandName.
    val languageCode = remember(context) { AppLanguageManager.resolveEffectiveLanguageCode(context) }
    val activeSite = remember(languageCode) { SiteConfig.siteForLanguage(languageCode) }
    val wordmarkLines = remember(activeSite) { introWordmarkLines(activeSite) }

    LaunchedEffect(animationsEnabled) {
        if (animationsEnabled) {
            progressState.animateTo(
                targetValue = 1f,
                animationSpec = tween(
                    durationMillis = IntroTiming.TOTAL_DURATION_MS,
                    easing = LinearEasing,
                ),
            )
        } else {
            // Statyczne logo — bardzo krótka pauza, aby zdążyć przygotować
            // WebView/sesję równolegle, ale bez opóźniania wejścia do aplikacji.
            kotlinx.coroutines.delay(150)
        }
        onFinished()
    }

    SourceLogoAnimation(
        progress = progressState.value,
        motto = motto,
        modifier = modifier.fillMaxSize(),
        wordmarkLines = wordmarkLines,
    )
}

/**
 * Intro używa dokładnie tych samych, jawnie kontrolowanych dwóch wierszy co
 * AppTopBar. Nie dzieli automatycznie pełnej nazwy, więc Android nie może
 * utworzyć innego łamania niż oficjalnie przyjęte dla danej wersji językowej.
 */
internal fun introWordmarkLines(site: ZrodloSlowaSite): List<String> =
    listOf(site.wordmarkLine1, site.wordmarkLine2)

/**
 * Czy animator systemowy jest włączony ("Ustawienia > Ułatwienia dostępu >
 * Skala animacji"). Gdy wyłączony (skala 0), zasady działania wymagają
 * pokazania od razu statycznego logo — nigdy pełnej animacji 2,2 s.
 */
private fun areSystemAnimationsEnabled(context: android.content.Context): Boolean {
    val scale = Settings.Global.getFloat(context.contentResolver, Settings.Global.ANIMATOR_DURATION_SCALE, 1f)
    return scale > 0f
}
