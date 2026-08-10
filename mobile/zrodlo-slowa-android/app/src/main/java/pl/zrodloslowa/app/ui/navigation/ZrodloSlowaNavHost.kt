package pl.zrodloslowa.app.ui.navigation

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.content.res.Configuration
import android.content.res.Resources
import android.net.Uri
import android.view.WindowManager
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Scaffold
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import androidx.navigation.NavType
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import kotlinx.coroutines.delay
import kotlinx.coroutines.suspendCancellableCoroutine
import pl.zrodloslowa.app.config.AppLanguageManager
import pl.zrodloslowa.app.config.LanguagePreferenceStore
import pl.zrodloslowa.app.config.OnboardingPreferenceStore
import pl.zrodloslowa.app.config.SiteConfig
import pl.zrodloslowa.app.navigation.AppDestination
import pl.zrodloslowa.app.ui.account.AccountScreen
import pl.zrodloslowa.app.ui.articles.ArticlesScreen
import pl.zrodloslowa.app.ui.common.WebPageScreen
import pl.zrodloslowa.app.ui.home.HomeScreen
import pl.zrodloslowa.app.ui.notifications.NotificationsScreen
import pl.zrodloslowa.app.ui.onboarding.OnboardingChoice
import pl.zrodloslowa.app.ui.onboarding.OnboardingScreen
import pl.zrodloslowa.app.ui.wallet.WalletScreen
import pl.zrodloslowa.app.webview.SessionSecurityState
import pl.zrodloslowa.app.referral.ReferralInstallManager
import pl.zrodloslowa.app.notifications.NotificationUnreadState
import pl.zrodloslowa.app.notifications.NotificationsApiBridge
import pl.zrodloslowa.app.notifications.NotificationsPage
import pl.zrodloslowa.app.session.WebSessionManager
import pl.zrodloslowa.app.webview.WebUrlResolver
import kotlin.coroutines.resume

private const val WEB_PAGE_ROUTE = "webpage/{path}"

private fun webPageRoute(path: String): String = "webpage/" + Uri.encode(path)

/** Znajduje najbliższą [Activity] za dowolnym opakowaniem [ContextWrapper] (np. kontekstem zlokalizowanym). */
private tailrec fun Context.findActivity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.findActivity()
    else -> null
}

/**
 * Powłoka nawigacji aplikacji "Źródło Słowa Mobile" (ETAP 1 z dyspozycji):
 * Scaffold ze stałym dolnym menu, kompaktowym natywnym nagłówkiem
 * ([AppTopBar]) i hostem nawigacji Compose przełączającym pięć głównych
 * ekranów. Stan wyboru zakładki pochodzi wprost z back stacku nawigacji,
 * dzięki czemu podświetlenie w menu jest zawsze zgodne z ekranem.
 *
 * Naprawa GŁÓWNEGO PROBLEMU z audytu: dawny, pełny nagłówek strony WWW
 * jest teraz ukryty wewnątrz WebView ([pl.zrodloslowa.app.webview.SecureWebView]),
 * a jego rolę przejmuje kompaktowy [AppTopBar] (logo + szukaj + hamburger).
 * Hamburger zastępuje też dawny osobny pasek językowy pod status barem
 * (pkt 3.4/7) oraz stały przycisk "Dołącz" (pkt 3.2) — wybór jest trwale
 * zapamiętany przez [LanguagePreferenceStore], a jego uwzględnienie przy
 * kolejnym uruchomieniu zapewnia [AppLanguageManager.resolveEffectiveLanguageCode].
 * Przed pierwszym wejściem do powłoki pokazywany jest jednorazowy
 * [OnboardingScreen] (pkt 3.2/6 dyspozycji).
 */
