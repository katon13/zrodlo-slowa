package pl.zrodloslowa.mobile

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.WindowManager
import androidx.activity.compose.setContent
import androidx.fragment.app.FragmentActivity
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.ui.Modifier
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import pl.zrodloslowa.mobile.data.ApprovalRepository
import pl.zrodloslowa.mobile.deeplink.Dors3DeepLink
import pl.zrodloslowa.mobile.demo.addDemoDestinations
import pl.zrodloslowa.mobile.ui.approval.ApprovalScreen
import pl.zrodloslowa.mobile.ui.approval.ApprovalViewModel
import pl.zrodloslowa.mobile.ui.enrollment.EnrollmentScreen
import pl.zrodloslowa.mobile.ui.enrollment.EnrollmentViewModel
import pl.zrodloslowa.mobile.ui.home.HomeScreen
import pl.zrodloslowa.mobile.ui.home.HomeUiState
import pl.zrodloslowa.mobile.ui.home.HomeViewModel
import pl.zrodloslowa.mobile.ui.qr.QrScannerScreen
import pl.zrodloslowa.mobile.ui.theme.Dors3MobileTheme

/** Trasy nawigacji uznawane za "ekrany rzeczywiste" (Effective Issue: FLAG_SECURE). */
private val SECURE_ROUTE_PREFIXES = listOf("approval/", "enrollment", "qr_scan_approval")

/**
 * Aktywność hosta nawigacji Compose. Obsługuje zarówno przepływ A (skanowanie
 * QR na tym urządzeniu jako przeglądający), jak i przepływ B — deep link
 * "ten sam telefon" (pkt 0.1, 5.3): intencja VIEW z publicznym identyfikatorem
 * żądania kieruje bezpośrednio na ekran operacji, z pominięciem ekranu QR.
 */
class MainActivity : FragmentActivity() {

    private var incomingRequestPublicId by mutableStateOf<String?>(null)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val app = application as Dors3MobileApp
        incomingRequestPublicId = intent?.data?.let(Dors3DeepLink::extractRequestPublicId)

        setContent {
            Dors3MobileTheme {
                val navController = rememberNavController()
                Dors3NavHost(
                    navController = navController,
                    app = app,
                    activity = this,
                    initialRequestPublicId = incomingRequestPublicId,
                )
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        incomingRequestPublicId = intent.data?.let(Dors3DeepLink::extractRequestPublicId)
    }

    fun consumeIncomingRequest(publicId: String) {
        if (incomingRequestPublicId == publicId) {
            incomingRequestPublicId = null
        }
    }
}

@androidx.compose.runtime.Composable
private fun Dors3NavHost(
    navController: NavHostController,
    app: Dors3MobileApp,
    activity: MainActivity,
    initialRequestPublicId: String?,
) {
    // FLAG_SECURE WYŁĄCZNIE na ekranach rzeczywistych (Effective Issue) — tryb
    // demo/Screen Gallery (tylko debug) celowo pozwala robić zrzuty ekranu.
    val currentRoute = navController.currentBackStackEntryAsState().value?.destination?.route
    DisposableEffect(currentRoute) {
        val isSecureScreen = currentRoute != null && SECURE_ROUTE_PREFIXES.any { currentRoute.startsWith(it) }
        if (isSecureScreen) {
            activity.window.setFlags(WindowManager.LayoutParams.FLAG_SECURE, WindowManager.LayoutParams.FLAG_SECURE)
        } else {
            activity.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
        }
        onDispose { }
    }

    NavHost(
        navController = navController,
        startDestination = "home",
        modifier = Modifier.safeDrawingPadding(),
    ) {
        composable("home") {
            val viewModel = remember {
                HomeViewModel(
                    repository = app.approvalRepository,
                    credentialStore = app.credentialStore,
                )
            }
            val state by viewModel.state.collectAsState()

            androidx.compose.runtime.LaunchedEffect(Unit) { viewModel.startWatching() }
            DisposableEffect(viewModel) {
                onDispose { viewModel.stopWatching() }
            }
            androidx.compose.runtime.LaunchedEffect(state) {
                val found = state
                if (found is HomeUiState.RequestFound) {
                    navController.navigate("approval/${Uri.encode(found.details.publicId)}")
                }
            }

            HomeScreen(
                state = state,
                onScanQrClick = { navController.navigate("qr_scan_approval") },
                onRegisterDeviceClick = { navController.navigate("enrollment") },
                onDemoModeClick = if (BuildConfig.DEBUG) {
                    { navController.navigate("demo_gallery") }
                } else {
                    null
                },
            )
        }

        composable("enrollment") {
            val viewModel = remember {
                EnrollmentViewModel(
                    repository = app.enrollmentRepository,
                    appVersion = ApprovalRepository.currentAppVersion(),
                    deviceModel = ApprovalRepository.currentDeviceModel(),
                    osVersion = ApprovalRepository.currentOsVersion(),
                )
            }
            EnrollmentScreen(viewModel = viewModel)
        }

        composable("qr_scan_approval") {
            QrScannerScreen(onQrCodeScanned = { rawQrContent ->
                // QR operacji/logowania zawiera wyłącznie publiczny identyfikator
                // żądania (pkt 6.1 krok 5) — reszta jest pobierana z backendu.
                val publicId = Uri.parse(rawQrContent).lastPathSegment ?: rawQrContent
                navController.navigate("approval/${Uri.encode(publicId)}") {
                    popUpTo("home")
                }
            })
        }

        composable("approval/{publicId}") { backStackEntry ->
            val publicId = backStackEntry.arguments?.getString("publicId").orEmpty()
            val viewModel = remember {
                ApprovalViewModel(repository = app.approvalRepository)
            }
            ApprovalScreen(
                viewModel = viewModel,
                requestPublicId = publicId,
                activity = activity,
                onFinished = {
                    navController.navigate("home") {
                        popUpTo("home") { inclusive = true }
                    }
                },
            )
        }

        if (BuildConfig.DEBUG) {
            addDemoDestinations(navController)
        }
    }

    androidx.compose.runtime.LaunchedEffect(initialRequestPublicId) {
        val publicId = initialRequestPublicId ?: return@LaunchedEffect
        navController.navigate("approval/${Uri.encode(publicId)}") {
            launchSingleTop = true
        }
        activity.consumeIncomingRequest(publicId)
    }
}
