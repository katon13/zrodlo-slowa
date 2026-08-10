<?php
namespace App\Services;

final class EnvironmentValidator
{
    private const REQUIRED_EXTENSIONS = [
        'curl',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'sodium',
    ];

    public function validate(bool $forInstall = false): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!extension_loaded($extension)) {
                $errors[] = "Brak wymaganego rozszerzenia PHP: {$extension}.";
            }
        }

        $databaseDriver = strtolower(trim((string)env('DB_DRIVER', 'mysql')));
        if (!in_array($databaseDriver, ['mysql', 'pgsql'], true)) {
            $errors[] = 'DB_DRIVER musi mieć wartość mysql albo pgsql.';
        } else {
            $driverExtension = $databaseDriver === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql';
            if (!extension_loaded($driverExtension)) {
                $errors[] = "Brak wymaganego rozszerzenia PHP: {$driverExtension}.";
            }
        }

        foreach (['APP_KEY', 'PASSWORD_PEPPER', 'FINANCE_HMAC_KEY'] as $key) {
            $value = trim((string)env($key, ''));
            if (!$this->isStrongSecret($value)) {
                $errors[] = "{$key} musi być losowym sekretem o długości co najmniej 32 znaków.";
            }
        }

        $environment = strtolower(trim((string)env('APP_ENV', 'production')));
        $debug = env_bool('APP_DEBUG', false);
        $appUrl = trim((string)env('APP_URL', ''));
        if (!filter_var($appUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'APP_URL musi być pełnym, poprawnym adresem URL.';
        } elseif ($environment === 'production' && strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)) !== 'https') {
            $errors[] = 'APP_URL na produkcji musi używać HTTPS.';
        }
        if ($environment === 'production' && $debug) {
            $errors[] = 'APP_DEBUG musi być wyłączone na produkcji.';
        }
        if ($environment === 'production' && !env_bool('SESSION_SECURE', true)) {
            $errors[] = 'SESSION_SECURE musi być włączone na produkcji.';
        }
        if (
            $environment === 'production'
            && (env_bool('DB_ALLOW_CREATE_DATABASE', false) || env_bool('DB_ALLOW_CREATE_SCHEMA', false))
        ) {
            $errors[] = 'Proces aplikacji nie może tworzyć bazy ani schematu na produkcji.';
        }

        $this->validateDors3($environment, $errors);

        $valkeyDrivers = [
            'SESSION_DRIVER' => ['file', 'valkey'],
            'CACHE_DRIVER' => ['file', 'valkey', 'none'],
            'RATE_LIMIT_DRIVER' => ['database', 'valkey'],
            'LOCK_DRIVER' => ['none', 'valkey'],
            'QUEUE_SIGNAL_DRIVER' => ['none', 'valkey'],
        ];
        $usesValkey = false;
        foreach ($valkeyDrivers as $key => $allowed) {
            $value = strtolower(trim((string)env($key, $allowed[0])));
            if (!in_array($value, $allowed, true)) {
                $errors[] = $key . ' ma nieobsługiwaną wartość.';
            }
            $usesValkey = $usesValkey || $value === 'valkey';
        }
        if ($usesValkey) {
            if (!extension_loaded('redis')) {
                $errors[] = 'Sterowniki Valkey wymagają rozszerzenia PHP redis.';
            }
            if (trim((string)env('VALKEY_HOST', '')) === '') {
                $errors[] = 'VALKEY_HOST jest wymagany dla sterowników Valkey.';
            }
            if ($environment === 'production' && $this->missingConfigurationValue('VALKEY_PASSWORD')) {
                $errors[] = 'VALKEY_PASSWORD jest wymagane na produkcji.';
            }
        }

        $storageDriver = strtolower(trim((string)env('OBJECT_STORAGE_DRIVER', 'local')));
        if (!in_array($storageDriver, ['local', 's3'], true)) {
            $errors[] = 'OBJECT_STORAGE_DRIVER musi mieć wartość local albo s3.';
        } elseif ($storageDriver === 's3') {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $errors[] = 'Sterownik S3 wymaga pakietu aws/aws-sdk-php.';
            }
            foreach (['S3_REGION', 'S3_BUCKET', 'S3_ACCESS_KEY', 'S3_SECRET_KEY'] as $key) {
                if ($this->missingConfigurationValue($key)) {
                    $errors[] = "{$key} jest wymagane dla sterownika S3.";
                }
            }
            $bucket = trim((string)env('S3_BUCKET', ''));
            if (
                $bucket !== ''
                && preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) !== 1
            ) {
                $errors[] = 'S3_BUCKET ma nieprawidłową nazwę.';
            }
            $endpoint = trim((string)env('S3_ENDPOINT', ''));
            if ($endpoint !== '') {
                if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
                    $errors[] = 'S3_ENDPOINT musi być pełnym, poprawnym adresem URL.';
                } elseif (
                    $environment === 'production'
                    && strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) !== 'https'
                ) {
                    $errors[] = 'S3_ENDPOINT na produkcji musi używać HTTPS.';
                }
            }
        } elseif ($environment === 'production') {
            $errors[] = 'OBJECT_STORAGE_DRIVER na produkcji musi używać S3.';
        }

        if (env_bool('GOOGLE_LOGIN_ENABLED', false)) {
            foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI'] as $key) {
                if ($this->missingConfigurationValue($key)) {
                    $errors[] = "{$key} jest wymagane, gdy logowanie Google jest włączone.";
                }
            }
            $this->validateExternalUrl('GOOGLE_REDIRECT_URI', $environment, $errors);
        }

        if (env_bool('APPLE_LOGIN_ENABLED', false)) {
            foreach (['APPLE_CLIENT_ID', 'APPLE_TEAM_ID', 'APPLE_KEY_ID', 'APPLE_PRIVATE_KEY_PATH', 'APPLE_REDIRECT_URI'] as $key) {
                if ($this->missingConfigurationValue($key)) {
                    $errors[] = "{$key} jest wymagane, gdy logowanie Apple jest włączone.";
                }
            }
            $this->validateExternalUrl('APPLE_REDIRECT_URI', $environment, $errors);
            $privateKeyPath = trim((string)env('APPLE_PRIVATE_KEY_PATH', ''));
            if ($privateKeyPath !== '') {
                $resolved = preg_match('/^[A-Za-z]:[\\\\\/]|^\//', $privateKeyPath) === 1
                    ? $privateKeyPath
                    : dirname(__DIR__, 2) . '/' . ltrim($privateKeyPath, '/\\');
                if (!is_file($resolved) || !is_readable($resolved)) {
                    $errors[] = 'APPLE_PRIVATE_KEY_PATH nie wskazuje czytelnego pliku klucza.';
                } else {
                    $realPath = realpath($resolved);
                    $publicPath = realpath(dirname(__DIR__, 2) . '/public');
                    if (
                        $realPath !== false
                        && $publicPath !== false
                        && str_starts_with(
                            strtolower(str_replace('\\', '/', $realPath)),
                            rtrim(strtolower(str_replace('\\', '/', $publicPath)), '/') . '/'
                        )
                    ) {
                        $errors[] = 'Klucz prywatny Apple nie może znajdować się w katalogu public.';
                    }
                    $keyContents = file_get_contents($resolved);
                    if (!is_string($keyContents) || openssl_pkey_get_private($keyContents) === false) {
                        $errors[] = 'APPLE_PRIVATE_KEY_PATH nie zawiera poprawnego klucza prywatnego.';
                    }
                }
            }
        }

        if (env_bool('STRIPE_ENABLED', false)) {
            foreach (['STRIPE_SECRET_KEY', 'STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET'] as $key) {
                if ($this->missingConfigurationValue($key)) {
                    $errors[] = "{$key} jest wymagane, gdy Stripe jest włączony.";
                }
            }
            foreach (['STRIPE_CHECKOUT_SUCCESS_URL', 'STRIPE_CHECKOUT_CANCEL_URL', 'STRIPE_WEBHOOK_URL'] as $key) {
                $this->validateExternalUrl($key, $environment, $errors);
            }
        }

        if (
            env_bool('AI_ENABLED', false)
            || env_bool('OPENAI_TRANSLATION_ENABLED', false)
            || env_bool('OPENAI_ENABLED', false)
        ) {
            if ($this->missingConfigurationValue('OPENAI_API_KEY')) {
                $errors[] = 'OPENAI_API_KEY jest wymagane, gdy funkcje OpenAI są włączone.';
            }
        }

        $databaseName = trim((string)env('DB_NAME', ''));
        if ($databaseName === '' || preg_match('/^[A-Za-z0-9_$-]+$/D', $databaseName) !== 1) {
            $errors[] = 'DB_NAME zawiera niedozwolone znaki.';
        }

        if ($forInstall) {
            $adminEmail = strtolower(trim((string)env('ADMIN_EMAIL', '')));
            if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'ADMIN_EMAIL nie jest poprawnym adresem e-mail.';
            }
        }

        if ($environment !== 'production' && env_bool('SESSION_SECURE', false) && str_starts_with(strtolower($appUrl), 'http://')) {
            $warnings[] = 'Bezpieczne ciasteczko sesji nie będzie wysyłane po lokalnym HTTP.';
        }
        $mailConfigured = trim((string)env('MAILER_DSN', '')) !== ''
            || trim((string)env('MAIL_SMTP_HOST', '')) !== ''
            || (
                strtolower(trim((string)env('MAIL_TRANSPORT', ''))) === 'null'
                && $environment !== 'production'
            );
        if (!$mailConfigured) {
            $message = 'Transport poczty nie jest ustawiony; wiadomości pozostaną w kolejce do czasu konfiguracji.';
            if ($environment === 'production') {
                $errors[] = $message;
            } else {
                $warnings[] = $message;
            }
        }

        return [
            'ok' => $errors === [],
            'environment' => $environment,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param list<string> $errors
     */
    private function validateDors3(string $environment, array &$errors): void
    {
        $mode = strtolower(trim((string)env('DORS3_MODE', 'prepare')));
        $fido2Enabled = env_bool('DORS3_FIDO2_ENABLED', false);
        $fido2Required = env_bool('DORS3_FIDO2_REQUIRED', false);
        $webauthnEnabled = env_bool('WEBAUTHN_ENABLED', false);
        $stepUp = strtolower(trim((string)env('DORS3_CRITICAL_STEP_UP', 'password')));
        $physical = strtolower(trim((string)env('DORS3_PHYSICAL_APPROVAL', 'disabled')));
        $mobileMode = strtolower(trim((string)env('DORS3_MOBILE_MODE', 'disabled')));
        $mobileEnabled = env_bool('DORS3_MOBILE_ENABLED', false);
        $adminMobileEnabled = env_bool('DORS3_ADMIN_APP_ENABLED', false);
        $authorMobileEnabled = env_bool('DORS3_AUTHOR_APP_ENABLED', false);

        if (!in_array($mode, ['prepare', 'test', 'required'], true)) {
            $errors[] = 'DORS3_MODE must be prepare, test or required.';
        }
        if (!in_array($stepUp, ['password', 'fido2'], true)) {
            $errors[] = 'DORS3_CRITICAL_STEP_UP must be password or fido2.';
        }
        if (!in_array($mobileMode, ['disabled', 'test', 'required'], true)) {
            $errors[] = 'DORS3_MOBILE_MODE must be disabled, test or required.';
        }
        if ($mobileMode === 'required') {
            if (!$mobileEnabled || (!$adminMobileEnabled && !$authorMobileEnabled)) {
                $errors[] = 'Required 3DORS Mobile needs an enabled Admin or Author application variant.';
            }
            if ($adminMobileEnabled && (!env_bool('DORS3_PAYOUT_APPROVAL', false) || !env_bool('DORS3_ADMIN_CRITICAL_APPROVAL', false))) {
                $errors[] = 'Required 3DORS Admin must protect payouts and critical administrative operations.';
            }
            if ($authorMobileEnabled && (!env_bool('DORS3_ARTICLE_SUBMIT_APPROVAL', false) || !env_bool('DORS3_ARTICLE_PUBLISH_APPROVAL', false))) {
                $errors[] = 'Required 3DORS Author must protect article submission and publication.';
            }
        }
        if ($physical !== 'disabled') {
            $errors[] = 'DORS3_PHYSICAL_APPROVAL must stay disabled until a real provider is implemented.';
        }
        if ($fido2Required && (!$fido2Enabled || !$webauthnEnabled)) {
            $errors[] = 'Required FIDO2 needs both DORS3_FIDO2_ENABLED and WEBAUTHN_ENABLED.';
        }
        if ($mode === 'prepare' && ($fido2Enabled || $fido2Required || $webauthnEnabled || $stepUp !== 'password')) {
            $errors[] = '3DORS prepare mode requires disabled FIDO2/WebAuthn and password step-up.';
        }
        if ($mode === 'test' && $fido2Required) {
            $errors[] = '3DORS test mode cannot require FIDO2 at login.';
        }
        if ($mode === 'required' && (!$fido2Enabled || !$fido2Required || !$webauthnEnabled || $stepUp !== 'fido2')) {
            $errors[] = '3DORS required mode needs enabled and required FIDO2 with fido2 step-up.';
        }

        $origin = rtrim(trim((string)env('WEBAUTHN_ORIGIN', 'http://localhost:8080')), '/');
        $rpId = strtolower(trim((string)env('WEBAUTHN_RP_ID', 'localhost')));
        $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
        if (!filter_var($origin, FILTER_VALIDATE_URL) || $originHost === '') {
            $errors[] = 'WEBAUTHN_ORIGIN must be one complete origin URL.';
        } elseif ($originHost !== $rpId && !str_ends_with($originHost, '.' . $rpId)) {
            $errors[] = 'WEBAUTHN_RP_ID must match the WEBAUTHN_ORIGIN host.';
        }
        if ($environment === 'production' && strtolower((string)parse_url($origin, PHP_URL_SCHEME)) !== 'https') {
            $errors[] = 'WEBAUTHN_ORIGIN must use HTTPS in production.';
        }
        if (strtolower(trim((string)env('WEBAUTHN_USER_VERIFICATION', 'required'))) !== 'required') {
            $errors[] = 'Administrative WebAuthn requires WEBAUTHN_USER_VERIFICATION=required.';
        }
        if ($webauthnEnabled && !class_exists(\Webauthn\PublicKeyCredential::class)) {
            $errors[] = 'Enabled WebAuthn requires the pinned web-auth/webauthn-lib package.';
        }
    }

    public function assertInstallable(): void
    {
        $result = $this->validate(true);
        if (!$result['ok']) {
            throw new \RuntimeException(
                "Konfiguracja środowiska jest niebezpieczna:\n- " . implode("\n- ", $result['errors'])
            );
        }
    }

    public function isStrongSecret(string $value): bool
    {
        if (strlen($value) < 32 || preg_match('/\s/', $value) === 1) {
            return false;
        }

        return !$this->isPlaceholderValue($value);
    }

    public function isPlaceholderValue(string $value): bool
    {
        return preg_match(
            '/(?:change|replace|example|placeholder|secret_here|wstaw|zmien|twoj|your|changeme)/i',
            $value
        ) === 1;
    }

    private function missingConfigurationValue(string $key): bool
    {
        $value = trim((string)env($key, ''));
        return $value === '' || $this->isPlaceholderValue($value);
    }

    /**
     * @param array<int, string> $errors
     */
    private function validateExternalUrl(string $key, string $environment, array &$errors): void
    {
        $url = trim((string)env($key, ''));
        if ($url === '') {
            return;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = "{$key} musi być pełnym, poprawnym adresem URL.";
            return;
        }
        if ($environment === 'production' && strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            $errors[] = "{$key} na produkcji musi używać HTTPS.";
        }
    }
}
