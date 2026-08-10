package pl.zrodloslowa.mobile.testutil

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Protocol
import okhttp3.Request
import okhttp3.ResponseBody.Companion.toResponseBody
import pl.zrodloslowa.mobile.biometric.Dors3BiometricSigner
import pl.zrodloslowa.mobile.crypto.Dors3KeystoreManager
import pl.zrodloslowa.mobile.crypto.Dors3SigningKeyStore
import pl.zrodloslowa.mobile.data.Dors3CredentialStore
import pl.zrodloslowa.mobile.model.ApprovalDecisionRequest
import pl.zrodloslowa.mobile.model.ApprovalDecisionResponse
import pl.zrodloslowa.mobile.model.ApprovalRequestDetails
import pl.zrodloslowa.mobile.model.EnrollmentCompleteRequest
import pl.zrodloslowa.mobile.model.EnrollmentCompleteResponse
import pl.zrodloslowa.mobile.model.EnrollmentConfirmRequest
import pl.zrodloslowa.mobile.model.DeviceHeartbeatRequest
import pl.zrodloslowa.mobile.model.DeviceStatusResponse
import pl.zrodloslowa.mobile.network.Dors3ApiService
import retrofit2.Response
import java.security.Signature

/** Buduje odpowiedź Retrofit z dowolnym kodem HTTP (204/404/409/...) bez treści. */
fun <T> emptyResponseWithCode(code: Int): Response<T> {
    val raw = okhttp3.Response.Builder()
        .code(code)
        .message("test")
        .protocol(Protocol.HTTP_1_1)
        .request(Request.Builder().url("http://localhost/").build())
        .build()
    return Response.success(null, raw)
}

/** Buduje odpowiedź błędu Retrofit z treścią JSON (np. `{"error":"device_revoked"}`). */
fun <T> errorResponseWithBody(code: Int, json: String): Response<T> {
    return Response.error(json.toResponseBody("application/json".toMediaType()), okhttpErrorRaw(code))
}

private fun okhttpErrorRaw(code: Int): okhttp3.Response = okhttp3.Response.Builder()
    .code(code)
    .message("test")
    .protocol(Protocol.HTTP_1_1)
    .request(Request.Builder().url("http://localhost/").build())
    .build()

/** Prosty fejk [Dors3CredentialStore] w pamięci — bez realnego Android Context. */
class InMemoryCredentialStore : Dors3CredentialStore {
    override var devicePublicId: String? = null
    override var credentialPublicId: String? = null
    override var apiToken: String? = null
    override var apiTokenExpiresAt: Long? = null
    override var keyAlias: String? = null
    override var deviceStatus: String? = null
    override var apiBaseUrlOverride: String? = null

    private val consumed = mutableMapOf<String, Long>()

    override fun isRegistered(): Boolean = devicePublicId != null

    override fun isRequestConsumedLocally(requestId: String, nowEpochSeconds: Long): Boolean {
        val expiresAt = consumed[requestId] ?: return false
        return expiresAt >= nowEpochSeconds
    }

    override fun markRequestConsumedLocally(requestId: String, expiresAtEpochSeconds: Long, nowEpochSeconds: Long) {
        consumed[requestId] = expiresAtEpochSeconds
    }

    override fun clear() {
        devicePublicId = null
        credentialPublicId = null
        apiToken = null
        apiTokenExpiresAt = null
        keyAlias = null
        deviceStatus = null
        apiBaseUrlOverride = null
        consumed.clear()
    }
}

/**
 * Fejk [Dors3SigningKeyStore] — zapamiętuje alias, którym został utworzony
 * (przez [FakeKeystoreFactory]), bez dotykania realnego Android Keystore.
 * Testy weryfikują, że EnrollmentRepository i ApprovalRepository proszą o
 * DOKŁADNIE ten sam alias (naprawa błędu "Alias Android Keystore").
 */
class FakeSigningKeyStore(val requestedAlias: String) : Dors3SigningKeyStore {
    var deleted = false
        private set
    var signCallCount = 0
        private set

    override fun hasKey(): Boolean = true

    override fun generateKeyPair(requireStrongBox: Boolean): Dors3KeystoreManager.GeneratedKeyInfo =
        Dors3KeystoreManager.GeneratedKeyInfo(
            publicKeyBase64 = "fake-public-key",
            algorithm = "SHA256withECDSA",
            securityLevel = Dors3KeystoreManager.SecurityLevel.SOFTWARE,
        )

    override fun createSignatureForAuthentication(): Signature = Signature.getInstance("SHA256withECDSA")

    override fun sign(signature: Signature, canonicalPayload: String): String {
        signCallCount++
        return "fake-signature-for($requestedAlias)"
    }

    override fun deleteKey() {
        deleted = true
    }
}

/**
 * Fejk [Dors3BiometricSigner] — symuluje udaną biometrię bez realnego
 * [androidx.biometric.BiometricPrompt]. Opcjonalny [beforeSign] pozwala
 * testom wykonać dowolną akcję (np. przesunąć zegar) DOKŁADNIE w momencie
 * "po udanej biometrii", żeby zweryfikować punkty kontrolne TTL.
 */
