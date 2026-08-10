package pl.zrodloslowa.mobile.demo

import androidx.navigation.NavGraphBuilder
import androidx.navigation.NavHostController

/**
 * Wariant release: tryb demo / Screen Gallery NIE ISTNIEJE w skompilowanym
 * kodzie (Effective Issue: "tryb demo wyłącznie debug"). Ta funkcja jest
 * celowym no-opem — utrzymuje ten sam kontrakt wywołania co w app/src/debug,
 * bez żadnych ekranów demonstracyjnych ani danych testowych w APK produkcyjnym.
 */
fun NavGraphBuilder.addDemoDestinations(navController: NavHostController) {
    // Celowo pusto — release nie zawiera trybu demo ani Screen Gallery.
}
