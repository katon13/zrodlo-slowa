package pl.zrodloslowa.app.notifications

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.webkit.WebSettings
import android.webkit.WebView
import androidx.webkit.WebViewCompat
import androidx.webkit.WebViewFeature
import pl.zrodloslowa.app.session.WebSessionManager
import pl.zrodloslowa.app.webview.SecureWebViewClient
import java.util.concurrent.atomic.AtomicInteger

/**
 * Klient API powiadomień finansowych (ETAP 5 z dyspozycji), oparty o
 * niewidoczny [WebView] wskazujący serwis danej wersji językowej. Zapytania
 * `fetch()` do `/api/earnings/notifications*` wykonuje silnik WebView, więc
 * cookie sesji jest dołączane automatycznie przez system — zgodnie z zasadą
 * z ETAPU 2, że aplikacja nigdy nie odczytuje ani nie kopiuje wartości
 * cookie ręcznie w kodzie natywnym.
 *
 * Naprawa błędów z audytu:
 * - "Powiadomienia mają możliwy konflikt wywołań": zamiast jednego
 *   [pendingCallback] nadpisywanego przez równoległe żądania (polling +
 *   ack mogące się nałożyć), każde wywołanie ma unikalny identyfikator
 *   ([pendingCallbacks]) — wynik trafia do właściwego callbacku, niezależnie
 *   od tego, ile żądań jest w toku jednocześnie;
 * - "Ukryty WebView powiadomień... bez tej samej kontroli hostów i TLS":
 *   `webViewClient` to teraz ten sam [SecureWebViewClient] (allowlista
 *   hostów + blokowanie błędów TLS) co główny [pl.zrodloslowa.app.webview.SecureWebView].
 * - "Usuń `addJavascriptInterface` z powiadomień" (niezależny audyt
 *   bezpieczeństwa, DYSPOZYCJA_NAPRAWCZA pkt 3): `addJavascriptInterface`
 *   udostępnia natywną metodę refleksyjnie KAŻDEJ ramce strony (w tym
 *   ewentualnym osadzonym `iframe` innego pochodzenia), bez sprawdzenia
 *   pochodzenia wywołującego skryptu. Zastąpione przez
 *   `WebViewCompat.addWebMessageListener` ograniczony jawnie do
 *   [allowedOriginRules] (dokładny origin `baseUrl`) — natywny listener
 *   dodatkowo odrzuca wiadomości spoza głównej ramki i spoza tego origin.
 *   Na urządzeniach bez wsparcia dla tej funkcji WebView (rzadkie, stare
 *   wersje Chromium) powiadomienia są świadomie WYŁĄCZONE zamiast wracać do
 *   niebezpiecznego `addJavascriptInterface` — patrz [isSupported].
 */
class NotificationsApiBridge(context: Context, private val baseUrl: String) {

    private data class PendingCallback(val callback: (String) -> Unit, val timeout: Runnable)
    private data class PendingCall(val invoke: () -> Unit, val timeout: Runnable)

    private val pendingCallbacks = mutableMapOf<Int, PendingCallback>()
    private val nextCallId = AtomicInteger(0)
    private var ready = false
    private val pendingCalls = mutableListOf<PendingCall>()
    private var fetchInFlight = false
    private var acknowledgeInFlight = false

