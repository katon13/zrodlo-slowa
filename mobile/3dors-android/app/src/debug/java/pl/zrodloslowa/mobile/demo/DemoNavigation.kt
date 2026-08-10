package pl.zrodloslowa.mobile.demo

import androidx.navigation.NavGraphBuilder
import androidx.navigation.NavHostController
import androidx.navigation.compose.composable

/**
 * Rejestruje trasy trybu demo / Screen Gallery WYŁĄCZNIE w wariancie debug
 * (Effective Issue: "tryb demo wyłącznie debug", "Screen Gallery tylko debug").
 * Odpowiednik tej funkcji w app/src/release jest pustym no-opem — w buildzie
 * release te ekrany nigdy nie istnieją w skompilowanym kodzie.
 */
fun NavGraphBuilder.addDemoDestinations(navController: NavHostController) {
    composable("demo_gallery") {
        ScreenGalleryScreen(
            scenarios = DemoScenario.entries,
            onScenarioClick = { scenario -> navController.navigate("demo_screen/${scenario.name}") },
        )
    }
    composable("demo_screen/{scenario}") { backStackEntry ->
        val scenarioName = backStackEntry.arguments?.getString("scenario")
        val scenario = DemoScenario.entries.firstOrNull { it.name == scenarioName } ?: DemoScenario.LOGIN
        DemoScreenHost(scenario = scenario, onBack = { navController.popBackStack() })
    }
}
