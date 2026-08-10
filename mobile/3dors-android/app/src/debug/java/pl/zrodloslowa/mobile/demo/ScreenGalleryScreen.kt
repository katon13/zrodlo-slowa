package pl.zrodloslowa.mobile.demo

import androidx.annotation.StringRes
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import pl.zrodloslowa.mobile.R

/**
 * Galeria ekranów dostępna WYŁĄCZNIE w wariancie debug (Effective Issue:
 * "Screen Gallery tylko debug"). Pozwala szybko przejrzeć wszystkie stany
 * ekranu operacji bez konieczności odtwarzania pełnego przepływu QR/biometrii.
 * Nigdy nie jest kompilowana ani osiągalna w wariancie release.
 */
@Composable
fun ScreenGalleryScreen(
    scenarios: List<DemoScenario>,
    onScenarioClick: (DemoScenario) -> Unit,
) {
    Column(modifier = Modifier.fillMaxSize().padding(24.dp)) {
        Text(stringResource(R.string.demo_gallery_title), style = MaterialTheme.typography.headlineSmall)
        Text(
            stringResource(R.string.demo_gallery_description),
            style = MaterialTheme.typography.bodyMedium,
            modifier = Modifier.padding(bottom = 16.dp, top = 4.dp),
        )
        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            items(scenarios) { scenario ->
                Button(onClick = { onScenarioClick(scenario) }, modifier = Modifier.fillMaxSize()) {
                    Text(stringResource(scenario.titleRes))
                }
            }
        }
    }
}

/** Scenariusze demonstracyjne (Effective Issue: "tryb demo wyłącznie debug"). */
enum class DemoScenario(@StringRes val titleRes: Int) {
    LOGIN(R.string.demo_scenario_login),
    WITHDRAWAL(R.string.demo_scenario_withdrawal),
    PUBLICATION(R.string.demo_scenario_publication),
    CONFIDENTIAL(R.string.demo_scenario_confidential),
    REJECTED(R.string.demo_scenario_rejected),
    EXPIRED(R.string.demo_scenario_expired),
}
