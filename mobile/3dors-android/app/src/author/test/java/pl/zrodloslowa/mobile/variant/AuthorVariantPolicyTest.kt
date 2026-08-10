package pl.zrodloslowa.mobile.variant

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class AuthorVariantPolicyTest {
    @Test
    fun `author accepts article submit and rejects administrative payout`() {
        assertTrue(VariantPolicy.accepts("article.submit"))
        assertFalse(VariantPolicy.accepts("payout.approve"))
    }
}
