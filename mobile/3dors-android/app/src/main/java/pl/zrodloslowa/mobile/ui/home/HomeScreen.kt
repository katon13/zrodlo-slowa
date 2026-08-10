package pl.zrodloslowa.mobile.ui.home

import androidx.compose.foundation.Image
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import pl.zrodloslowa.mobile.R
import pl.zrodloslowa.mobile.ui.theme.Dors3MobileTheme
import pl.zrodloslowa.mobile.ui.theme.Dors3InteractiveRedColor

/**
 * Ekran startowy — prosta aplikacja uwierzytelniająca (Effective Issue), a nie
 * panel zarządzania: duże logo, napis "3DORS MOBILE", automatyczne wyszukiwanie
 * aktywnego żądania oraz awaryjny przycisk "Skanuj QR". Bez menu, dashboardu
 * i kart „trzech drzwi”.
 */
@Composable
fun HomeScreen(
    state: HomeUiState,
    onScanQrClick: () -> Unit,
    onRegisterDeviceClick: () -> Unit,
    onDemoModeClick: (() -> Unit)? = null,
) {
    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Image(
            painter = painterResource(id = R.drawable.ic_dors3_logo),
            contentDescription = stringResource(R.string.dors3_logo_description),
            modifier = Modifier.size(190.dp),
        )
        Text(
            text = stringResource(R.string.product_label),
            style = MaterialTheme.typography.headlineMedium,
            color = MaterialTheme.colorScheme.onBackground,
            modifier = Modifier.padding(top = 16.dp, bottom = 32.dp),
        )

        when (state) {
            is HomeUiState.NotRegistered -> {
                Text(
                    text = stringResource(R.string.home_device_not_registered),
                    textAlign = TextAlign.Center,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(bottom = 16.dp),
                )
                Button(onClick = onRegisterDeviceClick) {
                    Text(stringResource(R.string.home_register_device))
                }
            }

            is HomeUiState.Error -> {
                Text(
                    text = stringResource(R.string.home_connection_problem),
                    textAlign = TextAlign.Center,
                    color = MaterialTheme.colorScheme.error,
                    modifier = Modifier.padding(bottom = 16.dp),
                )
            }

            is HomeUiState.RequestFound -> {
                // Nawigacja do ekranu operacji następuje na poziomie hosta nawigacji.
                CircularProgressIndicator(color = Dors3InteractiveRedColor)
            }

            HomeUiState.Idle, HomeUiState.Searching -> {
                Box(modifier = Modifier.padding(bottom = 16.dp)) {
                    CircularProgressIndicator(color = Dors3InteractiveRedColor)
                }
                Text(
                    text = stringResource(R.string.home_waiting_for_request),
                    style = MaterialTheme.typography.bodyLarge,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        Spacer()

        OutlinedButton(
            onClick = onScanQrClick,
            modifier = Modifier.fillMaxWidth(0.7f),
            colors = ButtonDefaults.outlinedButtonColors(contentColor = Dors3InteractiveRedColor),
        ) {
            Text(stringResource(R.string.home_scan_qr))
        }

        if (onDemoModeClick != null) {
            androidx.compose.material3.TextButton(
                onClick = onDemoModeClick,
                colors = ButtonDefaults.textButtonColors(contentColor = Dors3InteractiveRedColor),
            ) {
                Text(stringResource(R.string.home_demo_mode))
            }
        }
    }
}

@Composable
private fun Spacer() {
    androidx.compose.foundation.layout.Spacer(modifier = Modifier.padding(top = 32.dp))
}

@Preview(showBackground = true, name = "Home — oczekiwanie")
@Composable
private fun HomeScreenWaitingPreview() {
    Dors3MobileTheme {
        HomeScreen(state = HomeUiState.Searching, onScanQrClick = {}, onRegisterDeviceClick = {})
    }
}

@Preview(showBackground = true, name = "Home — brak rejestracji")
@Composable
private fun HomeScreenNotRegisteredPreview() {
    Dors3MobileTheme {
        HomeScreen(state = HomeUiState.NotRegistered, onScanQrClick = {}, onRegisterDeviceClick = {})
    }
}

@Preview(showBackground = true, name = "Home — błąd połączenia")
@Composable
private fun HomeScreenErrorPreview() {
    Dors3MobileTheme {
        HomeScreen(state = HomeUiState.Error("Brak połączenia"), onScanQrClick = {}, onRegisterDeviceClick = {})
    }
}
