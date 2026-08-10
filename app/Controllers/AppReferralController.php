<?php
declare(strict_types=1);

namespace App\Controllers;

final class AppReferralController extends BaseController
{
    public function overview(): never
    {
        $userId = $this->app->session->userId();
        if ($userId === null) {
            $this->jsonError('Zaloguj się, aby korzystać z zaproszeń.', 401);
        }
        try {
            $this->json(['ok' => true] + $this->appReferralService()->userOverview($userId));
        } catch (\Throwable $error) {
            $this->jsonError($this->safeError($error, 'Nie udało się pobrać promocji Talent.', 'referral_overview'), 503);
        }
    }

    public function create(): never
    {
        $userId = $this->app->session->userId();
        if ($userId === null) {
            $this->jsonError('Zaloguj się, aby wysłać zaproszenie.', 401);
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
                    'message' => 'Jeżeli adres kwalifikuje się do promocji, zaproszenie zostało przyjęte.',
                ]);
            }
            $this->jsonError($error->getMessage(), 409);
        } catch (\Throwable $error) {
            $this->jsonError($this->safeError($error, 'Nie udało się wysłać zaproszenia.', 'referral_create'), 500);
        }
    }

    public function landing(): string
    {
        $token = (string)($_GET['token'] ?? '');
        try {
            $invitation = $this->appReferralService()->openInvitation($token);
            $referrer = rawurlencode(http_build_query(['referral_token' => $token], '', '&', PHP_QUERY_RFC3986));
            return $this->view('referral/landing', [
                'title' => 'Zaproszenie do aplikacji ŹRÓDŁO SŁOWA',
                'invitation' => $invitation,
                'app_link' => 'zrodloslowa://referral/' . rawurlencode($token),
                'play_store_url' => 'https://play.google.com/store/apps/details?id=pl.zrodloslowa.app&referrer=' . $referrer,
            ]);
        } catch (\Throwable $error) {
            http_response_code(410);
            return $this->view('layouts/error', [
                'title' => 'Zaproszenie nieaktywne',
                'message' => $this->safeError($error, 'To zaproszenie jest nieprawidłowe albo nie jest już aktywne.', 'referral_landing'),
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
            $this->jsonError($this->safeError($error, 'Nie udało się potwierdzić instalacji.', 'referral_install'), 500);
        }
    }

    public function mobileFirstSession(): never
    {
        $this->requireMobileClient();
        $userId = $this->app->session->userId();
        if ($userId === null) {
            $this->jsonError('Pierwsza sesja nie jest uwierzytelniona.', 401);
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
            $this->jsonError($this->safeError($error, 'Nie udało się zakończyć polecenia.', 'referral_complete'), 500);
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
            $this->jsonError($this->safeError($error, 'Nie udało się przygotować rejestracji z aplikacji.', 'referral_registration_nonce'), 500);
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
            $this->jsonError('Żądanie nie pochodzi z obsługiwanej aplikacji.', 403);
        }
    }

    private function jsonError(string $message, int $status): never
    {
        http_response_code($status);
        $this->json(['ok' => false, 'error' => $message]);
    }
}
