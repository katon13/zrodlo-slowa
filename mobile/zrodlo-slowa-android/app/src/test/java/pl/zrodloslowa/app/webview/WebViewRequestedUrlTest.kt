package pl.zrodloslowa.app.webview

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class WebViewRequestedUrlTest {

    @Test
    fun recompositionDoesNotResetInternalNavigation() {
        val requestedByCompose = "https://zrodlo-slowa.pl/articles"
        val currentInsideWebView = "https://zrodlo-slowa.pl/articles/123"

        assertFalse(shouldLoadRequestedUrl(requestedByCompose, requestedByCompose))
        assertTrue(currentInsideWebView != requestedByCompose)
    }

    @Test
    fun realChangeOfComposeParameterRequestsNavigation() {
        assertTrue(
            shouldLoadRequestedUrl(
                "https://zrodlo-slowa.pl/articles",
                "https://zrodlo-slowa.pl/wallet",
            ),
        )
    }
}
