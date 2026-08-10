package pl.zrodloslowa.mobile.ui.home

import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.cancel
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Before
import org.junit.Test
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import pl.zrodloslowa.mobile.model.ApprovalRequestDetails

/**
 * `startWatching()` uruchamia nieskończoną pętlę odpytywania — w testach z
 * [runTest] MUSIMY ją jawnie anulować po asercjach, inaczej wirtualny zegar
 * `runTest` nigdy nie osiągnie stanu "idle" (nieskończone `delay`).
 */
private fun HomeViewModel.cancelWatching() {
    viewModelScope.cancel()
}

/**
 * Testy typowanych stanów ekranu startowego (Effective Issue: "typowane
 * stany" + "automatycznie szukać aktywnego żądania"). Zależności [HomeViewModel]
 * są funkcyjne, więc nie wymagają realnego kontekstu Androida ani sieci.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class HomeViewModelTest {

    private val dispatcher = StandardTestDispatcher()

    @Before
    fun setUp() {
        Dispatchers.setMain(dispatcher)
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun sampleDetails() = ApprovalRequestDetails(
        requestId = "req-1",
        publicId = "public-1",
        purpose = "login",
        service = "Źródło Słowa",
        environment = "LOKALNE",
        account = "jan.kowalski",
        person = "Jan Kowalski",
        role = "Administrator",
        organization = "Źródło Słowa",
        initiatingDevice = null,
        actionType = null,
        displayFields = emptyMap(),
        challenge = "challenge",
        actionFingerprint = "fingerprint",
        browserSessionHash = "session",
        issuedAt = 1000L,
        expiresAt = 1060L,
        nonce = "nonce",
        serverOrigin = "https://zrodlo-slowa.test",
    )

    @Test
    fun `brak zarejestrowanego urzadzenia daje stan NotRegistered`() = runTest(dispatcher) {
        val viewModel = HomeViewModel(
            getDevicePublicId = { null },
            findPendingRequest = { Result.success(null) },
        )

        viewModel.startWatching()

        assertEquals(HomeUiState.NotRegistered, viewModel.state.value)
    }

    @Test
    fun `brak oczekujacego zadania utrzymuje stan Searching`() = runTest(dispatcher) {
        val viewModel = HomeViewModel(
            getDevicePublicId = { "device-1" },
            findPendingRequest = { Result.success(null) },
        )

        viewModel.startWatching()
        dispatcher.scheduler.runCurrent()

        assertEquals(HomeUiState.Searching, viewModel.state.value)
        viewModel.cancelWatching()
    }

    @Test
    fun `wykrycie zadania zmienia stan na RequestFound`() = runTest(dispatcher) {
        val details = sampleDetails()
        val viewModel = HomeViewModel(
            getDevicePublicId = { "device-1" },
            findPendingRequest = { Result.success(details) },
        )

        viewModel.startWatching()
        dispatcher.scheduler.runCurrent()

        val state = viewModel.state.value
        assertTrue(state is HomeUiState.RequestFound)
        assertEquals(details, (state as HomeUiState.RequestFound).details)
        viewModel.cancelWatching()
    }

    @Test
    fun `blad sieci zmienia stan na Error`() = runTest(dispatcher) {
        val viewModel = HomeViewModel(
            getDevicePublicId = { "device-1" },
            findPendingRequest = { Result.failure(IllegalStateException("Brak połączenia")) },
        )

        viewModel.startWatching()
        dispatcher.scheduler.runCurrent()

        val state = viewModel.state.value
        assertTrue(state is HomeUiState.Error)
        viewModel.cancelWatching()
    }

    /**
     * Naprawa błędu z dyspozycji "Polling": pętla NIE MOŻE odpytywać serwera
     * bez końca ze stałym interwałem — musi zakończyć się natychmiast po
     * znalezieniu wyniku, bez kolejnych zapytań.
     */
    @Test
    fun `polling konczy sie natychmiast po znalezieniu zadania, bez kolejnych zapytan`() = runTest(dispatcher) {
        var callCount = 0
        val details = sampleDetails()
        val viewModel = HomeViewModel(
            getDevicePublicId = { "device-1" },
            findPendingRequest = {
                callCount++
                Result.success(details)
            },
        )

        viewModel.startWatching()
        dispatcher.scheduler.runCurrent()
        // Nawet po odczekaniu długiego czasu (znacznie dłużej niż jakikolwiek
        // krok backoffu) pętla NIE MOŻE wykonać kolejnego zapytania.
        dispatcher.scheduler.advanceTimeBy(60_000L)
        dispatcher.scheduler.runCurrent()

        assertEquals(1, callCount)
        assertTrue(viewModel.state.value is HomeUiState.RequestFound)
    }

    /** Backoff musi narastać (0,5 / 1 / 2 / 4 s), a nie odpytywać ze stałym interwałem. */
    @Test
    fun `polling uzywa narastajacego backoffu zamiast stalego interwalu`() = runTest(dispatcher) {
        var callCount = 0
        val viewModel = HomeViewModel(
            getDevicePublicId = { "device-1" },
            findPendingRequest = {
                callCount++
                Result.success(null)
            },
        )

        viewModel.startWatching()
        dispatcher.scheduler.runCurrent()
        assertEquals(1, callCount)

        dispatcher.scheduler.advanceTimeBy(500L)
        dispatcher.scheduler.runCurrent()
        assertEquals(2, callCount)

        dispatcher.scheduler.advanceTimeBy(1000L)
        dispatcher.scheduler.runCurrent()
        assertEquals(3, callCount)

        viewModel.cancelWatching()
    }

    @Test
    fun `polling pozostaje aktywny po uplywie minuty gdy ekran jest na pierwszym planie`() = runTest(dispatcher) {
        var callCount = 0
        val viewModel = HomeViewModel(
            getDevicePublicId = { "device-1" },
            findPendingRequest = {
                callCount++
                Result.success(null)
            },
        )

        viewModel.startWatching()
        dispatcher.scheduler.runCurrent()
        dispatcher.scheduler.advanceTimeBy(65_000L)
        dispatcher.scheduler.runCurrent()

        assertTrue(callCount > 15)
        assertEquals(HomeUiState.Searching, viewModel.state.value)
        viewModel.cancelWatching()
    }
}
