package pl.zrodloslowa.app.notifications

import org.json.JSONArray
import org.json.JSONObject

/**
 * Powiadomienie finansowe (ETAP 5 z dyspozycji), 1:1 z odpowiedzią
 * `GET /api/earnings/notifications` (`EarningsApiController::notifications`).
 * Parsowanie jest celowo tolerancyjne — pole spoza kontraktu jest ignorowane,
 * a brakujące pole tekstowe zamieniane na pusty ciąg, zamiast wyjątku.
 */
data class EarningsNotification(
    val id: Int,
    val activityType: String,
    val pointsAmount: Int,
    val amountMinor: Int,
    val title: String,
    val message: String,
    val icon: String,
    val createdAt: String,
) {
    companion object {
        fun fromJson(json: JSONObject): EarningsNotification = EarningsNotification(
            id = json.optInt("id"),
            activityType = json.optString("activity_type"),
            pointsAmount = json.optInt("points_amount"),
            amountMinor = json.optInt("amount_minor"),
            title = json.optString("title"),
            message = json.optString("message"),
            icon = json.optString("icon"),
            createdAt = json.optString("created_at"),
        )
    }
}

/** Wynik `GET /api/earnings/notifications` (`items` + `next_cursor` z kontraktu backendu). */
data class NotificationsPage(
    val ok: Boolean,
    val items: List<EarningsNotification>,
    val nextCursor: Int,
    val reason: String? = null,
) {
    companion object {
        fun fromJsonText(text: String): NotificationsPage = runCatching {
            val json = JSONObject(text)
            val ok = json.optBoolean("ok", false)
            if (!ok) {
                return NotificationsPage(ok = false, items = emptyList(), nextCursor = 0, reason = json.optString("reason"))
            }
            val rawItems: JSONArray = json.optJSONArray("items") ?: JSONArray()
            val items = (0 until rawItems.length()).map { index -> EarningsNotification.fromJson(rawItems.getJSONObject(index)) }
            NotificationsPage(ok = true, items = items, nextCursor = json.optInt("next_cursor"))
        }.getOrDefault(NotificationsPage(ok = false, items = emptyList(), nextCursor = 0, reason = "parse_error"))
    }
}
