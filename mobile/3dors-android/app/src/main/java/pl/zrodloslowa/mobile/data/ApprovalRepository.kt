package pl.zrodloslowa.mobile.data

import android.os.Build
import androidx.fragment.app.FragmentActivity
import com.squareup.moshi.Moshi
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import pl.zrodloslowa.mobile.BuildConfig
import pl.zrodloslowa.mobile.R
import pl.zrodloslowa.mobile.biometric.BiometricAuthenticator
import pl.zrodloslowa.mobile.biometric.Dors3BiometricSigner
import pl.zrodloslowa.mobile.config.EnvironmentConfig
import pl.zrodloslowa.mobile.crypto.CanonicalPayloadBuilder
import pl.zrodloslowa.mobile.crypto.Dors3KeystoreManager
import pl.zrodloslowa.mobile.crypto.Dors3SigningKeyStore
import pl.zrodloslowa.mobile.model.ApiErrorResponse
import pl.zrodloslowa.mobile.model.ApprovalDecisionRequest
import pl.zrodloslowa.mobile.model.ApprovalDecisionResponse
import pl.zrodloslowa.mobile.model.ApprovalRequestDetails
import pl.zrodloslowa.mobile.network.Dors3ApiService
import pl.zrodloslowa.mobile.variant.VariantPolicy
import retrofit2.Response

/**
 * Realizuje przepływ zatwierdzania/odrzucania z pkt 6.1 dyspozycji (kroki 6-11):
 * pobranie operacji → wyświetlenie danych → biometria → podpis dokładnego
 * `action_fingerprint`/challenge → wysłanie do backendu.
 *
 * Zależności krytyczne dla bezpieczeństwa (magazyn credentiali, fabryka klucza
 * Keystore, zegar) są wstrzykiwane jako interfejsy/funkcje, aby dało się
 * przetestować jednostkowo (JVM) każdy z wymaganych scenariuszy: spójny alias
 * klucza, brak credential_public_id, wygasanie na każdym etapie, błędny
 * origin/środowisko/wersję protokołu (patrz `ApprovalRepositoryTest`).
 */
