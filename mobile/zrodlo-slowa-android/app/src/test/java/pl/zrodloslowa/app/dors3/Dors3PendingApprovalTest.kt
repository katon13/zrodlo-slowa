package pl.zrodloslowa.app.dors3

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class Dors3PendingApprovalTest {

    @Test
    fun pendingApprovalIsConsumedExactlyOnceAfterReturn() {
        val pending = Dors3PendingApproval()

        assertFalse(pending.consumeIfPending())
        pending.markLaunched()
        assertTrue(pending.consumeIfPending())
        assertFalse(pending.consumeIfPending())
    }
}
