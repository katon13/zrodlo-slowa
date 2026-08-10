package pl.zrodloslowa.app.ui.theme

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color

/**
 * Paleta marki Źródło Słowa (ETAP 1: architektura, menu, nawigacja).
 * Zgodnie z dostarczoną makietą wizualną i stylem istniejącej aplikacji
 * 3DORS Mobile: ciemne tło, czerwony akcent jako jedyny kolor marki.
 * Aplikacja działa domyślnie w dark mode — bez przełącznika jasny/ciemny.
 */
private val ZsRed = Color(0xFFB90012)
private val ZsRedDark = Color(0xFF8F0010)
private val ZsBlack = Color(0xFF0B0B0B)
private val ZsSurface = Color(0xFF1A1A1A)
private val ZsSurfaceVariant = Color(0xFF262626)
private val ZsOutline = Color(0xFF3A3A3A)
private val ZsTextSecondary = Color(0xFFB3B3B3)
private val ZsError = Color(0xFFE5484D)

private val DarkColors = darkColorScheme(
    primary = ZsRed,
    onPrimary = Color.White,
    primaryContainer = ZsRedDark,
    onPrimaryContainer = Color.White,
    secondary = ZsTextSecondary,
    onSecondary = ZsBlack,
    background = ZsBlack,
    onBackground = Color.White,
    surface = ZsSurface,
    onSurface = Color.White,
    surfaceVariant = ZsSurfaceVariant,
    onSurfaceVariant = ZsTextSecondary,
    outline = ZsOutline,
    error = ZsError,
    onError = Color.White,
)

@Composable
fun ZrodloSlowaTheme(
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = DarkColors,
    ) {
        Surface(
            modifier = Modifier.fillMaxSize(),
            color = MaterialTheme.colorScheme.background,
            contentColor = MaterialTheme.colorScheme.onBackground,
            content = content,
        )
    }
}
