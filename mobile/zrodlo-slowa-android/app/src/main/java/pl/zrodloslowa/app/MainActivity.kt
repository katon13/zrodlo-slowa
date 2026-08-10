package pl.zrodloslowa.app

import android.os.Bundle
import android.content.Intent
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalView
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.core.view.WindowCompat
import pl.zrodloslowa.app.ui.intro.IntroLaunchGate
import pl.zrodloslowa.app.ui.intro.SourceIntroScreen
import pl.zrodloslowa.app.ui.navigation.ZrodloSlowaNavHost
import pl.zrodloslowa.app.ui.theme.ZrodloSlowaTheme
import pl.zrodloslowa.app.referral.ReferralInstallManager

/**
 * Aktywność hosta całej aplikacji (ETAP 1: architektura, menu, nawigacja,
 * powłoka). Kolejne etapy będą podłączać do tej powłoki: bezpieczny WebView
 * i sesję (ETAP 2), treści ekranów (ETAP 3+) oraz integrację 3DORS Author
 * (ETAP 7) — bez zmiany samej struktury nawigacji.
 *
 * Dyspozycja "Programowa czołówka" (2026-08-04): przed pierwszą treścią
 * pokazujemy natywną czołówkę [SourceIntroScreen] — ale wyłącznie przy
 * zimnym uruchomieniu procesu ([IntroLaunchGate], zasady działania,
 * pkt "nie pokazuj przy każdym powrocie z tła / z 3DORS Author"). Ponieważ
 * `MainActivity` nie jest tworzona na nowo przy powrocie z tła ani po
 * powrocie z 3DORS Author (ten sam proces/Activity), a [IntroLaunchGate]
 * jest ustawiany raz na cały proces, czołówka pojawi się tylko przy
 * pierwszym starcie po uruchomieniu procesu aplikacji.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        // Splash Screen API (androidx.core:core-splashscreen) MUSI być
        // wywołany PRZED super.onCreate() — dzięki temu system pokazuje
        // natywny splash z tłem @color/zs_intro_cream (patrz
        // Theme.ZrodloSlowa.Splash) zamiast domyślnego czarnego
        // windowBackground, aż do wyrenderowania pierwszej klatki Compose
        // (programowej czołówki). To eliminuje czarne mignięcie zgłoszone
        // w audycie — jedno płynne wejście: system splash → czołówka.
        installSplashScreen()
        super.onCreate(savedInstanceState)
        ReferralInstallManager.captureIntent(intent)

        setContent {
            ZrodloSlowaTheme {
                var showIntro by remember { mutableStateOf(IntroLaunchGate.shouldShowIntro()) }
                val view = LocalView.current
                SideEffect {
                    WindowCompat.getInsetsController(window, view).apply {
                        isAppearanceLightStatusBars = showIntro
                        isAppearanceLightNavigationBars = showIntro
                    }
                }
                if (showIntro) {
                    SourceIntroScreen(
                        onFinished = {
                            IntroLaunchGate.markIntroShown()
                            showIntro = false
                        },
                    )
                } else {
                    ZrodloSlowaNavHost()
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        ReferralInstallManager.captureIntent(intent)
    }
}
