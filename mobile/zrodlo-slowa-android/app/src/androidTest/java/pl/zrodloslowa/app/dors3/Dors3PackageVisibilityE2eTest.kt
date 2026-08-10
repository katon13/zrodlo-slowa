package pl.zrodloslowa.app.dors3

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.view.KeyEvent
import androidx.lifecycle.Lifecycle
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Assume.assumeTrue
import org.junit.Test
import org.junit.runner.RunWith
import pl.zrodloslowa.app.MainActivity
import pl.zrodloslowa.app.webview.openAuthorApp
import java.io.FileInputStream

@RunWith(AndroidJUnit4::class)
class Dors3PackageVisibilityE2eTest {

    @Suppress("DEPRECATION")
    @Test
    fun sourceSeesAuthorForwardsExactRequestAndReturns() {
        assumeTrue("Package visibility is relevant on Android 11+.", Build.VERSION.SDK_INT >= Build.VERSION_CODES.R)

        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val context = instrumentation.targetContext
        val authorPackage = Dors3AuthorLauncher.AUTHOR_PACKAGE_NAME

        val authorInstalledForSystem = shell("pm path $authorPackage").contains("package:")
        assumeTrue("Install the 3DORS Author debug APK before this integration test.", authorInstalledForSystem)

        val packageInfo = context.packageManager.getPackageInfo(authorPackage, 0)
        assertEquals(authorPackage, packageInfo.packageName)

        val requestId = "package-visibility-e2e-${System.currentTimeMillis()}"
        val requestUri = Uri.parse("dors3-author-dev://approve/$requestId")
        val authorIntent = Intent(Intent.ACTION_VIEW, requestUri).setPackage(authorPackage)
        val resolved = authorIntent.resolveActivity(context.packageManager)
        assertNotNull("The installed Author app must be visible to resolveActivity().", resolved)
        assertEquals(authorPackage, resolved?.packageName)

        val sourceIntent = Intent(context, MainActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
        }
        val sourceActivity = instrumentation.startActivitySync(sourceIntent) as MainActivity
        instrumentation.waitForIdleSync()

        val pendingApproval = Dors3PendingApproval()
        assertTrue(openAuthorApp(sourceActivity, requestUri))
        pendingApproval.markLaunched()

        assertTrue("3DORS Author did not become the resumed application.", waitForTopPackage(authorPackage))
        val authorState = shell("dumpsys activity activities")
        assertTrue("3DORS Author did not receive the expected request URI.", authorState.contains(requestId))

        instrumentation.sendKeyDownUpSync(KeyEvent.KEYCODE_BACK)
        assertTrue("Source of Word did not resume after returning from Author.", waitForTopPackage(context.packageName))
        instrumentation.waitForIdleSync()
        assertTrue(sourceActivity.lifecycle.currentState.isAtLeast(Lifecycle.State.RESUMED))
        assertTrue("The pending approval must be consumable on return so WebView can refresh.", pendingApproval.consumeIfPending())

        instrumentation.runOnMainSync { sourceActivity.finish() }
    }

    private fun waitForTopPackage(packageName: String, timeoutMillis: Long = 10_000L): Boolean {
        val deadline = System.currentTimeMillis() + timeoutMillis
        while (System.currentTimeMillis() < deadline) {
            val activities = shell("dumpsys activity activities")
            if (activities.lineSequence().any { line ->
                    line.contains("topResumedActivity=") && line.contains(packageName)
                }
            ) {
                return true
            }
            Thread.sleep(100L)
        }
        return false
    }

    private fun shell(command: String): String {
        val descriptor = InstrumentationRegistry.getInstrumentation().uiAutomation.executeShellCommand(command)
        return try {
            FileInputStream(descriptor.fileDescriptor).bufferedReader().use { it.readText() }
        } finally {
            descriptor.close()
        }
    }
}
