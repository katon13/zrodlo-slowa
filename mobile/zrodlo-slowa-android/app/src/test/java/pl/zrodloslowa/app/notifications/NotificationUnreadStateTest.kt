package pl.zrodloslowa.app.notifications

import org.junit.Assert.assertEquals
import org.junit.Test

class NotificationUnreadStateTest {
    @Test
    fun badgeHidesZeroAndCapsValuesAboveNinetyNine() {
        assertEquals("", notificationBadgeText(0))
        assertEquals("1", notificationBadgeText(1))
        assertEquals("7", notificationBadgeText(7))
        assertEquals("99", notificationBadgeText(99))
        assertEquals("99+", notificationBadgeText(100))
    }

    @Test
    fun sharedStateUsesServerValueWithoutLocalIncrementing() {
        NotificationUnreadState.update(4)
        assertEquals(4, NotificationUnreadState.count)
        NotificationUnreadState.update(3)
        assertEquals(3, NotificationUnreadState.count)
        NotificationUnreadState.clear()
        assertEquals(0, NotificationUnreadState.count)
    }
}
