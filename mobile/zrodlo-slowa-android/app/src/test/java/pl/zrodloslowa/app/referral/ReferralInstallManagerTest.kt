package pl.zrodloslowa.app.referral

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class ReferralInstallManagerTest {
    private val token = "A".repeat(43)

    @Test
    fun acceptsOnlyExactReferralLinksWithoutRewardAmount() {
        assertEquals(token, referralTokenFromUrl("https://zrodlo-slowa.pl/app/referral/$token"))
        assertEquals(token, referralTokenFromUrl("zrodloslowa://referral/$token"))
        assertNull(referralTokenFromUrl("https://evil.example/app/referral/$token"))
        assertNull(referralTokenFromUrl("https://zrodlo-slowa.pl/app/referral/${token}x"))
        assertNull(referralTokenFromUrl("https://zrodlo-slowa.pl/app/referral/$token/1000"))
    }
}
