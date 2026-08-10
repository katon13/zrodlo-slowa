package pl.zrodloslowa.app.config

import android.content.Context

/**
 * ETAP 1, pkt 6 dyspozycji: trwały zapis informacji, że użytkownik przeszedł
 * już przez pierwszy ekran wyboru ("Przeglądaj bez konta" / "Mam konto" /
 * "Dołącz jako autor"), aby nie pokazywać go ponownie przy kolejnym
 * uruchomieniu aplikacji.
 */
object OnboardingPreferenceStore {

    private const val PREFS_NAME = "zrodlo_slowa_onboarding_prefs"
    private const val KEY_ONBOARDING_DONE = "onboarding_done"

    fun isOnboardingDone(context: Context): Boolean {
        val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        return prefs.getBoolean(KEY_ONBOARDING_DONE, false)
    }

    fun markOnboardingDone(context: Context) {
        val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        prefs.edit().putBoolean(KEY_ONBOARDING_DONE, true).apply()
    }
}
