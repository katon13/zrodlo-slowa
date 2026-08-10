package pl.zrodloslowa.app

import android.app.Application
import pl.zrodloslowa.app.session.WebSessionManager
import pl.zrodloslowa.app.referral.ReferralInstallManager

/**
 * Klasa aplikacji "Źródło Słowa Mobile". Od ETAPU 2 inicjalizuje globalny
 * [WebSessionManager] (cookies WebView) raz, przy starcie procesu.
 */
class ZrodloSlowaApp : Application() {

    override fun onCreate() {
        super.onCreate()
        WebSessionManager.init()
        ReferralInstallManager.init(this)
    }
}
