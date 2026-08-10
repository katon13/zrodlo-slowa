package pl.zrodloslowa.mobile.data

/**
 * Abstrakcja trwałych metadanych urządzenia (bez klucza prywatnego — ten
 * fizycznie istnieje wyłącznie w Android Keystore).
 *
 * Wydzielona jako interfejs (implementowany produkcyjnie przez
 * [DeviceCredentialStore], oparty o [androidx.security.crypto.EncryptedSharedPreferences]
 * wymagający realnego Android Context), aby [EnrollmentRepository] i
 * [ApprovalRepository] dało się przetestować jednostkowo (JVM) przy użyciu
 * prostego fejka w pamięci.
 */
interface Dors3CredentialStore {

    var devicePublicId: String?

    /** Identyfikator konkretnego klucza/credentialu telefonu — odrębny od [devicePublicId]. */
    var credentialPublicId: String?

    /** Jednorazowo wydany, losowy token API związany po stronie serwera z konkretnym credentialem. */
    var apiToken: String?

    var apiTokenExpiresAt: Long?

    /** Jeden, trwały alias klucza w Android Keystore — patrz [pl.zrodloslowa.mobile.crypto.Dors3KeystoreManager]. */
    var keyAlias: String?

    var deviceStatus: String?

    var apiBaseUrlOverride: String?

    fun isRegistered(): Boolean

    fun isRequestConsumedLocally(requestId: String, nowEpochSeconds: Long): Boolean

    fun markRequestConsumedLocally(requestId: String, expiresAtEpochSeconds: Long, nowEpochSeconds: Long)

    fun clear()
}
