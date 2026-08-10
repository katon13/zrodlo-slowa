package pl.zrodloslowa.mobile.ui.approval

import androidx.annotation.StringRes
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import pl.zrodloslowa.mobile.R
import pl.zrodloslowa.mobile.model.ApprovalRequestDetails
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Ekran świadomej decyzji dla operacji sklasyfikowanej przez backend. */
@Composable
fun ApprovalScreen(
    viewModel: ApprovalViewModel,
    requestPublicId: String,
    activity: FragmentActivity,
    onFinished: () -> Unit = {},
) {
    val state by viewModel.state.collectAsState()

    LaunchedEffect(requestPublicId) {
        viewModel.loadRequest(requestPublicId)
    }

    when (state) {
        is ApprovalUiState.Loading -> CenteredMessage(R.string.approval_loading, showProgress = true)
        is ApprovalUiState.Ready -> {
            val ready = state as ApprovalUiState.Ready
            ApprovalDetailsContent(
                details = ready.details,
                onApprove = { viewModel.approve(activity, ready.details) },
                onReject = { viewModel.reject(activity, ready.details) },
            )
        }
        is ApprovalUiState.Submitting -> CenteredMessage(R.string.approval_signing, showProgress = true)
        is ApprovalUiState.Approved -> ApprovedScreen(onDone = onFinished)
        is ApprovalUiState.Rejected -> RejectedScreen(onDone = onFinished)
        is ApprovalUiState.Expired -> ExpiredScreen(onDone = onFinished)
        is ApprovalUiState.DeviceSuspended -> CenteredMessage(R.string.approval_device_suspended)
        is ApprovalUiState.DeviceRevoked -> CenteredMessage(R.string.approval_device_revoked)
        is ApprovalUiState.AlreadyProcessed -> CenteredMessage(R.string.approval_already_processed)
        is ApprovalUiState.Error -> CenteredMessage(R.string.approval_generic_error)
    }
}

/** Użytkownik widzi tłumaczenie; identyfikator maszynowy pozostaje częścią protokołu. */
@StringRes
private fun operationLabelResource(details: ApprovalRequestDetails): Int = when (details.actionType) {
    "auth.login" -> R.string.operation_auth_login
    "article.submit" -> R.string.operation_article_submit
    "article.send_to_editor" -> R.string.operation_article_send_to_editor
    "article.approve_version" -> R.string.operation_article_approve_version
    "article.publish" -> R.string.operation_article_publish
    "article.unpublish" -> R.string.operation_article_unpublish
    "article.export_sources" -> R.string.operation_article_export_sources
    "confidential.material_access", "confidential_material.access" -> R.string.operation_confidential_material_access
    "payout.approve" -> R.string.operation_payout_approve
    "payout.reject" -> R.string.operation_payout_reject
    "wallet.adjust" -> R.string.operation_wallet_adjust
    "payout.details_admin_change", "payout_details.admin_change" -> R.string.operation_payout_details_admin_change
    "ledger.manual_correction" -> R.string.operation_ledger_manual_correction
    "role.change" -> R.string.operation_role_change
    "security.settings_change", "security.settings.change" -> R.string.operation_security_settings_change
    "device.register" -> R.string.operation_device_register
    "device.revoke" -> R.string.operation_device_revoke
    "admin.approve" -> R.string.operation_admin_approve
    "dors3.policy_change", "dors3.policy.change" -> R.string.operation_dors3_policy_change
    "backup.restore" -> R.string.operation_backup_restore
    "sensitive.data_export", "sensitive_data.export" -> R.string.operation_sensitive_data_export
    "financial.settings_change", "financial_settings.change" -> R.string.operation_financial_settings_change
    "safety_fund.disbursement" -> R.string.operation_safety_fund_disbursement
    "payout.details_change", "payout_details.change" -> R.string.operation_payout_details_change
    "wallet.own_operation" -> R.string.operation_wallet_own_operation
    "agreement.action" -> R.string.operation_agreement_action
    else -> if (details.purpose == "login") R.string.operation_auth_login else R.string.operation_unknown
}

private fun formatExpiry(expiresAtEpochSeconds: Long): String {
    val formatter = SimpleDateFormat("HH:mm:ss", Locale.getDefault())
    return formatter.format(Date(expiresAtEpochSeconds * 1000))
}

@Composable
internal fun ApprovalDetailsContent(
    details: ApprovalRequestDetails,
    onApprove: () -> Unit,
    onReject: () -> Unit,
) {
    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(stringResource(R.string.product_label), style = MaterialTheme.typography.titleLarge)
        LabeledValue(stringResource(R.string.approval_service), details.service)
        LabeledValue(
            stringResource(R.string.approval_account_and_role),
            "${details.person} (${details.role})",
        )
        LabeledValue(
            stringResource(R.string.approval_operation_type),
            stringResource(operationLabelResource(details)),
        )
        details.displayFields.forEach { (label, value) -> LabeledValue(label, value) }
        LabeledValue(stringResource(R.string.approval_valid_until), formatExpiry(details.expiresAt))

        Row(
            modifier = Modifier.fillMaxWidth().padding(top = 24.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Button(onClick = onApprove, modifier = Modifier.fillMaxWidth(0.5f)) {
                Text(stringResource(R.string.approval_approve))
            }
            OutlinedButton(onClick = onReject, modifier = Modifier.fillMaxWidth()) {
                Text(stringResource(R.string.approval_reject))
            }
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
internal fun CenteredMessage(@StringRes messageRes: Int, showProgress: Boolean = false) {
    Column(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.Center,
    ) {
        if (showProgress) CircularProgressIndicator()
        Text(stringResource(messageRes))
    }
}

@Composable
private fun ApprovedScreen(onDone: () -> Unit) {
    CenteredMessage(R.string.approval_approved)
    LaunchedEffect(Unit) { onDone() }
}
