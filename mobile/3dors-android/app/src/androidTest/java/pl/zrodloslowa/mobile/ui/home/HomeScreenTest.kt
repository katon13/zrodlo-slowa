package pl.zrodloslowa.mobile.ui.home

import androidx.annotation.StringRes
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.graphics.toPixelMap
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import pl.zrodloslowa.mobile.R
import pl.zrodloslowa.mobile.ui.theme.Dors3MobileTheme

/**
 * Basic start-screen UI test. Visible text is resolved from the active Android
 * locale so the same test validates both the Polish default and English UI.
 */
class HomeScreenTest {

    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun pokazujeStanOczekiwaniaNaZadanie() {
        composeRule.setContent {
            Dors3MobileTheme {
                HomeScreen(
                    state = HomeUiState.Searching,
                    onScanQrClick = {},
                    onRegisterDeviceClick = {},
                )
            }
        }

        composeRule.onNodeWithText(visibleText(R.string.product_label)).assertExists()
        composeRule.onNodeWithText(visibleText(R.string.home_waiting_for_request)).assertExists()
        composeRule.onNodeWithText(visibleText(R.string.home_scan_qr)).assertExists()
    }

    @Test
    fun pokazujePrzyciskRejestracjiGdyUrzadzenieNiezarejestrowane() {
        composeRule.setContent {
            Dors3MobileTheme {
                HomeScreen(
                    state = HomeUiState.NotRegistered,
                    onScanQrClick = {},
                    onRegisterDeviceClick = {},
                )
            }
        }

        composeRule.onNodeWithText(visibleText(R.string.home_register_device)).assertExists()
        assertHasLightTextOnDarkBackground(
            composeRule.onNodeWithText(visibleText(R.string.product_label)),
        )
        assertHasLightTextOnDarkBackground(
            composeRule.onNodeWithText(visibleText(R.string.home_device_not_registered)),
        )
    }

    private fun assertHasLightTextOnDarkBackground(node: SemanticsNodeInteraction) {
        val pixels = node.captureToImage().toPixelMap()
        var lightPixels = 0
        var darkPixels = 0

        for (x in 0 until pixels.width) {
            for (y in 0 until pixels.height) {
                val luminance = pixels[x, y].luminance()
                if (luminance >= 0.45f) lightPixels++
                if (luminance <= 0.02f) darkPixels++
            }
        }

        assertTrue("Text should contain light pixels.", lightPixels >= 10)
        assertTrue("Text background should remain dark.", darkPixels >= 10)
    }

    private fun visibleText(@StringRes resourceId: Int): String =
        InstrumentationRegistry.getInstrumentation().targetContext.getString(resourceId)
}
