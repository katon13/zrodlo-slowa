package pl.zrodloslowa.app.session

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import pl.zrodloslowa.app.ui.auth.sessionExpiryProbeDelayMillis

class WebSessionManagerTest {

    @Test
    fun parsesAuthenticatedCanonicalSession() {
        val state = parseMobileSessionResponse(
            """{"ok":true,"authenticated":true,"session":{"generation":"0123456789abcdef0123456789abcdef","version":7,"session_expires_at":1800000000},"user":{"id":42,"primary_role":"author","roles":["author"],"can_write":true,"wallet_enabled":true,"payout_enabled":false}}""",
        )

        assertTrue(state.authenticated)
        assertEquals(42L, state.userId)
        assertEquals("author", state.primaryRole)
        assertTrue(state.canWrite)
        assertTrue(state.walletEnabled)
        assertFalse(state.payoutEnabled)
        assertEquals(1_800_000_000L, state.sessionExpiresAt)
        assertEquals("42:0123456789abcdef0123456789abcdef:7", state.storageKey)
    }

    @Test
    fun parsesAnonymousSessionWithoutInventingIdentity() {
        val state = parseMobileSessionResponse(
            """{"ok":true,"authenticated":false,"session":null,"user":null}""",
        )

        assertEquals(MobileSessionStatus.ANONYMOUS, state.status)
        assertFalse(state.authenticated)
        assertNull(state.storageKey)
    }

    @Test
    fun rejectsMalformedOrUntrustedPayload() {
        val state = parseMobileSessionResponse(
            """{"ok":true,"authenticated":true,"session":{"generation":"bad","version":1},"user":{"id":1}}""",
        )

        assertEquals(MobileSessionStatus.UNAVAILABLE, state.status)
    }

    @Test
    fun rejectsAuthenticatedPayloadMissingBackendEntitlementsOrExpiry() {
        val state = parseMobileSessionResponse(
            """{"ok":true,"authenticated":true,"session":{"generation":"0123456789abcdef0123456789abcdef","version":1},"user":{"id":1,"primary_role":"reader","roles":["reader"],"can_write":false}}""",
        )

        assertEquals(MobileSessionStatus.UNAVAILABLE, state.status)
    }

    @Test
    fun schedulesCanonicalProbeAtBackendSessionExpiry() {
        assertEquals(10_250L, sessionExpiryProbeDelayMillis(1_010L, 1_000_000L))
        assertEquals(250L, sessionExpiryProbeDelayMillis(999L, 1_000_000L))
    }
}
