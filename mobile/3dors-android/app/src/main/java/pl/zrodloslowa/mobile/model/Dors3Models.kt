package pl.zrodloslowa.mobile.model

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass
import pl.zrodloslowa.mobile.BuildConfig

/**
 * Modele danych 3DORS Mobile odzwierciedlające payloady opisane w pkt 4, 5 i 6
 * dyspozycji. Rozdzielają jednoznacznie: tożsamość, urządzenie, credential
 * i operację zatwierdzaną — zgodnie z zasadą architektoniczną z pkt 1.
 */

/** Cel żądania rozpoznawanego przez telefon. */
enum class Dors3Purpose {
    @Json(name = "login") LOGIN,
    @Json(name = "operation") OPERATION,
    @Json(name = "enrollment") ENROLLMENT,
}

/** Status urządzenia po stronie serwera (pkt 8, tabela security_mobile_devices). */
enum class Dors3DeviceStatus {
    @Json(name = "pending") PENDING,
    @Json(name = "active") ACTIVE,
    @Json(name = "suspended") SUSPENDED,
    @Json(name = "lost") LOST,
    @Json(name = "revoked") REVOKED,
    @Json(name = "expired") EXPIRED,
}

/**
 * Dane zeskanowane z kodu QR rejestracji urządzenia (pkt 4.2 kroki 4-6).
 *
 * [enrollmentRequestId] to WYŁĄCZNIE identyfikator zgłoszenia rejestracyjnego
 * (rekordu oczekującego na potwierdzenie) — jest odrębny od `device_public_id`
 * (przydzielanego dopiero po utworzeniu klucza) i od `credential_public_id`
 * (identyfikującego konkretny klucz/credential telefonu). Rozdzielenie tych
 * trzech identyfikatorów jest wymagane przez dyspozycję (sekcja "Rejestracja
 * urządzenia").
 */
@JsonClass(generateAdapter = true)
data class EnrollmentQrPayload(
    val token: String,
    @Json(name = "enrollment_request_id") val enrollmentRequestId: String,
    val service: String,
    val environment: String,
    val organization: String,
    @Json(name = "user_display_name") val userDisplayName: String,
    val account: String,
    val role: String,
    val purpose: String,
    @Json(name = "protocol_version") val protocolVersion: Int = 1,
    @Json(name = "expires_at") val expiresAt: Long,
    @Json(name = "application_variant") val applicationVariant: String = BuildConfig.DORS3_APPLICATION_VARIANT,
)

/** Żądanie ukończenia rejestracji wysyłane po utworzeniu klucza w Keystore. */
@JsonClass(generateAdapter = true)
data class EnrollmentCompleteRequest(
    val token: String,
    @Json(name = "enrollment_request_id") val enrollmentRequestId: String,
    @Json(name = "public_key") val publicKey: String,
    val algorithm: String,
    @Json(name = "security_level") val securityLevel: String,
    @Json(name = "device_model") val deviceModel: String,
    @Json(name = "os_version") val osVersion: String,
    @Json(name = "app_version") val appVersion: String,
    @Json(name = "application_variant") val applicationVariant: String = BuildConfig.DORS3_APPLICATION_VARIANT,
)

@JsonClass(generateAdapter = true)
data class EnrollmentCompleteResponse(
    @Json(name = "device_public_id") val devicePublicId: String,
    /** Identyfikator konkretnego klucza/credentialu telefonu — odrębny od [devicePublicId]. */
    @Json(name = "credential_public_id") val credentialPublicId: String,
    @Json(name = "comparison_code") val comparisonCode: String,
    @Json(name = "api_token") val apiToken: String,
    @Json(name = "api_token_expires_at") val apiTokenExpiresAt: Long,
)

@JsonClass(generateAdapter = true)
data class EnrollmentConfirmRequest(
    @Json(name = "device_public_id") val devicePublicId: String,
    @Json(name = "comparison_code") val comparisonCode: String,
    val confirmed: Boolean,
)

/** Dokładny opis żądania (logowanie lub operacja) pobrany z serwera (pkt 5.2, 6.2, 6.3). */
@JsonClass(generateAdapter = true)
data class ApprovalRequestDetails(
    @Json(name = "request_id") val requestId: String,
    @Json(name = "public_id") val publicId: String,
    val purpose: String,
    val service: String,
    val environment: String,
    val account: String,
    val person: String,
    val role: String,
    val organization: String,
    @Json(name = "initiating_device") val initiatingDevice: String?,
    @Json(name = "action_type") val actionType: String?,
    /** Pary etykieta -> wartość do wyświetlenia dokładnie tak, jak przygotował serwer. */
    @Json(name = "display_fields") val displayFields: Map<String, String> = emptyMap(),
    val challenge: String,
    @Json(name = "action_fingerprint") val actionFingerprint: String?,
    @Json(name = "browser_session_hash") val browserSessionHash: String?,
    @Json(name = "issued_at") val issuedAt: Long,
    @Json(name = "expires_at") val expiresAt: Long,
    val nonce: String,
    @Json(name = "server_origin") val serverOrigin: String,
    @Json(name = "protocol_version") val protocolVersion: Int = 1,
    @Json(name = "application_variant") val applicationVariant: String = BuildConfig.DORS3_APPLICATION_VARIANT,
)

@JsonClass(generateAdapter = true)
data class DeviceStatusResponse(
    @Json(name = "device_public_id") val devicePublicId: String,
    @Json(name = "application_variant") val applicationVariant: String,
    val status: String,
    @Json(name = "last_used_at") val lastUsedAt: String?,
)

@JsonClass(generateAdapter = true)
data class DeviceHeartbeatRequest(
    @Json(name = "credential_public_id") val credentialPublicId: String,
    @Json(name = "application_variant") val applicationVariant: String = BuildConfig.DORS3_APPLICATION_VARIANT,
)

/** Podpisana odpowiedź wysyłana do serwera po zatwierdzeniu (pkt 5.4, 6.1). */
@JsonClass(generateAdapter = true)
data class ApprovalDecisionRequest(
    @Json(name = "device_public_id") val devicePublicId: String,
    @Json(name = "credential_public_id") val credentialPublicId: String,
    val signature: String,
    @Json(name = "signed_payload") val signedPayload: String,
    val algorithm: String,
)

@JsonClass(generateAdapter = true)
data class ApprovalDecisionResponse(
    val status: String,
    @Json(name = "consumed_at") val consumedAt: Long?,
)

@JsonClass(generateAdapter = true)
data class ApiErrorResponse(
    val error: String,
    val message: String? = null,
)
