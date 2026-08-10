package pl.zrodloslowa.mobile.network

import pl.zrodloslowa.mobile.model.ApprovalDecisionRequest
import pl.zrodloslowa.mobile.model.ApprovalDecisionResponse
import pl.zrodloslowa.mobile.model.ApprovalRequestDetails
import pl.zrodloslowa.mobile.model.EnrollmentCompleteRequest
import pl.zrodloslowa.mobile.model.EnrollmentCompleteResponse
import pl.zrodloslowa.mobile.model.EnrollmentConfirmRequest
import pl.zrodloslowa.mobile.model.DeviceHeartbeatRequest
import pl.zrodloslowa.mobile.model.DeviceStatusResponse
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path

/**
 * Klient API 3DORS zgodny z endpointami z pkt 9 dyspozycji. Backend PHP nie jest
 * modyfikowany przez tę aplikację — kontrakt poniżej odzwierciedla wyłącznie
 * ścieżki opisane w dokumencie i musi zostać potwierdzony/dostarczony po
 * stronie backendu (patrz raport końcowy).
 */
interface Dors3ApiService {

    @POST("api/3dors/mobile/enrollment/complete")
    suspend fun completeEnrollment(
        @Body request: EnrollmentCompleteRequest,
    ): Response<EnrollmentCompleteResponse>

    @POST("api/3dors/mobile/enrollment/confirm")
    suspend fun confirmEnrollment(
        @Header("Authorization") deviceAuthorization: String,
        @Body request: EnrollmentConfirmRequest,
    ): Response<Unit>

    /**
     * Nowy kontrakt wymagany przez ekran startowy (dyspozycja "prosta aplikacja
     * uwierzytelniająca"): automatyczne wykrycie aktywnego żądania z pobliskiego
     * urządzenia/przeglądarki bez konieczności skanowania QR. Backend PHP musi
     * dostarczyć ten endpoint — zwraca 200 z treścią, gdy istnieje aktywne
     * żądanie dla danego urządzenia, albo 204/404, gdy brak żądania.
     */
    @GET("api/3dors/mobile/devices/{device_public_id}/pending-request")
    suspend fun getPendingRequestForDevice(
        @Path("device_public_id") devicePublicId: String,
        @Header("Authorization") deviceAuthorization: String,
    ): Response<ApprovalRequestDetails>

    @GET("api/3dors/mobile/devices/{device_public_id}/status")
    suspend fun getDeviceStatus(
        @Path("device_public_id") devicePublicId: String,
        @Header("Authorization") deviceAuthorization: String,
    ): Response<DeviceStatusResponse>

    @POST("api/3dors/mobile/devices/{device_public_id}/heartbeat")
    suspend fun heartbeat(
        @Path("device_public_id") devicePublicId: String,
        @Header("Authorization") deviceAuthorization: String,
        @Body request: DeviceHeartbeatRequest,
    ): Response<Unit>

    @GET("api/3dors/mobile/requests/{public_id}")
    suspend fun getApprovalRequest(
        @Path("public_id") publicId: String,
        @Header("Authorization") deviceAuthorization: String,
    ): Response<ApprovalRequestDetails>

    @POST("api/3dors/mobile/requests/{public_id}/approve")
    suspend fun approveRequest(
        @Path("public_id") publicId: String,
        @Body request: ApprovalDecisionRequest,
    ): Response<ApprovalDecisionResponse>

    @POST("api/3dors/mobile/requests/{public_id}/reject")
    suspend fun rejectRequest(
        @Path("public_id") publicId: String,
        @Body request: ApprovalDecisionRequest,
    ): Response<ApprovalDecisionResponse>
}