class ApprovalRepository(
    private val apiService: Dors3ApiService,
    private val credentialStore: Dors3CredentialStore,
    private val keystoreFactory: (String) -> Dors3SigningKeyStore = { alias -> Dors3KeystoreManager(alias) },
    private val biometricSignerFactory: (FragmentActivity?) -> Dors3BiometricSigner = { activity ->
        BiometricAuthenticator(requireNotNull(activity) { "Aktywność jest wymagana do wywołania BiometricPrompt" })
    },
    private val nowEpochSecondsProvider: () -> Long = { System.currentTimeMillis() / 1000 },
    private val expectedOriginHost: String? = null,
) {

    private val errorAdapter = Moshi.Builder()
        .add(KotlinJsonAdapterFactory())
        .build()
        .adapter(ApiErrorResponse::class.java)

    suspend fun fetchRequest(publicId: String): Result<ApprovalRequestDetails> {
        return try {
            val response = apiService.getApprovalRequest(publicId, deviceAuthorization())
            val body = response.body()
            if (response.isSuccessful && body != null) {
                validateRequestDetails(body)?.let { return Result.failure(it) }
                Result.success(body)
            } else {
                Result.failure(mapServerError(response))
            }
        } catch (exception: Exception) {
            Result.failure(exception)
        }
    }

    /**
     * Ekran startowy automatycznie szuka aktywnego żądania z pobliskiego
     * urządzenia (Effective Issue: "prosta aplikacja uwierzytelniająca").
     * Zwraca `null`, gdy w danej chwili nie ma żadnego oczekującego żądania —
     * to jest stan normalny, a nie błąd (204/404 z backendu).
     *
     * Zgodnie z pkt "Walidacja żądań" dyspozycji, pełna walidacja (wersja
     * protokołu, środowisko, origin, purpose, nonce, action_fingerprint,
     * identyfikatory) obowiązuje RÓWNIEŻ dla żądań wykrywanych automatycznie —
     * telefon nigdy nie pokazuje ekranu decyzji dla nieprawidłowego żądania,
     * nawet jeśli "wygląda" na aktywne.
     */
    suspend fun findPendingRequest(devicePublicId: String): Result<ApprovalRequestDetails?> {
        return try {
            val response = apiService.getPendingRequestForDevice(devicePublicId, deviceAuthorization())
            when {
                response.code() == 204 || response.code() == 404 -> Result.success(null)
                response.isSuccessful -> {
                    val body = response.body()
                    if (body == null) {
                        Result.success(null)
                    } else {
                        validateRequestDetails(body)?.let { return Result.failure(it) }
                        Result.success(body)
                    }
                }
                else -> Result.failure(mapServerError(response))
            }
        } catch (exception: Exception) {
            Result.failure(exception)
        }
    }

    /**
     * Podpisuje kanoniczny payload żądania po udanej biometrii/PIN (pkt 5.4, 6.1)
     * i wysyła decyzję (approve/reject) do backendu. Zwraca odpowiedź serwera
     * — dopiero po niej operacja jest uznana za zatwierdzoną (atomowe zużycie
     * zgody, pkt 6.1 krok 11-12).
     *
     * TTL żądania jest sprawdzany w PIĘCIU punktach (dyspozycja, pkt "TTL"):
     * po pobraniu żądania (wcześniej, przy [fetchRequest]/ViewModel), tutaj
     * przed BiometricPrompt, po udanej biometrii, bezpośrednio przed podpisem
     * i tuż przed wysłaniem do serwera — żądanie wygasłe na KTÓRYMKOLWIEK z
     * tych etapów nie zostanie podpisane/wysłane.
     *
     * Zgodnie z ETAP 3 dyspozycji dodano lokalną ochronę przed replay: telefon
     * nie podpisze drugi raz tego samego `request_id`, nawet jeśli zostanie
     * poproszony (np. dwukrotne otwarcie tego samego linku/QR).
     */
    suspend fun approveOrReject(
        activity: FragmentActivity?,
        details: ApprovalRequestDetails,
        approve: Boolean,
    ): Result<ApprovalDecisionResponse> {
        val devicePublicId = credentialStore.devicePublicId
            ?: return Result.failure(IllegalStateException("Urządzenie nie jest zarejestrowane"))

        // Bez fallbacku: brak credential_public_id kończy operację kontrolowanym
        // błędem bezpieczeństwa (naprawa błędu z dyspozycji: "Credential ID") —
        // device_public_id i credential_public_id muszą pozostać rozdzielone,
        // nigdy nie wolno podstawiać jednego w miejsce drugiego.
        val credentialPublicId = credentialStore.credentialPublicId
            ?: return Result.failure(MissingCredentialException())
        val keyAlias = credentialStore.keyAlias
            ?: return Result.failure(MissingCredentialException())

        if (credentialStore.deviceStatus == "suspended") {
            return Result.failure(DeviceSuspendedException())
        }
        if (credentialStore.deviceStatus == "revoked") {
            return Result.failure(DeviceRevokedException())
        }

        validateRequestDetails(details)?.let { return Result.failure(it) }

        if (credentialStore.isRequestConsumedLocally(details.requestId, nowEpochSecondsProvider())) {
            return Result.failure(RequestAlreadyProcessedException())
        }

        return try {
            // TTL checkpoint #2: bezpośrednio przed BiometricPrompt.
            ensureNotExpired(details)

            // Alias klucza jest odczytywany WYŁĄCZNIE z magazynu credentiali —
            // dokładnie ten sam, który został ustalony i zapisany raz podczas
            // rejestracji (naprawa błędu z dyspozycji: "Alias Android Keystore").
            // NIGDY nie wolno budować go ponownie z device_public_id ani
            // enrollment_request_id.
            val keystoreManager = keystoreFactory(keyAlias)
            val decision = if (approve) "approve" else "reject"
            // Tożsamość osoby i organizacji pochodzą WYŁĄCZNIE z opisu żądania
            // zweryfikowanego przez serwer (details.account/organization) — telefon
            // nigdy nie zgaduje ani nie podstawia własnego identyfikatora urządzenia
            // w ich miejsce (błąd, który wcześniej powodował podpisywanie pustego
            // organization_id).
            val canonicalPayload = CanonicalPayloadBuilder.build(
                details = details,
                userId = details.account,
                organizationId = details.organization,
                purpose = details.purpose,
                decision = decision,
                credentialPublicId = credentialPublicId,
            )

            // Zgodnie z pkt 8.3 dyspozycji: odrzucenie jest także świadomą,
            // audytowalną decyzją i NIE MOŻE być wysyłane z pustym podpisem.
            // Preferujemy podpis po BiometricPrompt również dla reject, aby serwer
            // miał dowód, że decyzję podjął właściciel urządzenia.
            val biometricSigner = biometricSignerFactory(activity)
            val unlockedSignature = biometricSigner.authenticateForSigning(
                title = activity?.getString(R.string.product_label) ?: BuildConfig.APPLICATION_ID,
                subtitle = if (approve) {
                    activity?.getString(R.string.biometric_approve_subtitle) ?: BuildConfig.APPLICATION_ID
                } else {
                    activity?.getString(R.string.biometric_reject_subtitle) ?: BuildConfig.APPLICATION_ID
                },
                signatureProvider = keystoreManager::createSignatureForAuthentication,
            )

            // TTL checkpoint #3: po udanej biometrii.
            ensureNotExpired(details)
            // TTL checkpoint #4: bezpośrednio przed podpisem.
            ensureNotExpired(details)
            val signatureBase64 = keystoreManager.sign(unlockedSignature, canonicalPayload)

            val decisionRequest = ApprovalDecisionRequest(
                devicePublicId = devicePublicId,
                credentialPublicId = credentialPublicId,
                signature = signatureBase64,
                signedPayload = canonicalPayload,
                algorithm = Dors3KeystoreManager.SIGNATURE_ALGORITHM,
            )

            // TTL checkpoint #5: tuż przed wysłaniem do serwera.
            ensureNotExpired(details)

            val response = if (approve) {
                apiService.approveRequest(details.publicId, decisionRequest)
            } else {
                apiService.rejectRequest(details.publicId, decisionRequest)
            }

            val body = response.body()
            if (response.isSuccessful && body != null) {
                credentialStore.markRequestConsumedLocally(details.requestId, details.expiresAt, nowEpochSecondsProvider())
                Result.success(body)
            } else {
                Result.failure(mapServerError(response))
            }
        } catch (exception: Exception) {
            Result.failure(exception)
        }
    }

    fun isExpired(details: ApprovalRequestDetails, nowEpochSeconds: Long): Boolean {
        return nowEpochSeconds >= details.expiresAt
    }

    private fun ensureNotExpired(details: ApprovalRequestDetails) {
        if (isExpired(details, nowEpochSecondsProvider())) {
            throw RequestExpiredException()
        }
    }

    /**
     * Pełna walidacja żądania (dyspozycja, pkt "Walidacja żądań"): wersja
     * protokołu, środowisko, origin serwera, purpose, nonce, action_fingerprint
     * (wymagany dla operacji) oraz identyfikator użytkownika/organizacji.
     * Zwraca wyjątek, jeśli którykolwiek warunek nie jest spełniony, albo
     * `null`, gdy żądanie jest poprawne.
     */
    private fun validateRequestDetails(details: ApprovalRequestDetails): Exception? {
        if (details.protocolVersion != EnvironmentConfig.SUPPORTED_PROTOCOL_VERSION) {
            return UnsupportedProtocolVersionException(details.protocolVersion)
        }
        if (details.applicationVariant != VariantPolicy.applicationVariant) {
            return InvalidRequestException("Żądanie jest przeznaczone dla wariantu ${details.applicationVariant}")
        }
        if (details.environment != EnvironmentConfig.ENVIRONMENT_LABEL) {
            return InvalidRequestException("Żądanie dotyczy innego środowiska: ${details.environment}")
        }
        val expectedHost = expectedOriginHost
        if (!expectedHost.isNullOrBlank() && extractHost(details.serverOrigin) != expectedHost) {
            return InvalidRequestException("Nieprawidłowe pochodzenie żądania (server_origin)")
        }
        if (details.purpose != "login" && details.purpose != "operation") {
            return InvalidRequestException("Nieznany cel żądania (purpose): ${details.purpose}")
        }
        if (details.nonce.isBlank()) {
            return InvalidRequestException("Brak nonce w żądaniu")
        }
        if (details.purpose == "operation" && details.actionFingerprint.isNullOrBlank()) {
            return InvalidRequestException("Brak action_fingerprint dla operacji")
        }
        if (details.purpose == "operation" && !VariantPolicy.accepts(details.actionType)) {
            return InvalidRequestException("Ten wariant aplikacji nie obsługuje operacji: ${details.actionType.orEmpty()}")
        }
        if (details.account.isBlank() || details.organization.isBlank()) {
            return InvalidRequestException("Brak identyfikatora użytkownika lub organizacji w żądaniu")
        }
        return null
    }

    private fun extractHost(origin: String): String? {
        // Prosta, zależna wyłącznie od czystego Kotlina ekstrakcja hosta (bez
        // android.net.Uri), żeby walidację dało się w pełni przetestować w JVM.
        val withoutScheme = origin.substringAfter("://", origin)
        val hostAndPort = withoutScheme.substringBefore("/")
        return hostAndPort.substringBefore(":").takeIf { it.isNotBlank() }
    }

    private fun deviceAuthorization(): String {
        val credentialPublicId = credentialStore.credentialPublicId ?: throw MissingCredentialException()
        val apiToken = credentialStore.apiToken ?: throw MissingCredentialException()
        val expiresAt = credentialStore.apiTokenExpiresAt ?: throw MissingCredentialException()
        if (nowEpochSecondsProvider() >= expiresAt) {
            throw DeviceTokenExpiredException()
        }
        return "Dors3Device $credentialPublicId.$apiToken"
    }

    /** Tłumaczy kod błędu serwera na typowany wyjątek (pkt "obsługa urządzenia zawieszonego i unieważnionego"). */
    private fun mapServerError(response: Response<*>): Exception {
        val errorBody = response.errorBody()?.string()
        val errorCode = errorBody?.let { runCatching { errorAdapter.fromJson(it) }.getOrNull()?.error }
        return when (errorCode) {
            "device_suspended" -> DeviceSuspendedException()
            "device_revoked" -> DeviceRevokedException()
            "device_lost" -> DeviceRevokedException()
            "request_expired" -> RequestExpiredException()
            "request_already_processed" -> RequestAlreadyProcessedException()
            else -> IllegalStateException("Błąd serwera: ${response.code()}")
        }
    }

    class DeviceSuspendedException : Exception("Urządzenie jest zawieszone przez administratora")
    class DeviceRevokedException : Exception("Urządzenie zostało unieważnione — wymagana ponowna rejestracja")
    class RequestAlreadyProcessedException : Exception("To żądanie zostało już przetworzone")
    class RequestExpiredException : Exception("Żądanie wygasło")
    class DeviceTokenExpiredException : Exception("Token urządzenia wygasł — wymagana ponowna rejestracja")
    class UnsupportedProtocolVersionException(version: Int) :
        Exception("Nieobsługiwana wersja protokołu żądania: $version")
    class InvalidRequestException(message: String) : Exception(message)

    /** Kontrolowany błąd bezpieczeństwa — brak credential_public_id lub key_alias NIE MOŻE zostać obsłużony fallbackiem. */
    class MissingCredentialException :
        Exception("Brak zarejestrowanego credentialu telefonu (credential_public_id/key_alias) — wymagana ponowna rejestracja")

    companion object {
        fun currentDeviceModel(): String = "${Build.MANUFACTURER} ${Build.MODEL}"
        fun currentOsVersion(): String = "Android ${Build.VERSION.RELEASE}"
        fun currentAppVersion(): String = BuildConfig.VERSION_NAME
    }
}
