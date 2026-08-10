package pl.zrodloslowa.mobile.biometric

import android.app.KeyguardManager
import android.content.Context
import android.os.Build
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.fragment.app.FragmentActivity
import java.security.Signature
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlinx.coroutines.suspendCancellableCoroutine

/**
 * Cienka warstwa nad [BiometricPrompt] zgodna z pkt 5.2/6.1 dyspozycji:
 * "Zatwierdzenie wymaga BiometricPrompt lub bezpiecznego PIN-u urządzenia."
 *
 * Używamy [BiometricManager.Authenticators.BIOMETRIC_STRONG] razem z
 * [BiometricManager.Authenticators.DEVICE_CREDENTIAL], żeby dopuścić bezpieczny
 * PIN/wzór ekranu blokady jako awaryjny sposób odblokowania klucza (zgodnie z
 * DORS3_MOBILE_ALLOW_DEVICE_CREDENTIAL=true), przy zachowaniu wymogu lokalnego
 * uwierzytelnienia dla każdego użycia klucza w Keystore.
 */
class BiometricAuthenticator(private val activity: FragmentActivity) : Dors3BiometricSigner {

    fun canAuthenticate(): Boolean {
        val manager = BiometricManager.from(activity)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            return manager.canAuthenticate(AUTHENTICATORS) == BiometricManager.BIOMETRIC_SUCCESS
        }

        val biometricAvailable = manager.canAuthenticate(
            BiometricManager.Authenticators.BIOMETRIC_STRONG,
        ) == BiometricManager.BIOMETRIC_SUCCESS
        val secureDeviceCredential =
            activity.getSystemService(Context.KEYGUARD_SERVICE) as KeyguardManager
        return biometricAvailable || secureDeviceCredential.isDeviceSecure
    }

    /**
     * Prosi użytkownika o biometrię/PIN i zwraca odblokowany [Signature] powiązany
     * z kluczem urządzenia (CryptoObject), gotowy do podpisania kanonicznego
     * payloadu operacji.
     */
    override suspend fun authenticateForSigning(
        title: String,
        subtitle: String,
        signatureProvider: () -> Signature,
    ): Signature = suspendCancellableCoroutine { continuation ->
        val usesCryptoObject = Build.VERSION.SDK_INT >= Build.VERSION_CODES.R
        val promptInfoBuilder = BiometricPrompt.PromptInfo.Builder()
            .setTitle(title)
            .setSubtitle(subtitle)
        if (usesCryptoObject) {
            promptInfoBuilder.setAllowedAuthenticators(AUTHENTICATORS)
        } else {
            @Suppress("DEPRECATION")
            promptInfoBuilder.setDeviceCredentialAllowed(true)
        }
        val promptInfo = promptInfoBuilder.build()

        val callback = object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                if (!continuation.isActive) return
                try {
                    val unlockedSignature = if (usesCryptoObject) {
                        result.cryptoObject?.signature
                            ?: throw IllegalStateException("Brak CryptoObject po udanej autoryzacji")
                    } else {
                        // Android 10 nie obsługuje DEVICE_CREDENTIAL z CryptoObject.
                        // Prompt najpierw otwiera krótki okres autoryzacji klucza,
                        // a Signature jest inicjalizowany dopiero po jego sukcesie.
                        signatureProvider()
                    }
                    continuation.resume(unlockedSignature)
                } catch (error: Throwable) {
                    continuation.resumeWithException(
                        error,
                    )
                }
            }

            override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                if (!continuation.isActive) return
                continuation.resumeWithException(
                    BiometricAuthException(errorCode, errString.toString()),
                )
            }

            override fun onAuthenticationFailed() {
                // Pojedyncza nieudana próba (np. zły odcisk) — BiometricPrompt sam
                // pozwala spróbować ponownie, nie przerywamy tutaj coroutine.
            }
        }

        val prompt = BiometricPrompt(activity, activity.mainExecutor, callback)
        continuation.invokeOnCancellation { prompt.cancelAuthentication() }
        if (usesCryptoObject) {
            val signature = try {
                signatureProvider()
            } catch (error: Throwable) {
                continuation.resumeWithException(error)
                return@suspendCancellableCoroutine
            }
            prompt.authenticate(promptInfo, BiometricPrompt.CryptoObject(signature))
        } else {
            prompt.authenticate(promptInfo)
        }
    }

    class BiometricAuthException(val errorCode: Int, message: String) : Exception(message)

    companion object {
        private val AUTHENTICATORS: Int =
            BiometricManager.Authenticators.BIOMETRIC_STRONG or
                BiometricManager.Authenticators.DEVICE_CREDENTIAL
    }
}
