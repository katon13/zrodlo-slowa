package pl.zrodloslowa.app.navigation

import org.junit.Assert.assertEquals
import org.junit.Test

class AppDestinationTest {

    @Test
    fun `bottom bar contains all five required tabs in the requested order`() {
        assertEquals(
            listOf(
                AppDestination.HOME,
                AppDestination.ARTICLES,
                AppDestination.WALLET,
                AppDestination.NOTIFICATIONS,
                AppDestination.ACCOUNT,
            ),
            AppDestination.bottomBarItems,
        )
    }

    @Test
    fun `fromRoute resolves known route`() {
        assertEquals(AppDestination.WALLET, AppDestination.fromRoute("wallet"))
    }

    @Test
    fun `fromRoute falls back to home for unknown or null route`() {
        assertEquals(AppDestination.HOME, AppDestination.fromRoute(null))
        assertEquals(AppDestination.HOME, AppDestination.fromRoute("unknown"))
    }
}
