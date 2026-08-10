package pl.zrodloslowa.mobile.variant

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class AdminVariantPolicyTest {
    @Test
    fun `admin accepts payout but does not compile author operation policy`() {
        assertTrue(VariantPolicy.accepts("payout.approve"))
        assertFalse(VariantPolicy.accepts("article.submit"))
    }
}
