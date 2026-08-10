package pl.zrodloslowa.app.ui.notifications

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountBalanceWallet
import androidx.compose.material.icons.filled.Payments
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.automirrored.filled.TrendingUp
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.suspendCancellableCoroutine
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import pl.zrodloslowa.app.R
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.notifications.EarningsNotification
import pl.zrodloslowa.app.notifications.NotificationsApiBridge
import pl.zrodloslowa.app.notifications.NotificationsPage
import pl.zrodloslowa.app.session.WebSessionManager
import pl.zrodloslowa.app.ui.auth.AuthGate
import pl.zrodloslowa.app.webview.WebUrlResolver
import kotlin.coroutines.resume

private const val POLL_INTERVAL_MS = 20_000L

/**
 * Ekran Powiadomienia (ETAP 5 z dyspozycji): lista zdarzeń finansowych z
 * istniejącego, natywnego API `GET /api/earnings/notifications` (bez
 * jakichkolwiek zmian w backendzie), z cyklicznym odpytywaniem po
 * `next_cursor` oraz oznaczaniem jako przeczytane przez
 * `POST /api/earnings/notifications/ack`. Wymaga zalogowania — bramkowane
 * przez [AuthGate] (ETAP 2), tak jak Portfel i Konto.
 */
@Composable
fun NotificationsScreen(languageCode: String = "pl") {
    // Ten ekran nie osadza w\u0142asnego SecureWebView (dane pochodz\u0105 z API
    // powiadomie\u0144) \u2014 wykrycie wylogowania na stronie WWW jest tu wy\u0142\u0105czone.
    AuthGate(languageCode = languageCode) {
        NotificationsContent(languageCode = languageCode)
    }
}

@Composable
private fun NotificationsContent(languageCode: String) {
    val context = LocalContext.current
    val site = remember(languageCode) { SiteConfig.siteForLanguage(languageCode) }
    val baseUrl = remember(site) { WebUrlResolver.baseUrl(site) }
    val allSessions by WebSessionManager.sessions
    val sessionKey = allSessions[baseUrl.trimEnd('/')]?.storageKey ?: "anonymous"
    val lifecycleOwner = LocalLifecycleOwner.current

    // Stan i niewidoczny WebView są rozdzielone per domena, użytkownik i
    // generacja sesji. Po wylogowaniu lub ponownym zalogowaniu dane poprzedniej
    // osoby nie mogą pozostać w pamięci ekranu.
    val bridge = remember(baseUrl, sessionKey) { NotificationsApiBridge(context, baseUrl) }
    DisposableEffect(bridge) {
        onDispose { bridge.destroy() }
    }

    var items by remember(sessionKey) { mutableStateOf(listOf<EarningsNotification>()) }
    var afterId by remember(sessionKey) { mutableIntStateOf(0) }
    var loading by remember(sessionKey) { mutableStateOf(true) }
    var error by remember(sessionKey) { mutableStateOf(false) }

    // Polling działa wyłącznie, gdy ekran jest co najmniej STARTED. Każda
    // iteracja czeka na wynik albo timeout poprzedniego fetchu, więc nie ma
    // równoległych pobrań i nie rośnie nieograniczona kolejka callbacków.
    LaunchedEffect(bridge, lifecycleOwner) {
        lifecycleOwner.lifecycle.repeatOnLifecycle(Lifecycle.State.STARTED) {
            while (true) {
                val raw = suspendCancellableCoroutine { continuation ->
                    bridge.fetchNotifications(afterId = afterId, limit = 10) { response ->
                        if (continuation.isActive) continuation.resume(response)
                    }
                }
                val page = NotificationsPage.fromJsonText(raw)
                loading = false
                if (page.ok) {
                    error = false
                    if (page.items.isNotEmpty()) {
                        items = page.items.reversed() + items
                        afterId = maxOf(afterId, page.nextCursor)
                    }
                } else if (items.isEmpty()) {
                    error = true
                }
                delay(POLL_INTERVAL_MS)
            }
        }
    }

    Column(modifier = Modifier.fillMaxSize()) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Text(
                text = stringResource(R.string.nav_notifications),
                style = MaterialTheme.typography.titleLarge,
            )
            if (items.isNotEmpty()) {
                TextButton(onClick = {
                    val ids = items.map { it.id }
                    bridge.acknowledge(ids) { raw ->
                        val json = runCatching { org.json.JSONObject(raw) }.getOrNull()
                        if (json?.optBoolean("ok", false) == true) {
                            items = emptyList()
                        }
                    }
                }) {
                    Text(text = stringResource(R.string.notifications_mark_all_read))
                }
            }
        }

        when {
            loading && items.isEmpty() -> Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = MaterialTheme.colorScheme.primary)
            }
            error && items.isEmpty() -> Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text(
                    text = stringResource(R.string.notifications_error),
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            items.isEmpty() -> Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text(
                    text = stringResource(R.string.notifications_empty),
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            else -> LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(items = items, key = { it.id }) { item ->
                    NotificationCard(item)
                }
            }
        }
    }
}

@Composable
private fun NotificationCard(item: EarningsNotification) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(14.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant),
    ) {
        Row(
            modifier = Modifier.padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                modifier = Modifier
                    .size(40.dp)
                    .background(MaterialTheme.colorScheme.primary, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    imageVector = iconForActivityType(item.activityType),
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.onPrimary,
                )
            }
            Column(modifier = Modifier.padding(start = 12.dp)) {
                Text(text = item.title, style = MaterialTheme.typography.titleSmall)
                Text(
                    text = item.message,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                if (item.createdAt.isNotBlank()) {
                    Text(
                        text = item.createdAt,
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

private fun iconForActivityType(activityType: String) = when {
    activityType.contains("wallet") || activityType.contains("payout") -> Icons.Filled.AccountBalanceWallet
    activityType.contains("sale") || activityType.contains("support") -> Icons.Filled.Payments
    activityType.contains("bonus") || activityType.contains("talent") -> Icons.Filled.Star
    else -> Icons.AutoMirrored.Filled.TrendingUp
}
