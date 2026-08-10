package pl.zrodloslowa.app.ui.onboarding

import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import pl.zrodloslowa.app.R

/**
 * Trzy proste możliwości pierwszego uruchomienia (ETAP 1, pkt 3.2/6
 * dyspozycji). Same dane (imię, telefon, e-mail) nie są jeszcze logowaniem
 * ani autoryzacją — istniejąca trasa `/register` zakłada hasło i tworzy
 * konto autora oczekujące na akceptację, a nie zwykłe konto czytelnika,
 * dlatego aplikacja nie tworzy żadnej nowej rejestracji czytelnika.
 */
enum class OnboardingChoice {
    BROWSE_AS_GUEST,
    HAVE_ACCOUNT,
    JOIN_AS_AUTHOR,
}

@Composable
fun OnboardingScreen(
    onChoiceSelected: (OnboardingChoice) -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .safeDrawingPadding()
            .verticalScroll(rememberScrollState())
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            text = stringResource(R.string.onboarding_title),
            style = MaterialTheme.typography.headlineSmall,
        )
        androidx.compose.foundation.layout.Spacer(modifier = Modifier.padding(top = 32.dp))

        Button(
            onClick = { onChoiceSelected(OnboardingChoice.BROWSE_AS_GUEST) },
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(text = stringResource(R.string.onboarding_browse_guest))
        }
        androidx.compose.foundation.layout.Spacer(modifier = Modifier.padding(top = 12.dp))

        OutlinedButton(
            onClick = { onChoiceSelected(OnboardingChoice.HAVE_ACCOUNT) },
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(text = stringResource(R.string.onboarding_have_account))
        }
        androidx.compose.foundation.layout.Spacer(modifier = Modifier.padding(top = 12.dp))

        TextButton(
            onClick = { onChoiceSelected(OnboardingChoice.JOIN_AS_AUTHOR) },
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(text = stringResource(R.string.onboarding_join_author))
        }
    }
}
