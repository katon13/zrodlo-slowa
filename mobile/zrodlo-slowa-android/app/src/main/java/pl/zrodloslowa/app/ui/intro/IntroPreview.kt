package pl.zrodloslowa.app.ui.intro

import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.runtime.Composable

/**
 * Podglądy Compose czołówki dla kluczowych momentów storyboardu — początek
 * (cienka linia), połowa spływania znaku i finalne logo z nazwą i mottem.
 * Wyłącznie do podglądu w IDE, nie jest częścią przepływu aplikacji.
 */
@Preview(name = "Intro — początek", showBackground = true)
@Composable
private fun IntroPreviewStart() {
    SourceLogoAnimation(progress = 0.05f, motto = null)
}

@Preview(name = "Intro — spływanie Źródła", showBackground = true)
@Composable
private fun IntroPreviewStream() {
    SourceLogoAnimation(progress = 0.30f, motto = null)
}

@Preview(name = "Intro — finalne logo", showBackground = true)
@Composable
private fun IntroPreviewFinal() {
    SourceLogoAnimation(progress = 0.85f, motto = "Daj temu własne źródło.")
}
