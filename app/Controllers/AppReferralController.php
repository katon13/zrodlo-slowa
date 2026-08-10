<?php
declare(strict_types=1);

namespace App\Controllers;

final class AppReferralController extends BaseController
{
    public function overview(): never
    {
        $userId = $this->app->session->userId();
        if ($userId === null) {
            $this->jsonError(t('controller.appreferral.zaloguj_sie_aby_korzystac_z_zaproszen'), 401);
        }
        try {
            $this->json(['ok' => true] + $this->appReferralService()->userOverview($userId));
        } catch (\Throwable $error) {
            $this->jsonError($this->safeError($error, t('controller.appreferral.nie_udao_sie_pobrac_promocji_talent'), 'referral_overview'), 503);
        }
    }

    public function create(): never
    {
        $userId = $this->app->session->userId();
        if ($userId === null) {
            $this->jsonError(t('controller.appreferral.zaloguj_sie_aby_wysac_zaproszenie'), 401);
        }
        try {
            $invitation = $this->appReferralService()->createInvitation(
                $userId,
                (string)($_POST['email'] ?? '')
            );
            $this->json(['ok' => true, 'invitation' => $invitation]);
        } catch (\InvalidArgumentException $error) {
            $this->jsonError($error->getMessage(), 422);
        } catch (\RuntimeException $error) {
            if ($error->getCode() === \App\Services\AppReferralService::PRIVATE_ELIGIBILITY_REJECTION) {
                http_response_code(202);
                $this->json([
                    'ok' => true,
                    'accepted' => true,
                    'message' => t('controller.appreferral.jezeli_adres_kwalifikuje_sie_do_promocji_zaproszenie_zo_8e26b5ed'),
                ]);
            }
            $this->jsonError($error->getMessage(), 409);
        } catch (\Throwable $error) {
            $this->jsonError($this->safeError($error, t('controller.appreferral.nie_udao_sie_wysac_zaproszenia'), 'referral_create'), 500);
        }
    }

    public function landing(): string
    {
        $token = (string)($_GET['token'] ?? '');
        try {
            $invitation = $this->appReferralService()->openInvitation($token);
            $referrer = rawurlencode(http_build_query(['referral_token' => $token], '', '&', PHP_QUERY_RFC3986));
            return $this->view('referral/landing', [
                'title' => t('controller.appreferral.zaproszenie_do_aplikacji_zrodo_sowa'),
                'invitation' => $invitation,
                'app_link' => 'zrodloslowa://referral/' . rawurlencode($token),
                'play_store_url' => 'https://play.google.com/store/apps/details?id=pl.zrodloslowa.app&referrer=' . $referrer,
            ]);
        } catch (\Throwable $error) {
            http_response_code(410);
            return $this->view('layouts/error', [
                'title' => t('controller.appreferral.zaproszenie_nieaktywne'),
                'message' => $this->safeError($error, t('controller.appreferral.to_zaproszenie_jest_nieprawidowe_albo_nie_jest_juz_aktywne'), 'referral_landing'),
            ]);
        }
    }

    public function mobileInstall(): never
    {
        $this->requireMobileClient();
        $input = $this->jsonInput();
        try {
            $this->json($this->appReferralService()->recordInstallation(
                (string)($input['token'] ?? ''),
                (string)($input['device_id'] ?? '')
            ));
        } catch (\InvalidArgumentException $error) {
            $this->jsonError($error->getMessage(), 422);
        } catch (\RuntimeException $error) {
            $this->jsonError($error->getMessage(), 409);
        } catch (\Throwable $error) {
            $this->jsonError($this->safeError($error, t('controller.appreferral.nie_udao_sie_potwierdzic_instalacji'), 'referral_install'), 500);
        }
    }

    public function mobileFirstSession(): never
    {
        $this->requireMobileClient();
        $userId = $this->app->session->userId();
        if ($userId === null) {
            $this->jsonError(t('controller.appreferral.pierwsza_sesja_nie_jest_uwierzytelniona'), 401);
        }
        $input = $this->jsonInput();
        try {
            $this->json($this->appReferralService()->completeFirstSession(
                (string)($input['token'] ?? ''),
                (string)($input['device_id'] ?? ''),
                $userId,
            ));
        } catch (\InvalidArgumentException $error) {
            $this->jsonError($error->getMessage(), 422);
        } catch (\RuntimeException $error) {
            $this->jsonError($error->getMessage(), 409);
        } catch (\Throwable $error) {
            $this->jsonError($this->safeError($error, t('controller.appreferral.nie_udao_sie_zakonczyc_polecenia'), 'referral_complete'), 500);
        }
    }

    public function mobileRegistrationNonce(): never
    {
        $this->requireMobileClient();
        $input = $this->jsonInput();
        try {
            $this->json($this->appReferralService()->createRegistrationNonce(
                (string)($input['token'] ?? ''),
                (string)($input['device_id'] ?? '')
            ));
        } catch (\InvalidArgumentException $error) {
            $this->jsonError($error->getMessage(), 422);
        } catch (\RuntimeException $error) {
            $this->jsonError($error->getMessage(), 409);
        } catch (\Throwable $error) {
            $this->jsonError($this->safeError($error, t('controller.appreferral.nie_udao_sie_przygotowac_rejestracji_z_aplikacji'), 'referral_registration_nonce'), 500);
        }
    }

    /** @return array<string,mixed> */
    private function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function requireMobileClient(): void
    {
        $requestedWith = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!hash_equals('ZrodloSlowaMobile', $requestedWith) || !str_starts_with($contentType, 'application/json')) {
            $this->jsonError(t('controller.appreferral.zadanie_nie_pochodzi_z_obsugiwanej_aplikacji'), 403);
        }
    }

    private function jsonError(string $message, int $status): never
    {
        http_response_code($status);
        $this->json(['ok' => false, 'error' => $message]);
    }
}
