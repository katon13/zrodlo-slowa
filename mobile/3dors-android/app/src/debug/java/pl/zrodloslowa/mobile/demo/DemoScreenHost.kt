package pl.zrodloslowa.mobile.demo

import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import pl.zrodloslowa.mobile.R
import pl.zrodloslowa.mobile.model.ApprovalRequestDetails
import pl.zrodloslowa.mobile.ui.approval.ApprovalDetailsContent
import pl.zrodloslowa.mobile.ui.approval.CenteredMessage
import pl.zrodloslowa.mobile.ui.approval.ExpiredScreen
import pl.zrodloslowa.mobile.ui.approval.RejectedScreen

/**
 * Host trybu demo, dostępny WYŁĄCZNIE w wariancie debug (Effective Issue).
 * Odtwarza wygląd realnych ekranów operacji na sztucznych, statycznych danych
 * — bez żadnego wywołania sieciowego, biometrii ani Android Keystore.
 */
@Composable
fun DemoScreenHost(scenario: DemoScenario, onBack: () -> Unit) {
    var approved by remember(scenario) { mutableStateOf(false) }

    if (approved) {
        CenteredMessage(R.string.demo_approved)
        return
    }

    when (scenario) {
        DemoScenario.REJECTED -> RejectedScreen(onDone = onBack)
        DemoScenario.EXPIRED -> ExpiredScreen(onDone = onBack)
        else -> ApprovalDetailsContent(
            details = demoDetailsFor(scenario),
            onApprove = { approved = true },
            onReject = onBack,
        )
    }
}

private fun demoDetailsFor(scenario: DemoScenario): ApprovalRequestDetails {
    val (purpose, actionType, displayFields) = when (scenario) {
        DemoScenario.LOGIN -> Triple("login", null, emptyMap())
        DemoScenario.WITHDRAWAL -> Triple(
            "operation",
            "payout.approve",
            mapOf("Kwota / odbiorca" to "1 250,00 PLN → Anna Nowak"),
        )
        DemoScenario.PUBLICATION -> Triple(
            "operation",
            "article.publish",
            mapOf("Tytuł materiału" to "Kazanie na Niedzielę Palmową"),
        )
        DemoScenario.CONFIDENTIAL -> Triple(
            "operation",
            "confidential.material_access",
            mapOf("Materiał" to "Raport zarządu — poufne"),
        )
        DemoScenario.REJECTED, DemoScenario.EXPIRED -> Triple("login", null, emptyMap())
    }
    val nowSeconds = System.currentTimeMillis() / 1000
    return ApprovalRequestDetails(
        requestId = "demo-req",
        publicId = "demo",
        purpose = purpose,
        service = "Źródło Słowa",
        environment = "DEMO",
        account = "demo.uzytkownik",
        person = "Demo Użytkownik",
        role = "Administrator",
        organization = "Źródło Słowa",
        initiatingDevice = "Chrome / Windows (demo)",
        actionType = actionType,
        displayFields = displayFields,
        challenge = "demo-challenge",
        actionFingerprint = "demo-fingerprint",
        browserSessionHash = "demo-session",
        issuedAt = nowSeconds,
        expiresAt = nowSeconds + 60,
        nonce = "demo-nonce",
        serverOrigin = "https://zrodlo-slowa.test",
    )
}
