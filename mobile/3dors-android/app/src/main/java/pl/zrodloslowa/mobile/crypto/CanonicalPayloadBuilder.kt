package pl.zrodloslowa.mobile.crypto

import pl.zrodloslowa.mobile.model.ApprovalRequestDetails

/**
 * Buduje kanoniczny ciąg znaków do podpisu, zgodnie z pkt 5.4 dyspozycji:
 *
 * ```
 * request_id
 * challenge
 * user_id
 * organization_id
 * role_context
 * server_origin
 * environment
 * browser_session_hash
 * issued_at
 * expires_at
 * nonce
 * purpose=login|operation
 * ```
 *
 * Zmiana KTÓREGOKOLWIEK pola musi unieważnić podpis — dlatego pola są łączone
 * w stałej, jednoznacznej kolejności, oddzielone znakiem `\n`, bez normalizacji
 * ani przycinania wartości (żeby nie dało się przemycić kolizji).
 */
object CanonicalPayloadBuilder {

    /** Wersja kanonizacji — zmiana formatu payloadu wymaga podniesienia tej stałej (pkt 8.5). */
    const val PAYLOAD_VERSION = 1

    fun build(
        details: ApprovalRequestDetails,
        userId: String,
        organizationId: String,
        purpose: String,
        decision: String,
        credentialPublicId: String,
    ): String {
        return listOf(
            "payload_version=$PAYLOAD_VERSION",
            "decision=$decision",
            "purpose=$purpose",
            details.requestId,
            details.challenge,
            userId,
            organizationId,
            details.role,
            details.serverOrigin,
            details.environment,
            details.browserSessionHash.orEmpty(),
            details.actionFingerprint.orEmpty(),
            details.issuedAt.toString(),
            details.expiresAt.toString(),
            details.nonce,
            credentialPublicId,
        ).joinToString(separator = "\n")
    }
}
