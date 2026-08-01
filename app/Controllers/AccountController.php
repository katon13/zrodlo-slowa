<?php
namespace App\Controllers;

use App\Services\UserService;
use App\Services\UploadService;

final class AccountController extends BaseController
{
    public function showSettings(): string
    {
        $userId = $this->requireAuth();
        $userService = new UserService($this->app->db);
        $settings = $userService->operationalPermissions($userId);
        $currentLanguage = public_language();

        $userRow = $this->app->db->one('SELECT display_name, avatar_path, avatar_updated_at FROM users WHERE id = :id', ['id' => $userId]);

        return $this->view('account/settings', [
            'title' => t('account.settings.title', $currentLanguage),
            'current_language' => $currentLanguage,
            'settings' => $settings,
            'user_display_name' => $userRow['display_name'] ?? 'A',
            'current_user_avatar' => $userRow['avatar_path'] ?? null,
            'current_user_avatar_updated_at' => $userRow['avatar_updated_at'] ?? null,
        ]);
    }

    public function updateSettings(): never
    {
        $userId = $this->requireAuth();
        $userService = new UserService($this->app->db);

        // _lang oznacza język strony, z której wysłano formularz.
        // interface_language oznacza NOWĄ preferencję użytkownika.
        // To są dwie różne rzeczy i nie wolno ich mieszać.
        $requestLang = public_language((string)($_POST['_lang'] ?? ''));
        $newLang = $this->normalizePublicLanguage((string)($_POST['interface_language'] ?? $requestLang), $requestLang);

        try {
            $userService->updateSettings($userId, [
                'display_currency' => $_POST['display_currency'] ?? 'AUTO',
                'interface_language' => $newLang,
            ]);

            $_SESSION['interface_language'] = $newLang;
            $_SESSION['display_currency'] = $_POST['display_currency'] ?? 'AUTO';
            $redirectUrl = public_language_url($newLang, '/account/settings');

            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'success' => true,
                    'message' => t('account.settings.saved', $newLang),
                    'lang' => $newLang,
                    'redirect' => $redirectUrl,
                ]);
                exit;
            }

            $this->app->session->flash('success', t('account.settings.saved', $newLang));
            redirect($redirectUrl);
        } catch (\Throwable $e) {
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => t('account.settings.save_error', $requestLang),
                ]);
                exit;
            }
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się zapisać ustawień konta.', 'account_settings'));
            redirect(public_language_url($requestLang, '/account/settings'));
        }
    }

    private function normalizePublicLanguage(string $language, string $fallback): string
    {
        $language = strtolower(trim($language));
        $fallback = strtolower(trim($fallback));
        $enabled = $this->app->config['languages']['public_enabled'] ?? ['pl'];
        if (!is_array($enabled)) {
            $enabled = ['pl'];
        }
        $enabled = array_map(static fn($value): string => strtolower(trim((string)$value)), $enabled);

        if (in_array($language, $enabled, true)) {
            return $language;
        }
        if (in_array($fallback, $enabled, true)) {
            return $fallback;
        }
        return 'pl';
    }

    public function updateAvatar(): void
    {
        $userId = $this->requireAuth();
        
        if (!$this->isAjax()) {
            http_response_code(400);
            exit;
        }

        header('Content-Type: application/json; charset=UTF-8');
        $lang = public_language();

        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Nieprawidłowy format danych avatara.');
            }
            $lang = public_language((string)($data['_lang'] ?? $data['language'] ?? $data['lang'] ?? ''));
            $imageData = $data['image'] ?? null;

            if (!$imageData || !str_starts_with($imageData, 'data:image/')) {
                echo json_encode(['ok' => false, 'message' => t('profile.avatar.invalid_type', $lang)]);
                exit;
            }

            // Dekodowanie base64
            $parts = explode(',', $imageData);
            if (count($parts) < 2) {
                echo json_encode(['ok' => false, 'message' => t('profile.avatar.invalid_type', $lang)]);
                exit;
            }

            $metadata = $parts[0];
            $base64 = $parts[1];

            // Sprawdzanie MIME
            $mime = '';
            if (preg_match('/^data:(image\/[a-z]+);base64/', $metadata, $matches)) {
                $mime = $matches[1];
            }

            $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
            if (!in_array($mime, $allowedMime)) {
                echo json_encode(['ok' => false, 'message' => t('profile.avatar.invalid_type', $lang)]);
                exit;
            }

            $binary = base64_decode($base64);
            if (!$binary) {
                echo json_encode(['ok' => false, 'message' => t('profile.avatar.error', $lang)]);
                exit;
            }

            // Rozmiar (5 MB)
            if (strlen($binary) > 5 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'message' => t('profile.avatar.file_too_large', $lang)]);
                exit;
            }

            $oldReferenceValue = $this->app->db->cell(
                'SELECT avatar_path FROM users WHERE id=:id',
                ['id' => $userId]
            );
            $oldReference = is_string($oldReferenceValue) ? $oldReferenceValue : '';
            $uploadService = new UploadService($this->app->db, $this->app->objectStorage);
            $relativeUrl = $uploadService->uploadAvatarDataUrl($imageData, $userId);
            $userService = new UserService($this->app->db);
            try {
                if (!$userService->updateAvatarIfCurrent(
                    $userId,
                    $relativeUrl,
                    is_string($oldReferenceValue) ? $oldReferenceValue : null
                )) {
                    throw new \RuntimeException('Avatar został równolegle zmieniony; ponów operację.');
                }
            } catch (\Throwable $error) {
                try {
                    $uploadService->deleteReference($relativeUrl);
                } catch (\Throwable) {
                    // Sprzątanie nowego obiektu nie może ukryć błędu zapisu bazy.
                }
                throw $error;
            }
            if ($oldReference !== '' && $oldReference !== $relativeUrl) {
                try {
                    $uploadService->deleteReference($oldReference);
                } catch (\Throwable $error) {
                    error_log('Previous avatar cleanup failed: ' . $error->getMessage());
                }
            }

            echo json_encode([
                'ok' => true,
                'avatar_url' => $relativeUrl
            ]);
            exit;

        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'message' => t('profile.avatar.error', $lang)]);
            exit;
        }
    }
}
