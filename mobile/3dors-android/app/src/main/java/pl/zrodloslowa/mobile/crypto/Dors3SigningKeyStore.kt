package pl.zrodloslowa.mobile.crypto

import java.security.Signature

/**
 * Abstrakcja operacji kryptograficznych wykonywanych na kluczu telefonu.
 *
 * Wydzielona jako interfejs (implementowany produkcyjnie przez
 * [Dors3KeystoreManager], oparty o realny Android Keystore), aby
 * [pl.zrodloslowa.mobile.data.EnrollmentRepository] i
 * [pl.zrodloslowa.mobile.data.ApprovalRepository] dało się przetestować
 * jednostkowo (JVM, bez realnego Android Keystore) przy użyciu prostego fejka
 * — patrz testy: rejestracja → późniejszy podpis tym samym aliasem.
 */
interface Dors3SigningKeyStore {

    fun hasKey(): Boolean

    fun generateKeyPair(requireStrongBox: Boolean = true): Dors3KeystoreManager.GeneratedKeyInfo

    fun createSignatureForAuthentication(): Signature

    fun sign(signature: Signature, canonicalPayload: String): String

    fun deleteKey()
}
