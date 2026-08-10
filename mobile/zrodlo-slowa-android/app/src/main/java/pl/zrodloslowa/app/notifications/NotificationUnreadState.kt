package pl.zrodloslowa.app.notifications

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue

/**
 * Jedna prezentacja backendowego `unread_count` dla całej aplikacji.
 * Nie nalicza nic lokalnie i po zmianie sesji jest zerowana.
 */
object NotificationUnreadState {
    var count by mutableIntStateOf(0)
        private set

    fun update(serverCount: Int) {
        count = serverCount.coerceAtLeast(0)
    }

    fun clear() {
        count = 0
    }
}

fun notificationBadgeText(unreadCount: Int): String = when {
    unreadCount <= 0 -> ""
    unreadCount > 99 -> "99+"
    else -> unreadCount.toString()
}