@Composable
fun ZrodloSlowaNavHost(
    navController: NavHostController = rememberNavController(),
) {
    val context = LocalContext.current
    var languageCode by rememberSaveable {
        mutableStateOf(AppLanguageManager.resolveEffectiveLanguageCode(context))
    }
    var onboardingDone by rememberSaveable {
        mutableStateOf(OnboardingPreferenceStore.isOnboardingDone(context))
    }
    var pendingPostOnboardingRoute by rememberSaveable { mutableStateOf<String?>(null) }
    val registrationNonce by ReferralInstallManager.pendingRegistrationNonce
    val currentBackStackEntry = navController.currentBackStackEntryAsState().value
    val currentRoute = currentBackStackEntry?.destination?.route
    val currentDestination = AppDestination.fromRoute(currentRoute)
    val notificationSite = remember(languageCode) { SiteConfig.siteForLanguage(languageCode) }
    val notificationBaseUrl = remember(notificationSite) { WebUrlResolver.baseUrl(notificationSite) }
    val webSessions by WebSessionManager.sessions
    val notificationSessionKey = webSessions[notificationBaseUrl.trimEnd('/')]?.storageKey ?: "anonymous"
    val lifecycleOwner = LocalLifecycleOwner.current
    val notificationBridge = remember(notificationBaseUrl, notificationSessionKey) {
        NotificationsApiBridge(context, notificationBaseUrl)
    }

    DisposableEffect(notificationBridge) {
        onDispose { notificationBridge.destroy() }
    }
    LaunchedEffect(notificationSessionKey) {
        NotificationUnreadState.clear()
    }
    LaunchedEffect(notificationBridge, lifecycleOwner, notificationSessionKey) {
        lifecycleOwner.lifecycle.repeatOnLifecycle(Lifecycle.State.STARTED) {
            while (true) {
                val raw = suspendCancellableCoroutine { continuation ->
                    notificationBridge.fetchNotifications(afterId = 0, limit = 1) { response ->
                        if (continuation.isActive) continuation.resume(response)
                    }
                }
                val page = NotificationsPage.fromJsonText(raw)
                if (page.ok) {
                    NotificationUnreadState.update(page.unreadCount)
                }
                delay(20_000L)
            }
        }
    }

    // Doprecyzowanie dyspozycji pkt 4.2 ("FLAG_SECURE ma wynikać z
    // potwierdzonego przez serwer stanu sesji, nie z heurystyki URL"):
    // wcześniejsza wersja analizowała słowa kluczowe w adresie/ścieżce
    // (`wallet`, `login`, `author` itd.) — to była lokalna heurystyka mobilna,
    // która duplikowała wiedzę o strukturze serwisu WWW. Dziś ochrona ekranu
    // wynika WYŁĄCZNIE z [SessionSecurityState.isProtectionActive] — trwający
    // proces logowania/2FA/resetu hasła lub sesja POTWIERDZONA przez serwer
    // ([pl.zrodloslowa.app.ui.auth.AuthGate], realne żądanie HTTP, nigdy sama
    // obecność cookie). Podczas ponownej weryfikacji wcześniej aktywnej sesji
    // ochrona NIE jest zdejmowana, dopóki serwer jawnie nie potwierdzi
    // wylogowania — patrz komentarze w [pl.zrodloslowa.app.ui.auth.AuthGate].
    val isProtectionActive = SessionSecurityState.isProtectionActive
    DisposableEffect(isProtectionActive) {
        val activity = context.findActivity()
        if (isProtectionActive) {
            activity?.window?.setFlags(WindowManager.LayoutParams.FLAG_SECURE, WindowManager.LayoutParams.FLAG_SECURE)
        } else {
            activity?.window?.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
        }
        onDispose { }
    }

    // Naprawa NIESPÓJNOŚCI WIZUALNEJ z audytu (mieszanie PL/EN): natywne
    // stringi Compose (dolne menu, tytuły ekranów) mają podążać za wybraną
    // wersją językową serwisu ([languageCode]), a nie za samym locale
    // systemowym urządzenia. Zasoby `values-en/de/fr/it/es` już istnieją,
    // więc wystarczy opakować drzewo Compose kontekstem z wymuszonym locale.
    val localizedContext = remember(context, languageCode) {
        localizedContext(context, languageCode)
    }

    LaunchedEffect(registrationNonce) {
        if (registrationNonce != null && !onboardingDone) {
            OnboardingPreferenceStore.markOnboardingDone(context)
            onboardingDone = true
        }
    }

    CompositionLocalProvider(LocalContext provides localizedContext) {
    if (!onboardingDone) {
        OnboardingScreen(
            onChoiceSelected = { choice ->
                OnboardingPreferenceStore.markOnboardingDone(context)
                pendingPostOnboardingRoute = when (choice) {
                    OnboardingChoice.BROWSE_AS_GUEST -> null
                    OnboardingChoice.HAVE_ACCOUNT -> AppDestination.ACCOUNT.route
                    OnboardingChoice.JOIN_AS_AUTHOR -> webPageRoute("register")
                }
                onboardingDone = true
            },
        )
        return@CompositionLocalProvider
    }

    Scaffold(
        topBar = {
            val activeSite = SiteConfig.siteForLanguage(languageCode)
            AppTopBar(
                brandName = activeSite.brandName,
                wordmarkLine1 = activeSite.wordmarkLine1,
                wordmarkLine2 = activeSite.wordmarkLine2,
                selectedLanguageCode = languageCode,
                onSearchClick = {
                    if (currentDestination != AppDestination.ARTICLES) {
                        navController.navigate(AppDestination.ARTICLES.route) {
                            popUpTo(AppDestination.HOME.route) { saveState = true }
                            launchSingleTop = true
                            restoreState = true
                        }
                    }
                },
                onMenuPathSelected = { path -> navController.navigate(webPageRoute(path)) },
                onLanguageSelected = { selected ->
                    // Naprawa pkt 1 dyspozycji: dopóki działa pojedynczy
                    // debugowy backend PL, żaden ręczny wybór innej wersji
                    // nie może faktycznie zmienić ładowanej treści — więc
                    // ignorujemy wybór, zamiast pokazywać UI w innym języku
                    // niż strona (patrz AppLanguageManager.isSingleDebugBackendActive).
                    if (!AppLanguageManager.isSingleDebugBackendActive()) {
                        LanguagePreferenceStore.setManualLanguage(context, selected)
                        languageCode = selected
                    }
                },
                onAutomaticLanguageSelected = {
                    LanguagePreferenceStore.clearManualLanguage(context)
                    languageCode = AppLanguageManager.resolveEffectiveLanguageCode(context)
                },
                onAccountClick = {
                    if (currentDestination != AppDestination.ACCOUNT) {
                        navController.navigate(AppDestination.ACCOUNT.route) {
                            popUpTo(AppDestination.HOME.route) { saveState = true }
                            launchSingleTop = true
                            restoreState = true
                        }
                    }
                },
            )
        },
        bottomBar = {
            ZrodloSlowaBottomBar(
                currentDestination = currentDestination,
                unreadNotificationCount = NotificationUnreadState.count,
                onDestinationSelected = { destination ->
                    if (destination != currentDestination) {
                        navController.navigate(destination.route) {
                            popUpTo(AppDestination.HOME.route) { saveState = true }
                            launchSingleTop = true
                            restoreState = true
                        }
                    }
                },
            )
        },
    ) { innerPadding ->
        NavHost(
            navController = navController,
            startDestination = AppDestination.HOME.route,
            modifier = Modifier.padding(innerPadding),
        ) {
            composable(AppDestination.HOME.route) { HomeScreen(languageCode = languageCode) }
            composable(AppDestination.ARTICLES.route) { ArticlesScreen(languageCode = languageCode) }
            composable(AppDestination.WALLET.route) { WalletScreen(languageCode = languageCode) }
            composable(AppDestination.NOTIFICATIONS.route) { NotificationsScreen(languageCode = languageCode) }
            composable(AppDestination.ACCOUNT.route) { AccountScreen(languageCode = languageCode) }
            composable(
                route = WEB_PAGE_ROUTE,
                arguments = listOf(navArgument("path") { type = NavType.StringType }),
            ) { backStackEntry ->
                val path = Uri.decode(backStackEntry.arguments?.getString("path").orEmpty())
                WebPageScreen(path = path, languageCode = languageCode)
            }
        }

        // Przekierowanie po wyborze na ekranie startowym (pkt 3.2/6): "Mam
        // konto" prowadzi do zakładki Konto (LoginScreen przez AuthGate),
        // a "Dołącz jako autor" otwiera istniejącą trasę `/register`.
        LaunchedEffect(pendingPostOnboardingRoute) {
            pendingPostOnboardingRoute?.let { route ->
                navController.navigate(route)
                pendingPostOnboardingRoute = null
            }
        }
        LaunchedEffect(registrationNonce) {
            registrationNonce?.let { nonce ->
                navController.navigate(webPageRoute("register?refn=$nonce")) {
                    launchSingleTop = true
                }
                ReferralInstallManager.markRegistrationOpened()
            }
        }
    }
    }
}

