package pl.zrodloslowa.mobile

import android.content.Intent
import android.net.Uri
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class AuthorDeepLinkReceiptTest {

    @Test
    fun authorReceivesExactRequestFromSourceOfWord() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val expected = Uri.parse("dors3-author-dev://approve/package-visibility-author-receipt")
        val intent = Intent(Intent.ACTION_VIEW, expected).apply {
            setClass(instrumentation.targetContext, MainActivity::class.java)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
        }

        val activity = instrumentation.startActivitySync(intent) as MainActivity
        instrumentation.waitForIdleSync()

        assertEquals(expected, activity.intent.data)
        instrumentation.runOnMainSync { activity.finish() }
    }
}
