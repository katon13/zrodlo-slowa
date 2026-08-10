package pl.zrodloslowa.mobile.variant

/** Kod dostępny wyłącznie w kompilacji 3DORS Admin. */
object VariantPolicy {
    const val applicationVariant: String = "admin"
    private val allowedOperations = setOf(
        "payout.approve",
        "payout.reject",
        "wallet.adjust",
        "payout_details.admin_change",
        "ledger.manual_correction",
        "role.change",
        "security.settings.change",
        "device.register",
        "device.revoke",
        "admin.approve",
        "dors3.policy.change",
        "backup.restore",
        "sensitive_data.export",
        "financial_settings.change",
        "safety_fund.disbursement",
        "payout_details.change",
        "wallet.own_operation",
    )

    fun accepts(actionType: String?): Boolean = actionType != null && actionType in allowedOperations
}