    /** `true`, gdy WebView urządzenia wspiera bezpieczny, ograniczony origin'em kanał JS<->natywny. */
    val isSupported: Boolean = WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)

    private val originRule: String = runCatching { java.net.URI(baseUrl) }.getOrNull()
        ?.let { uri -> "${uri.scheme}://${uri.host}${if (uri.port != -1) ":${uri.port}" else ""}" }
        ?: baseUrl

    private val webView: WebView = WebView(context).apply {
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = false
        settings.cacheMode = WebSettings.LOAD_NO_CACHE
        settings.allowFileAccess = false
        settings.allowContentAccess = false
        settings.allowFileAccessFromFileURLs = false
        settings.allowUniversalAccessFromFileURLs = false
        settings.javaScriptCanOpenWindowsAutomatically = false
        settings.setGeolocationEnabled(false)
        settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
        settings.safeBrowsingEnabled = true
        WebSessionManager.attach(this)
        if (isSupported) {
            WebViewCompat.addWebMessageListener(
                this,
                JS_BRIDGE_OBJECT_NAME,
                setOf(originRule),
            ) { _, message, sourceOrigin, isMainFrame, _ ->
                // Naprawa DYSPOZYCJA_NAPRAWCZA pkt 3 ("dokładny origin", "brak
                // dostępu z iframe"): mimo że [androidx.webkit.WebViewCompat.addWebMessageListener]
                // już filtruje po [allowedOriginRules], sprawdzamy to ponownie
                // jawnie w kodzie natywnym — obrona w głąb — i odrzucamy
                // wszystko spoza głównej ramki.
                if (isMainFrame && sourceOrigin.toString().trimEnd('/') == originRule.trimEnd('/')) {
                    handleBridgeMessage(message.data)
                }
            }
        }
        webViewClient = SecureWebViewClient(
            extraAllowedHosts = pl.zrodloslowa.app.webview.WebUrlResolver.extraAllowedHosts(baseUrl),
            allowInsecureHttp = pl.zrodloslowa.app.BuildConfig.DEBUG,
            onPageFinishedUrl = {
                ready = true
                val calls = pendingCalls.toList()
                pendingCalls.clear()
                calls.forEach {
                    mainHandler.removeCallbacks(it.timeout)
                    it.invoke()
                }
            },
        )
        loadUrl(baseUrl.trimEnd('/') + "/")
    }

    /** `GET /api/earnings/notifications?after_id=...&limit=...` — zwraca surowy JSON przez [onResult]. */
    fun fetchNotifications(afterId: Int, limit: Int = 10, onResult: (String) -> Unit) {
        if (fetchInFlight) {
            mainHandler.post { onResult(ERROR_BUSY) }
            return
        }
        fetchInFlight = true
        val url = baseUrl.trimEnd('/') + "/api/earnings/notifications?after_id=$afterId&limit=$limit"
        runFetch(
            scriptTemplate = { callId ->
                """
                fetch(${jsString(url)}, { method: 'GET', credentials: 'same-origin' })
                    .then(function(r) { return r.text(); })
                    .then(function(t) { ${postToBridge(callId = "$callId", json = "t")} })
                    .catch(function(e) { ${postToBridge(callId = "$callId", json = "'{\"ok\":false,\"reason\":\"network_error\"}'")} });
                """.trimIndent()
            },
            onResult = { raw ->
                fetchInFlight = false
                onResult(raw)
            },
        )
    }

    /**
     * `POST /api/earnings/notifications/ack` — potwierdzenie odczytania powiadomień.
     *
     * Naprawa pkt 3.6/10 z dyspozycji: endpoint wymaga CSRF, więc token
     * pobieramy z `<meta name="csrf-token">` tej samej strony (tak samo jak
     * robi to natywny JS serwisu w `layouts/main.php`) i dokładamy go w
     * nagłówku `X-CSRF-TOKEN` razem z `X-Requested-With`. Nie tworzymy
     * nowego endpointu ani nie wyłączamy CSRF po stronie backendu.
     */
    fun acknowledge(ids: List<Int>, onResult: (String) -> Unit) {
        acknowledgeBody("ids=" + ids.joinToString(","), onResult)
    }

    /** Ten sam endpoint i ten sam backendowy licznik; bez lokalnego czyszczenia na ślepo. */
    fun acknowledgeAll(onResult: (String) -> Unit) {
        acknowledgeBody("all=1", onResult)
    }

    private fun acknowledgeBody(body: String, onResult: (String) -> Unit) {
        if (acknowledgeInFlight) {
            mainHandler.post { onResult(ERROR_BUSY) }
            return
        }
        acknowledgeInFlight = true
        val url = baseUrl.trimEnd('/') + "/api/earnings/notifications/ack"
        runFetch(
            scriptTemplate = { callId ->
                """
                (function() {
                    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
                    fetch(${jsString(url)}, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-TOKEN': csrfToken || '',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: ${jsString(body)}
                    })
                        .then(function(r) { return r.text(); })
                        .then(function(t) { ${postToBridge(callId = "$callId", json = "t")} })
                        .catch(function(e) { ${postToBridge(callId = "$callId", json = "'{\"ok\":false,\"reason\":\"network_error\"}'")} });
                })();
                """.trimIndent()
            },
            onResult = { raw ->
                acknowledgeInFlight = false
                onResult(raw)
            },
        )
    }

    /** Zwalnia zasoby WebView — wywoływać przy opuszczeniu ekranu Powiadomienia. */
    fun destroy() {
        pendingCallbacks.values.forEach { mainHandler.removeCallbacks(it.timeout) }
        pendingCalls.forEach { mainHandler.removeCallbacks(it.timeout) }
        pendingCallbacks.clear()
        pendingCalls.clear()
        fetchInFlight = false
        acknowledgeInFlight = false
        webView.stopLoading()
        webView.destroy()
    }

    /**
     * Każde wywołanie dostaje własny [callId] — naprawa "Powiadomienia mają
     * możliwy konflikt wywołań" z audytu: polling i ack mogą nakładać się
     * czasowo, ale każdy wynik trafia do własnego callbacku przez
     * [pendingCallbacks], nigdy nie nadpisując cudzego.
     */
    private fun runFetch(scriptTemplate: (callId: Int) -> String, onResult: (String) -> Unit) {
        if (pendingCallbacks.size >= MAX_PENDING_CALLBACKS) {
            mainHandler.post { onResult(ERROR_BUSY) }
            return
        }
        val callId = nextCallId.getAndIncrement()
        lateinit var call: () -> Unit
        val readinessTimeout = Runnable {
            val queued = pendingCalls.firstOrNull { it.invoke === call }
            if (queued != null && pendingCalls.remove(queued)) onResult(ERROR_TIMEOUT)
        }
        call = {
            mainHandler.removeCallbacks(readinessTimeout)
            val timeout = Runnable {
                val pending = pendingCallbacks.remove(callId) ?: return@Runnable
                pending.callback(ERROR_TIMEOUT)
            }
            pendingCallbacks[callId] = PendingCallback(onResult, timeout)
            mainHandler.postDelayed(timeout, CALL_TIMEOUT_MS)
            webView.evaluateJavascript(scriptTemplate(callId), null)
        }
        if (ready) {
            call()
        } else {
            pendingCalls.add(PendingCall(call, readinessTimeout))
            mainHandler.postDelayed(readinessTimeout, CALL_TIMEOUT_MS)
        }
    }

    private fun jsString(value: String): String =
        org.json.JSONObject.quote(value)

    /**
     * Buduje fragment JS wysyłający wynik do `AndroidBridge.postMessage(...)`
     * — jedynego API dostępnego dla WebMessageListener (w przeciwieństwie do
     * dawnego `addJavascriptInterface`, nie ma tu dowolnej nazwy metody).
     * [json] to fragment JS zwracający łańcuch znaków z surowym JSON-em
     * odpowiedzi (zmienna `t` z `fetch().then(...)` albo stały literal
     * błędu sieci).
     */
    private fun postToBridge(callId: String, json: String): String =
        if (isSupported) {
            "$JS_BRIDGE_OBJECT_NAME.postMessage(JSON.stringify({callId: $callId, json: $json}));"
        } else {
            ""
        }

    /**
     * Parsuje wiadomość `{callId, json}` otrzymaną od
     * [androidx.webkit.WebViewCompat.WebMessageListener.onPostMessage] i
     * kieruje wynik do właściwego, oczekującego callbacku
     * ([pendingCallbacks]) — dokładnie tak samo jak dawny `JsBridge.onResult`,
     * ale bez refleksyjnej metody dostępnej dla każdej ramki strony.
     */
    private fun handleBridgeMessage(rawMessage: String?) {
        val payload = rawMessage ?: return
        val callback = runCatching {
            val obj = org.json.JSONObject(payload)
            val callId = obj.getInt("callId")
            val json = obj.getString("json")
            val pending = pendingCallbacks.remove(callId)
            if (pending != null) mainHandler.removeCallbacks(pending.timeout)
            pending?.callback to json
        }.getOrNull() ?: return
        val (onResult, json) = callback
        mainHandler.post { onResult?.invoke(json) }
    }

    private val mainHandler = Handler(Looper.getMainLooper())

    private companion object {
        /** Nazwa obiektu JS wstrzykiwanego przez `WebMessageListener` (odpowiednik dawnego `AndroidBridge`). */
        const val JS_BRIDGE_OBJECT_NAME = "AndroidBridge"
        const val MAX_PENDING_CALLBACKS = 4
        const val CALL_TIMEOUT_MS = 10_000L
        const val ERROR_BUSY = "{\"ok\":false,\"reason\":\"request_in_progress\"}"
        const val ERROR_TIMEOUT = "{\"ok\":false,\"reason\":\"request_timeout\"}"
    }
}
