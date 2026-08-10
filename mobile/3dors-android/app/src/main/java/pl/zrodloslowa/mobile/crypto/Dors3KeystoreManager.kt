package pl.zrodloslowa.mobile.crypto

import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyInfo
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyFactory
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.PrivateKey
import java.security.Signature

/**
 * Zarządza kluczem kryptograficznym telefonu w Android Keystore, zgodnie z pkt 7
 * dyspozycji:
 * - asymetryczna para kluczy EC (secp256r1 / ECDSA-SHA256) — wspierana sprzętowo
 *   i łatwa do zweryfikowania po stronie PHP (openssl_verify z algorytmem
 *   OPENSSL_ALGO_SHA256 i kluczem EC);
 * - klucz prywatny NIGDY nie opuszcza Keystore (setIsStrongBoxBacked, gdy dostępny,
 *   z bezpiecznym fallbackiem);
 * - [setUserAuthenticationRequired] wymusza biometrię/PIN przy KAŻDYM użyciu klucza;
 * - po reinstalacji aplikacji AndroidKeyStore czyści alias — stary credential
 *   przestaje istnieć i wymaga ponownej rejestracji (pkt 7, ostatni punkt).
 */
class Dors3KeystoreManager(
    keyAliasSeed: String,
) : Dors3SigningKeyStore {
    /**
     * Jeden, TRWAŁY alias klucza w Android Keystore — nadany raz przy rejestracji
     * (patrz [pl.zrodloslowa.mobile.data.DeviceCredentialStore.keyAlias]) i
     * używany identycznie przy KAŻDYM kolejnym podpisie (approve/reject).
     * Alias NIE MOŻE zależeć od `enrollment_request_id` (tymczasowy, ginie po
     * rejestracji) ani być wyszukiwany po `device_public_id` — obie wartości są
     * odrębne od faktycznego aliasu klucza (naprawa błędu z dyspozycji: "Alias
     * Android Keystore").
     */
    val keyAlias: String = "$KEY_ALIAS_PREFIX$keyAliasSeed"

    private val keyStore: KeyStore by lazy {
        KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
    }

    override fun hasKey(): Boolean = keyStore.containsAlias(keyAlias)

    /**
     * Tworzy nową parę kluczy w Keystore. Zwraca klucz publiczny w formacie
     * Base64(X.509 SubjectPublicKeyInfo) do wysłania do backendu oraz informację
     * o poziomie ochrony sprzętowej (pkt 7 — "informacja o poziomie ochrony
     * zapisana w metadanych urządzenia").
     */
    override fun generateKeyPair(requireStrongBox: Boolean): GeneratedKeyInfo {
        val purposes = KeyProperties.PURPOSE_SIGN or KeyProperties.PURPOSE_VERIFY
        val specBuilder = KeyGenParameterSpec.Builder(keyAlias, purposes)
            .setAlgorithmParameterSpec(java.security.spec.ECGenParameterSpec("secp256r1"))
            .setDigests(KeyProperties.DIGEST_SHA256)
            .setUserAuthenticationRequired(true)

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            specBuilder.setUserAuthenticationParameters(
                0,
                KeyProperties.AUTH_BIOMETRIC_STRONG or KeyProperties.AUTH_DEVICE_CREDENTIAL,
            )
        } else {
            @Suppress("DEPRECATION")
            specBuilder.setUserAuthenticationValidityDurationSeconds(LEGACY_AUTH_WINDOW_SECONDS)
        }

        val securityLevel = if (requireStrongBox) {
            try {
                specBuilder.setIsStrongBoxBacked(true)
                val generator = KeyPairGenerator.getInstance(
                    KeyProperties.KEY_ALGORITHM_EC,
                    ANDROID_KEYSTORE,
                )
                generator.initialize(specBuilder.build())
                generator.generateKeyPair()
                SecurityLevel.STRONGBOX
            } catch (strongBoxUnavailable: Exception) {
                generateFallback(specBuilder)
            }
        } else {
            generateFallback(specBuilder)
        }

        val publicKey = keyStore.getCertificate(keyAlias).publicKey
        val encodedPublicKey = Base64.encodeToString(publicKey.encoded, Base64.NO_WRAP)
        return GeneratedKeyInfo(
            publicKeyBase64 = encodedPublicKey,
            algorithm = SIGNATURE_ALGORITHM,
            securityLevel = securityLevel,
        )
    }

    private fun generateFallback(specBuilder: KeyGenParameterSpec.Builder): SecurityLevel {
        specBuilder.setIsStrongBoxBacked(false)
        val generator = KeyPairGenerator.getInstance(
            KeyProperties.KEY_ALGORITHM_EC,
            ANDROID_KEYSTORE,
        )
        generator.initialize(specBuilder.build())
        generator.generateKeyPair()
        return resolveActualSecurityLevel()
    }

    private fun resolveActualSecurityLevel(): SecurityLevel {
        val privateKey = keyStore.getKey(keyAlias, null) as PrivateKey
        val factory = KeyFactory.getInstance(privateKey.algorithm, ANDROID_KEYSTORE)
        val keyInfo = factory.getKeySpec(privateKey, KeyInfo::class.java)
        return if (keyInfo.isInsideSecureHardware) SecurityLevel.TEE else SecurityLevel.SOFTWARE
    }

    /**
     * Zwraca [Signature] zainicjalizowany kluczem prywatnym telefonu, gotowy do
     * użycia z [android.hardware.biometrics.BiometricPrompt.CryptoObject]. Każde
     * użycie wymaga świeżego uwierzytelnienia biometrycznego/PIN.
     */
    override fun createSignatureForAuthentication(): Signature {
        val privateKey = keyStore.getKey(keyAlias, null) as PrivateKey
        return Signature.getInstance(SIGNATURE_ALGORITHM).apply { initSign(privateKey) }
    }

    /** Podpisuje kanoniczny payload już odblokowanym (przez BiometricPrompt) obiektem [Signature]. */
    override fun sign(signature: Signature, canonicalPayload: String): String {
        signature.update(canonicalPayload.toByteArray(Charsets.UTF_8))
        val raw = signature.sign()
        return Base64.encodeToString(raw, Base64.NO_WRAP)
    }

    /** Usuwa klucz — używane przy unieważnieniu lokalnym/re-rejestracji urządzenia. */
    override fun deleteKey() {
        if (keyStore.containsAlias(keyAlias)) {
            keyStore.deleteEntry(keyAlias)
        }
    }

    enum class SecurityLevel {
        STRONGBOX,
        TEE,
        SOFTWARE,
    }

    data class GeneratedKeyInfo(
        val publicKeyBase64: String,
        val algorithm: String,
        val securityLevel: SecurityLevel,
    )

    companion object {
        private const val ANDROID_KEYSTORE = "AndroidKeyStore"
        private const val KEY_ALIAS_PREFIX = "dors3_mobile_device_"
        private const val LEGACY_AUTH_WINDOW_SECONDS = 15
        const val SIGNATURE_ALGORITHM = "SHA256withECDSA"
    }
}
