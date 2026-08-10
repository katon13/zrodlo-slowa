package pl.zrodloslowa.mobile.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.layout.fillMaxSize

/**
 * Paleta marki Źródło Słowa dostosowana do dark mode (dyspozycja pkt 3.1, 4).
 * Czerwień pozostaje jedynym akcentem koloru, ale nie jest jedynym nośnikiem
 * znaczenia (stany mają też ikony/etykiety tekstowe — patrz ekrany approval).
 */
private val Dors3Red = Color(0xFFB90012)
private val Dors3RedDark = Color(0xFF8F0010)
private val Dors3Black = Color(0xFF0B0B0B)
private val Dors3Surface = Color(0xFF1A1A1A)
private val Dors3SurfaceVariant = Color(0xFF262626)
private val Dors3Outline = Color(0xFF3A3A3A)
private val Dors3TextSecondary = Color(0xFFB3B3B3)
private val Dors3Success = Color(0xFF4CAF6D)
private val Dors3Warning = Color(0xFFC98A2C)
private val Dors3Error = Color(0xFFE5484D)
private val Dors3InteractiveRed = Color(0xFFFF3348)

private val DarkColors = darkColorScheme(
    primary = Dors3Red,
    onPrimary = Color.White,
    primaryContainer = Dors3RedDark,
    onPrimaryContainer = Color.White,
    secondary = Dors3TextSecondary,
    onSecondary = Dors3Black,
    background = Dors3Black,
    onBackground = Color.White,
    surface = Dors3Surface,
    onSurface = Color.White,
    surfaceVariant = Dors3SurfaceVariant,
    onSurfaceVariant = Dors3TextSecondary,
    outline = Dors3Outline,
    error = Dors3Error,
    onError = Color.White,
)

val Dors3SuccessColor = Dors3Success
val Dors3WarningColor = Dors3Warning
val Dors3InteractiveRedColor = Dors3InteractiveRed

/**
 * Aplikacja działa domyślnie w dark mode (dyspozycja pkt 4) — w tym etapie nie
 * budujemy przełącznika jasny/ciemny, dlatego motyw jest zawsze ciemny,
 * niezależnie od ustawień systemowych.
 */
@Composable
fun Dors3MobileTheme(
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = DarkColors,
    ) {
        // MaterialTheme udostępnia paletę, ale sam nie ustawia tła ani
        // LocalContentColor. Surface jest celowo wspólny dla całej aplikacji,
        // aby każdy ekran dziedziczył biały tekst na czarnym tle także wtedy,
        // gdy nie deklaruje własnego koloru.
        Surface(
            modifier = Modifier.fillMaxSize(),
            color = MaterialTheme.colorScheme.background,
            contentColor = MaterialTheme.colorScheme.onBackground,
            content = content,
        )
    }
}