class FakeBiometricSigner(private val beforeSign: () -> Unit = {}) : Dors3BiometricSigner {
    var callCount = 0
        private set

    override suspend fun authenticateForSigning(
        title: String,
        subtitle: String,
        signatureProvider: () -> Signature,
    ): Signature {
        callCount++
        beforeSign()
        return signatureProvider()
    }
}

/** Rejestruje wszystkie żądane aliasy, aby testy mogły zweryfikować ich spójność. */
class FakeKeystoreFactory {
    val requestedAliases = mutableListOf<String>()
    val instances = mutableMapOf<String, FakeSigningKeyStore>()

    val factory: (String) -> Dors3SigningKeyStore = { alias ->
        requestedAliases.add(alias)
        instances.getOrPut(alias) { FakeSigningKeyStore(alias) }
    }
}

/** Prosty, w pełni kontrolowany fejk [Dors3ApiService] do testów jednostkowych (JVM, bez sieci). */
class FakeDors3ApiService : Dors3ApiService {

    var enrollmentCompleteResponse: Response<EnrollmentCompleteResponse> =
        Response.success(EnrollmentCompleteResponse("device-1", "cred-1", "482913", "test-api-token", 4_102_444_800L))
    var lastEnrollmentCompleteRequest: EnrollmentCompleteRequest? = null

    var confirmEnrollmentResponse: Response<Unit> = Response.success(Unit)
    var lastEnrollmentConfirmRequest: EnrollmentConfirmRequest? = null
    var deviceStatusResponse: Response<DeviceStatusResponse> =
        Response.success(DeviceStatusResponse("device-1", pl.zrodloslowa.mobile.BuildConfig.DORS3_APPLICATION_VARIANT, "active", null))

    var pendingRequestResponse: Response<ApprovalRequestDetails> = emptyResponseWithCode(204)
    var approvalRequestResponse: Response<ApprovalRequestDetails>? = null

    var decisionResponse: Response<ApprovalDecisionResponse> =
        Response.success(ApprovalDecisionResponse("approved", 1000L))
    var lastDecisionRequest: ApprovalDecisionRequest? = null
    var approveCallCount = 0
    var rejectCallCount = 0

    override suspend fun completeEnrollment(request: EnrollmentCompleteRequest): Response<EnrollmentCompleteResponse> {
        lastEnrollmentCompleteRequest = request
        return enrollmentCompleteResponse
    }

    override suspend fun confirmEnrollment(
        deviceAuthorization: String,
        request: EnrollmentConfirmRequest,
    ): Response<Unit> {
        lastEnrollmentConfirmRequest = request
        return confirmEnrollmentResponse
    }

    override suspend fun getPendingRequestForDevice(
        devicePublicId: String,
        deviceAuthorization: String,
    ): Response<ApprovalRequestDetails> =
        pendingRequestResponse

    override suspend fun getDeviceStatus(
        devicePublicId: String,
        deviceAuthorization: String,
    ): Response<DeviceStatusResponse> = deviceStatusResponse

    override suspend fun heartbeat(
        devicePublicId: String,
        deviceAuthorization: String,
        request: DeviceHeartbeatRequest,
    ): Response<Unit> =
        Response.success(Unit)

    override suspend fun getApprovalRequest(
        publicId: String,
        deviceAuthorization: String,
    ): Response<ApprovalRequestDetails> =
        approvalRequestResponse ?: emptyResponseWithCode(404)

    override suspend fun approveRequest(
        publicId: String,
        request: ApprovalDecisionRequest,
    ): Response<ApprovalDecisionResponse> {
        approveCallCount++
        lastDecisionRequest = request
        return decisionResponse
    }

    override suspend fun rejectRequest(
        publicId: String,
        request: ApprovalDecisionRequest,
    ): Response<ApprovalDecisionResponse> {
        rejectCallCount++
        lastDecisionRequest = request
        return decisionResponse
    }
}

fun sampleApprovalRequestDetails(
    requestId: String = "req-1",
    publicId: String = "public-1",
    purpose: String = "login",
    environment: String = "LOKALNE",
    account: String = "jan.kowalski",
    organization: String = "Źródło Słowa",
    actionFingerprint: String? = "fingerprint-1",
    nonce: String = "nonce-xyz",
    serverOrigin: String = "https://3dors.przyklad-domeny.pl/session/abc",
    issuedAt: Long = 1000L,
    expiresAt: Long = 1060L,
    protocolVersion: Int = 1,
) = ApprovalRequestDetails(
    requestId = requestId,
    publicId = publicId,
    purpose = purpose,
    service = "Źródło Słowa",
    environment = environment,
    account = account,
    person = "Jan Kowalski",
    role = "dziennikarz",
    organization = organization,
    initiatingDevice = "Komputer biurowy",
    actionType = null,
    displayFields = emptyMap(),
    challenge = "challenge-abc",
    actionFingerprint = actionFingerprint,
    browserSessionHash = "session-hash",
    issuedAt = issuedAt,
    expiresAt = expiresAt,
    nonce = nonce,
    serverOrigin = serverOrigin,
    protocolVersion = protocolVersion,
)
