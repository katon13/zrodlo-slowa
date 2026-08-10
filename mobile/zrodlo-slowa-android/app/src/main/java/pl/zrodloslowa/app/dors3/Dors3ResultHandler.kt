package pl.zrodloslowa.app.dors3

import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver

/**
 * ETAP 7 z dyspozycji (pkt 13): odbiór powrotu z 3DORS Author.
 *
 * 3DORS Author to osobna, istniejąca aplikacja — nie zwraca ona wyniku
 * przez `Activity Result` (brak takiego kontraktu w istniejącym systemie,
 * patrz `mobile/3dors-android`), a powłoka nie ma prawa go dodawać ("nie
 * zmienia kontraktu"). Jedynym bezpiecznym i zgodnym z audytem sposobem
 * wykrycia powrotu jest obserwacja cyklu życia ekranu: gdy użytkownik
 * wraca do Źródło Słowa Mobile po tym, jak powłoka otworzyła link
 * zatwierdzenia ([Dors3AuthorLauncher.isApprovalLink]), strona WWW jest
 * odświeżana, aby pokazać zaktualizowany, istniejący status z serwera.
 */
class Dors3PendingApproval {
    var isPending: Boolean = false
        private set

    fun markLaunched() {
        isPending = true
    }

    fun consumeIfPending(): Boolean {
        val wasPending = isPending
        isPending = false
        return wasPending
    }
}

@Composable
fun rememberDors3PendingApproval(): Dors3PendingApproval = remember { Dors3PendingApproval() }

/**
 * Rejestruje obserwatora `ON_RESUME`; przy powrocie do ekranu po otwarciu
 * 3DORS Author wywołuje [onReturnFromAuthor] (odświeżenie WebView).
 */
@Composable
fun Dors3ResultHandler(
    pendingApproval: Dors3PendingApproval,
    onReturnFromAuthor: () -> Unit,
) {
    val lifecycleOwner = LocalLifecycleOwner.current

    DisposableEffect(lifecycleOwner, pendingApproval) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME && pendingApproval.consumeIfPending()) {
                onReturnFromAuthor()
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose {
            lifecycleOwner.lifecycle.removeObserver(observer)
        }
    }
}
