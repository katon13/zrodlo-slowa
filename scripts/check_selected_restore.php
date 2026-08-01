<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Infrastructure\Storage\ObjectStorageFactory;
use App\Services\SelectedContentMigrationService;
use App\Services\SelectedWalletVerificationService;

foreach (['MYSQL_SOURCE_DB_HOST', 'MYSQL_SOURCE_DB_NAME', 'MYSQL_SOURCE_DB_USER'] as $required) {
    if (trim((string)env($required, '')) === '') {
        fwrite(STDERR, "Brak {$required}.\n");
        exit(2);
    }
}

try {
    $sourceDatabase = (string)env('MYSQL_SOURCE_DB_NAME');
    $source = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string)env('MYSQL_SOURCE_DB_HOST'),
            (int)env('MYSQL_SOURCE_DB_PORT', 3306),
            $sourceDatabase
        ),
        (string)env('MYSQL_SOURCE_DB_USER'),
        (string)env('MYSQL_SOURCE_DB_PASS', ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
        ]
    );
    $targetConfig = require __DIR__ . '/../config/database.php';
    $target = new Database($targetConfig['default']);
    if (!$target->isPostgres()) {
        throw new RuntimeException('Kontrola wymaga PostgreSQL jako celu.');
    }
    $manifest = require __DIR__ . '/../config/mysql_to_postgresql_selected_migration.php';
    $manifest['_source_database'] = $sourceDatabase;
    $service = new SelectedContentMigrationService($source, $target, $manifest);
    $plan = $service->plan();
    $restored = $service->import('resume');

    $sourceAdminStatement = $source->prepare('SELECT id,email,password_hash FROM users WHERE id=:id');
    $sourceAdminStatement->execute(['id' => (int)$manifest['source_admin_id']]);
    $sourceAdmin = $sourceAdminStatement->fetch(PDO::FETCH_ASSOC);
    $targetAdmin = $target->one('SELECT id,email,password_hash,status FROM users WHERE id=:id', [
        'id' => (int)$manifest['source_admin_id'],
    ]);
    if (!is_array($sourceAdmin) || !$targetAdmin) {
        throw new RuntimeException('Brak administratora po jednej ze stron kontroli.');
    }

    $passwordHashPreserved = hash_equals(
        (string)$sourceAdmin['password_hash'],
        (string)$targetAdmin['password_hash']
    );
    $credentialRotationAudited = (int)$target->cell(
        'SELECT COUNT(*) FROM admin_audit_logs
         WHERE user_id=:id AND action=\'admin.credentials_rotated\'',
        ['id' => (int)$manifest['source_admin_id']]
    ) > 0;

    $checks = [
        'one_business_user' => (int)$target->cell('SELECT COUNT(*) FROM users') === 1,
        'admin_active' => (string)$targetAdmin['status'] === 'active',
        'source_id_preserved' => (int)$targetAdmin['id'] === (int)$sourceAdmin['id'],
        'email_preserved' => hash_equals((string)$sourceAdmin['email'], (string)$targetAdmin['email']),
        'password_hash_preserved_or_rotation_audited' => $passwordHashPreserved || $credentialRotationAudited,
        'admin_and_author_roles' => (int)$target->cell(
            "SELECT COUNT(*) FROM user_roles WHERE user_id=:id AND role IN ('admin','author')",
            ['id' => (int)$manifest['source_admin_id']]
        ) === 2,
        'all_articles_reassigned' => (int)$target->cell(
            'SELECT COUNT(*) FROM articles WHERE author_id<>:id', ['id' => (int)$manifest['source_admin_id']]
        ) === 0,
        'content_and_translations_equal' => ($restored['ok'] ?? false) === true,
        'one_wallet' => (int)$target->cell('SELECT COUNT(*) FROM wallets') === 1,
        'one_active_opening_transaction' => (int)$target->cell('SELECT COUNT(*) FROM wallet_transactions') === 1,
        'no_other_users_or_wallets' => (int)$target->cell(
            'SELECT COUNT(*) FROM users u FULL JOIN wallets w ON w.user_id=u.id
             WHERE COALESCE(u.id,w.user_id)<>:id', ['id' => (int)$manifest['source_admin_id']]
        ) === 0,
    ];

    $wallet = (new SelectedWalletVerificationService(
        $target,
        (int)$manifest['source_admin_id'],
        (int)$manifest['opening_points'],
        (string)$restored['manifest_hash']
    ))->verify();
    $checks['wallet_archive_and_chain_verified'] = $wallet['ok'];

    $storage = ObjectStorageFactory::create(dirname(__DIR__), require __DIR__ . '/../config/storage.php');
    $media = $target->all('SELECT id,path FROM media ORDER BY id');
    $mediaErrors = [];
    foreach ($media as $item) {
        $reference = (string)$item['path'];
        try {
            $stored = $storage->read($reference);
            if (!$storage->isPublicReference($reference) || $stored->contentLength <= 0 || !str_starts_with($stored->contentType, 'image/')) {
                $mediaErrors[] = 'media#' . (int)$item['id'];
            }
        } catch (Throwable) {
            $mediaErrors[] = 'media#' . (int)$item['id'];
        }
    }
    $checks['all_media_readable_from_object_storage'] = count($media) === 5 && $mediaErrors === [];

    $failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
    $report = [
        'ok' => $failed === [],
        'checks' => $checks,
        'failed_checks' => $failed,
        'source_counts' => $plan['source']['content_counts'],
        'target_counts' => $restored['target']['counts'],
        'translations' => $restored['translations'],
        'route_diagnostics' => $restored['route_diagnostics'],
        'wallet' => $wallet,
        'media_count' => count($media),
        'media_errors' => $mediaErrors,
    ];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($report['ok'] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
