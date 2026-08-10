package pl.zrodloslowa.app.notifications

import android.graphics.Bitmap
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.ui.Modifier
import androidx.compose.ui.Alignment
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.io.File
import java.io.FileOutputStream
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import pl.zrodloslowa.app.navigation.AppDestination
import pl.zrodloslowa.app.ui.navigation.ZrodloSlowaBottomBar
import pl.zrodloslowa.app.ui.theme.ZrodloSlowaTheme

@RunWith(AndroidJUnit4::class)
class NotificationBadgeVisualTest {

    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun capturesRealBottomNavigationWithUnreadBadge(): Unit {
        composeRule.setContent {
            ZrodloSlowaTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    Box(modifier = Modifier.fillMaxSize()) {
                        Column(modifier = Modifier.padding(24.dp)) {
                            Text(
                                text = "Powiadomienia",
                                style = MaterialTheme.typography.headlineMedium,
                            )
                            Text(
                                text = "Nowe informacje z Programu Talent są zawsze pod ręką.",
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        Box(modifier = Modifier.align(Alignment.BottomCenter)) {
                            ZrodloSlowaBottomBar(
                                currentDestination = AppDestination.HOME,
                                unreadNotificationCount = 100,
                                onDestinationSelected = {},
                            )
                        }
                    }
                }
            }
        }

        composeRule.onNodeWithText("99+").assertIsDisplayed()
        val targetContext = InstrumentationRegistry.getInstrumentation().targetContext
        val output = File(
            targetContext.getExternalFilesDir(null) ?: targetContext.filesDir,
            "notification-badge-mobile.png",
        )
        FileOutputStream(output).use { stream ->
            assertTrue(composeRule.onRoot().captureToImage().asAndroidBitmap().compress(
                Bitmap.CompressFormat.PNG,
                100,
                stream,
            ))
        }
        println("SCREENSHOT_PATH=${output.absolutePath}")
    }
}
