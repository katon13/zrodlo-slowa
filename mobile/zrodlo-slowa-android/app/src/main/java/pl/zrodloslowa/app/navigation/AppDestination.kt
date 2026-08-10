package pl.zrodloslowa.app.navigation

import androidx.annotation.StringRes
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material.icons.filled.Article
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Wallet
import androidx.compose.ui.graphics.vector.ImageVector
import pl.zrodloslowa.app.R

/**
 * Stałe dolne menu nawigacyjne aplikacji "Źródło Słowa Mobile" (ETAP 1).
 * Kolejność i zestaw zakładek wynikają z dyspozycji (ETAPY 3-6): Główna,
 * Artykuły, Portfel, Powiadomienia, Konto. Każda zakładka to osobna trasa
 * hosta nawigacji — treść poszczególnych ekranów zostanie uzupełniona w
 * kolejnych etapach (obecnie placeholdery).
 */
enum class AppDestination(
    val route: String,
    @StringRes val labelRes: Int,
    val icon: ImageVector,
) {
    HOME(route = "home", labelRes = R.string.nav_home, icon = Icons.Filled.Home),
    ARTICLES(route = "articles", labelRes = R.string.nav_articles, icon = Icons.Filled.Article),
    WALLET(route = "wallet", labelRes = R.string.nav_wallet, icon = Icons.Filled.Wallet),
    NOTIFICATIONS(route = "notifications", labelRes = R.string.nav_notifications, icon = Icons.Filled.Notifications),
    ACCOUNT(route = "account", labelRes = R.string.nav_account, icon = Icons.Filled.AccountCircle),
    ;

    companion object {
        val bottomBarItems: List<AppDestination> = entries.toList()

        fun fromRoute(route: String?): AppDestination = entries.firstOrNull { it.route == route } ?: HOME
    }
}