/**
 * Tworzy [Context] z wymuszonym [Locale] odpowiadającym wybranej wersji
 * językowej serwisu, tak aby `stringResource(...)` w całym drzewie Compose
 * (dolne menu, tytuły ekranów, logowanie, powiadomienia) sięgał po zasoby
 * `values-<languageCode>` zgodne z wybraną wersją, a nie po locale systemu
 * urządzenia — naprawa mieszania języków PL/EN z audytu.
 *
 * Naprawa "Nie zastępuj globalnego LocalContext kontekstem konfiguracyjnym"
 * (niezależny audyt bezpieczeństwa, DYSPOZYCJA_NAPRAWCZA pkt 10): poprzednia
 * wersja zwracała bezpośrednio wynik `context.createConfigurationContext(...)`
 * — to NIE jest `Activity`, a był on następnie dostarczany jako globalny
 * `LocalContext` dla całego drzewa Compose. Skutek: `WebView(context)`
 * ([pl.zrodloslowa.app.webview.SecureWebView]) i `context.startActivity(...)`
 * dla linków zewnętrznych (OAuth, 3DORS Author) działały na kontekście
 * konfiguracyjnym zamiast na `Activity` — dla `startActivity` bez
 * `FLAG_ACTIVITY_NEW_TASK` to realne ryzyko `AndroidRuntimeException` przy
 * każdym kliknięciu linku spoza WebView. Zamiast podmieniać kontekst,
 * OPAKOWUJEMY oryginalny kontekst `Activity` w [ContextWrapper], nadpisując
 * wyłącznie [ContextWrapper.getResources] — `startActivity`,
 * `getSystemService` i reszta operacji nadal trafiają do prawdziwej
 * `Activity` (deleguje je `ContextWrapper`), a jedynie odczyt zasobów
 * (w tym `stringResource(...)`) korzysta z wymuszonego locale.
 */
private fun localizedContext(context: Context, languageCode: String): Context {
    val locale = java.util.Locale(languageCode)
    val configuration = Configuration(context.resources.configuration)
    configuration.setLocale(locale)
    val configResources = context.createConfigurationContext(configuration).resources
    return object : ContextWrapper(context) {
        override fun getResources(): Resources = configResources
    }
}
