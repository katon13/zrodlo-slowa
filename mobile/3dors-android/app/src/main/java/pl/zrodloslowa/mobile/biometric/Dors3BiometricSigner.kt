package pl.zrodloslowa.mobile.biometric

import java.security.Signature

/**
 * Abstrakcja kroku "biometria/PIN → odblokowany podpis" wykorzystywana przez
 * [pl.zrodloslowa.mobile.data.ApprovalRepository].
 *
 * Wydzielona jako interfejs (implementowany produkcyjnie przez
 * [BiometricAuthenticator], oparty o realny [androidx.biometric.BiometricPrompt]
 * i [androidx.fragment.app.FragmentActivity]), aby logikę zatwierdzania/
 * odrzucania (TTL, walidacja, replay) dało się przetestować jednostkowo (JVM)
 * bez uruchamiania realnego okna biometrii.
 */
interface Dors3BiometricSigner {

    suspend fun authenticateForSigning(
        title: String,
        subtitle: String,
        signatureProvider: () -> Signature,
    ): Signature
}
