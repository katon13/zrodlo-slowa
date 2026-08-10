package pl.zrodloslowa.mobile.data

import pl.zrodloslowa.mobile.config.EnvironmentConfig
import pl.zrodloslowa.mobile.variant.VariantPolicy
import pl.zrodloslowa.mobile.crypto.Dors3KeystoreManager
import pl.zrodloslowa.mobile.crypto.Dors3SigningKeyStore
import pl.zrodloslowa.mobile.model.EnrollmentCompleteRequest
import pl.zrodloslowa.mobile.model.EnrollmentCompleteResponse
import pl.zrodloslowa.mobile.model.EnrollmentConfirmRequest
import pl.zrodloslowa.mobile.model.EnrollmentQrPayload
import pl.zrodloslowa.mobile.network.Dors3ApiService

/**
 * Realizuje rejestrację telefonu zgodnie z pkt 4.2 dyspozycji (kroki 7-13):
 * utworzenie klucza w Android Keystore (bez opuszczania telefonu), wysłanie
 * klucza publicznego i metadanych oraz pokazanie kodu porównawczego. Aktywację
 * wykonuje wyłącznie administrator w powiązanej sesji panelu.
 */
class EnrollmentRepository(
    private val apiService: Dors3ApiService,
    private val credentialStore: Dors3CredentialStore,
    private val keystoreFactory: (String) -> Dors3SigningKeyStore = { alias -> Dors3KeystoreManager(alias) },
) {

    /**
     * Tworzy parę kluczy w Keystore dla NOWEGO, jeszcze nieznanego identyfikatora
     * urządzenia (lokalny UUID, zanim backend przydzieli `device_public_id`) i
     * wysyła dane rejestracyjne. Klucz prywatny nigdy nie opuszcza telefonu.
     */
    suspend fun completeEnrollment(
        qrPayload: EnrollmentQrPayload,
        appVersion: String,
        deviceModel: String,
        osVersion: String,
    ): Result<EnrollmentResult> {
        // Walidacja TTL kodu QR PRZED utworzeniem klucza w Keystore (dyspozycja,
        // ETAP 2: "walidacja hosta, środowiska, TTL i wersji protokołu") — nie ma
        // sensu tworzyć klucza sprzętowego dla już nieważnego zgłoszenia.
        val nowEpochSeconds = System.currentTimeMillis() / 1000
        if (nowEpochSeconds >= qrPayload.expiresAt) {
            return Result.failure(IllegalStateException("Kod QR rejestracji wygasł"))
        }
        if (qrPayload.protocolVersion != EnvironmentConfig.SUPPORTED_PROTOCOL_VERSION) {
            return Result.failure(IllegalStateException("Nieobsługiwana wersja protokołu rejestracji: ${qrPayload.protocolVersion}"))
        }
        if (qrPayload.environment != EnvironmentConfig.ENVIRONMENT_LABEL) {
            return Result.failure(
                IllegalStateException(
                    "Kod QR dotyczy innego środowiska (${qrPayload.environment}) niż ten build aplikacji (${EnvironmentConfig.ENVIRONMENT_LABEL})",
                ),
            )
        }
        if (qrPayload.applicationVariant != VariantPolicy.applicationVariant) {
            return Result.failure(
                IllegalStateException("Kod QR jest przeznaczony dla aplikacji ${qrPayload.applicationVariant}, a nie ${VariantPolicy.applicationVariant}"),
            )
        }

        return try {
            // Alias klucza w Keystore MUSI być niezależny od enrollment_request_id
            // (tymczasowy, ginie po rejestracji) i od device_public_id (przydzielany
            // dopiero po utworzeniu klucza) — losujemy WŁASNY, trwały identyfikator
            // RAZ, tutaj, i zapisujemy go przy credentialu (naprawa błędu z
            // dyspozycji: "Alias Android Keystore"). Ten sam alias musi być
            // później używany identycznie przy approve i reject.
            val keyAlias = java.util.UUID.randomUUID().toString()
            val keystoreManager = keystoreFactory(keyAlias)
            val generatedKey = keystoreManager.generateKeyPair()

            val request = EnrollmentCompleteRequest(
                token = qrPayload.token,
                enrollmentRequestId = qrPayload.enrollmentRequestId,
                publicKey = generatedKey.publicKeyBase64,
                algorithm = generatedKey.algorithm,
                securityLevel = generatedKey.securityLevel.name,
                deviceModel = deviceModel,
                osVersion = osVersion,
                appVersion = appVersion,
                applicationVariant = VariantPolicy.applicationVariant,
            )

            val response = apiService.completeEnrollment(request)
            val body = response.body()
            if (!response.isSuccessful || body == null) {
                keystoreManager.deleteKey()
                return Result.failure(IllegalStateException("Rejestracja nieudana: ${response.code()}"))
            }

            credentialStore.devicePublicId = body.devicePublicId
            credentialStore.credentialPublicId = body.credentialPublicId
            credentialStore.apiToken = body.apiToken
            credentialStore.apiTokenExpiresAt = body.apiTokenExpiresAt
            credentialStore.keyAlias = keyAlias
            credentialStore.deviceStatus = "pending"

            Result.success(EnrollmentResult(body.devicePublicId, body.credentialPublicId, body.comparisonCode))
        } catch (exception: Exception) {
            Result.failure(exception)
        }
    }

    suspend fun rejectEnrollment(devicePublicId: String, comparisonCode: String): Result<Unit> {
        return try {
            val credentialPublicId = credentialStore.credentialPublicId
                ?: return Result.failure(IllegalStateException("Brak credentialu urządzenia"))
            val apiToken = credentialStore.apiToken
                ?: return Result.failure(IllegalStateException("Brak tokenu uwierzytelniającego urządzenie"))
            val response = apiService.confirmEnrollment(
                deviceAuthorization(credentialPublicId, apiToken),
                EnrollmentConfirmRequest(
                    devicePublicId = devicePublicId,
                    comparisonCode = comparisonCode,
                    confirmed = false,
                ),
            )
            if (response.isSuccessful) {
                credentialStore.keyAlias?.let { keystoreFactory(it).deleteKey() }
                credentialStore.clear()
                Result.success(Unit)
            } else {
                Result.failure(IllegalStateException("Odrzucenie rejestracji nieudane: ${response.code()}"))
            }
        } catch (exception: Exception) {
            Result.failure(exception)
        }
    }

    suspend fun activationStatus(devicePublicId: String): Result<String> {
        return try {
            val credentialPublicId = credentialStore.credentialPublicId
                ?: return Result.failure(IllegalStateException("Brak credentialu urządzenia"))
            val apiToken = credentialStore.apiToken
                ?: return Result.failure(IllegalStateException("Brak tokenu uwierzytelniającego urządzenie"))
            val response = apiService.getDeviceStatus(
                devicePublicId,
                deviceAuthorization(credentialPublicId, apiToken),
            )
            val body = response.body()
            if (!response.isSuccessful || body == null || body.devicePublicId != devicePublicId) {
                Result.failure(IllegalStateException("Nie udało się sprawdzić aktywacji: ${response.code()}"))
            } else {
                credentialStore.deviceStatus = body.status
                Result.success(body.status)
            }
        } catch (exception: Exception) {
            Result.failure(exception)
        }
    }

    data class EnrollmentResult(
        val devicePublicId: String,
        val credentialPublicId: String,
        val comparisonCode: String,
    )

    private fun deviceAuthorization(credentialPublicId: String, apiToken: String): String =
        "Dors3Device $credentialPublicId.$apiToken"
}
