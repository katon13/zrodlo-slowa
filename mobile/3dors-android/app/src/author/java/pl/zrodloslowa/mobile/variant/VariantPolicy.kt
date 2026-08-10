package pl.zrodloslowa.mobile.variant

/** Kod dostępny wyłącznie w kompilacji 3DORS Author. */
object VariantPolicy {
    const val applicationVariant: String = "author"
    private val allowedOperations = setOf(
        "article.submit",
        "article.send_to_editor",
        "article.approve_version",
        "article.publish",
        "article.unpublish",
        "article.export_sources",
        "confidential_material.access",
        "agreement.action",
    )

    fun accepts(actionType: String?): Boolean = actionType != null && actionType in allowedOperations
}
