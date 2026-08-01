<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\StructuredAuditService;

$config = require __DIR__ . '/../config/database.php';
$db = new Database($config['default']);

$email = trim((string)env('ADMIN_EMAIL', 'admin@zrodlo-slowa.local'));
$password = $argv[1] ?? (string)env('ADMIN_PASSWORD', '');
$loginName = strtolower(trim((string)env('ADMIN_LOGIN', '')));
$allowInsecureLocal = env('APP_ENV', 'production') === 'local'
    && env('ADMIN_ALLOW_INSECURE_PASSWORD', 'false') === 'true';

try {
    if ($email === '') {
        throw new RuntimeException('Brak ADMIN_EMAIL w środowisku.');
    }
    if ($password === '') {
        throw new RuntimeException('Nowe hasło admina nie może być puste.');
    }
    if ((strlen($password) < 12 && !$allowInsecureLocal) || strlen($password) > 4096) {
        throw new RuntimeException(
            'Nowe hasło admina musi mieć od 12 do 4096 znaków. Krótsze hasło jest dozwolone tylko lokalnie po jawnym ustawieniu ADMIN_ALLOW_INSECURE_PASSWORD=true.'
        );
    }
    if ($loginName !== '' && preg_match('/\A[a-z0-9][a-z0-9._-]{2,63}\z/', $loginName) !== 1) {
        throw new RuntimeException(
            'Login musi mieć 3–64 znaki i może zawierać małe litery, cyfry, kropkę, podkreślenie oraz myślnik.'
        );
    }

    $user = $db->one('SELECT id, email, display_name FROM users WHERE email=:email LIMIT 1', [
        'email' => $email,
    ]);
    if (!$user) {
        throw new RuntimeException('Nie znaleziono konta admina dla e-maila: ' . $email);
    }

    $userId = (int)$user['id'];
    $hash = password_hash($password . env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT);

    $db->transaction(function (Database $db) use (
        $userId,
        $hash,
        $loginName,
        $allowInsecureLocal
    ): void {
        if ($loginName !== '') {
            $conflict = $db->one(
                'SELECT id FROM users WHERE LOWER(login_name)=:login AND id<>:id LIMIT 1',
                ['login' => $loginName, 'id' => $userId]
            );
            if ($conflict) {
                throw new RuntimeException('Podany login jest już używany.');
            }
        }

        $db->query(
            'UPDATE users
             SET login_name=CASE WHEN :login_name_empty=1 THEN login_name ELSE :login_name END,
                 password_hash=:hash,
                 status=\'active\',
                 email_verified_at=COALESCE(email_verified_at,NOW()),
                 auth_security_level=\'high\',
                 force_2fa_setup=CASE WHEN two_factor_enabled=1 THEN 0 ELSE 1 END,
                 force_password_change=0,
                 session_version=session_version+1,
                 updated_at=NOW()
             WHERE id=:id',
            [
                'login_name_empty' => $loginName === '' ? 1 : 0,
                'login_name' => $loginName !== '' ? $loginName : null,
                'hash' => $hash,
                'id' => $userId,
            ]
        );

        $role = $db->one(
            'SELECT user_id FROM user_roles WHERE user_id=:id AND role=\'admin\' LIMIT 1',
            ['id' => $userId]
        );
        if (!$role) {
            $db->query('INSERT INTO user_roles(user_id,role) VALUES(:id,\'admin\')', [
                'id' => $userId,
            ]);
        }

        (new StructuredAuditService($db))->record(
            $userId,
            'admin.credentials_rotated',
            [
                'login_changed' => $loginName !== '',
                'login_name' => $loginName !== '' ? $loginName : null,
                'password_changed' => true,
                'sessions_invalidated' => true,
                'insecure_local_password_override' => $allowInsecureLocal,
            ],
            'success',
            $userId
        );
    });

    echo json_encode([
        'ok' => true,
        'admin_email' => $email,
        'admin_login' => $loginName !== '' ? $loginName : null,
        'admin_user_id' => $userId,
        'password_changed' => true,
        'email_verified' => true,
        'sessions_invalidated' => true,
        'source' => isset($argv[1]) ? 'CLI argument' : 'ADMIN_PASSWORD from environment',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD RESETU HASŁA ADMINA: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
