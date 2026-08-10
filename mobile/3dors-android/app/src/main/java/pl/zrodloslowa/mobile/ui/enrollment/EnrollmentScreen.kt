package pl.zrodloslowa.mobile.ui.enrollment

import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import pl.zrodloslowa.mobile.R
import pl.zrodloslowa.mobile.config.EnvironmentConfig
import pl.zrodloslowa.mobile.ui.qr.QrScannerScreen

/**
 * Ekran rejestracji urządzenia (pkt 4.2). Pokazuje dokładnie te dane, które
 * wymaga krok 6 dyspozycji: serwis, środowisko, organizację, osobę, konto,
 * rolę i cel rejestracji — zanim klucz zostanie utworzony w Keystore.
 */
@Composable
fun EnrollmentScreen(viewModel: EnrollmentViewModel) {
    val state by viewModel.state.collectAsState()

    when (val current = state) {
        is EnrollmentUiState.AwaitingQrScan -> {
            QrScannerScreen(onQrCodeScanned = viewModel::onQrScanned)
        }

        is EnrollmentUiState.ReviewingDetails -> {
            val payload = current.payload
            Column(
                modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text(stringResource(R.string.product_label), style = MaterialTheme.typography.titleLarge)
                Text(stringResource(R.string.enrollment_title))
                LabeledValue(stringResource(R.string.label_service), payload.service)
                LabeledValue(stringResource(R.string.label_environment), payload.environment)
                LabeledValue(stringResource(R.string.label_organization), payload.organization)
                LabeledValue(stringResource(R.string.label_person), payload.userDisplayName)
                LabeledValue(stringResource(R.string.label_account), payload.account)
                LabeledValue(stringResource(R.string.label_role), payload.role)
                LabeledValue(stringResource(R.string.label_purpose), stringResource(R.string.purpose_enrollment))
                Button(onClick = { viewModel.confirmRegistrationDetails(payload) }) {
                    Text(stringResource(R.string.enrollment_create_key))
                }
            }
        }

        is EnrollmentUiState.CreatingKey -> {
            CenteredMessage(R.string.enrollment_creating_key, showProgress = true)
        }

        is EnrollmentUiState.AwaitingPanelApproval -> {
            Column(
                modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text(stringResource(R.string.enrollment_panel_approval_title), style = MaterialTheme.typography.titleLarge)
                Text(stringResource(R.string.enrollment_panel_approval_description))
                Text(current.comparisonCode, style = MaterialTheme.typography.headlineMedium)
                Text(stringResource(R.string.enrollment_server_status, serverStatusLabel(current.serverStatus)))
                Button(onClick = {
                    viewModel.checkActivation(current.devicePublicId, current.comparisonCode)
                }) {
                    Text(stringResource(R.string.enrollment_check_activation))
                }
                OutlinedButton(onClick = {
                    viewModel.rejectComparisonCode(current.devicePublicId, current.comparisonCode)
                }) {
                    Text(stringResource(R.string.enrollment_code_mismatch))
                }
            }
        }

        is EnrollmentUiState.Completed -> {
            CenteredMessage(R.string.enrollment_completed)
        }

        is EnrollmentUiState.Error -> {
            CenteredMessage(R.string.approval_generic_error)
        }
    }
}

@Composable
private fun LabeledValue(label: String, value: String) {
    Column {
        Text(label, style = MaterialTheme.typography.labelMedium)
        Text(value, style = MaterialTheme.typography.bodyLarge)
    }
}

@Composable
private fun CenteredMessage(messageRes: Int, showProgress: Boolean = false) {
    Column(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.Center,
    ) {
        if (showProgress) {
            CircularProgressIndicator()
        }
        Text(stringResource(messageRes))
        Text(
            stringResource(R.string.environment_value, EnvironmentConfig.ENVIRONMENT_LABEL),
            style = MaterialTheme.typography.labelSmall,
        )
    }
}

@Composable
private fun serverStatusLabel(status: String): String = stringResource(
    when (status) {
        "pending" -> R.string.status_pending
        "completed" -> R.string.status_completed
        "confirmed", "active" -> R.string.status_active
        "suspended" -> R.string.status_suspended
        "lost" -> R.string.status_lost
        "revoked" -> R.string.status_revoked
        "expired" -> R.string.status_expired
        else -> R.string.status_unknown
    },
)
