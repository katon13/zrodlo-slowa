<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\App;

$app = App::boot(dirname(__DIR__));
$db = $app->db;

function column_exists($db, string $table, string $column): bool {
    return $db->columnExists($table, $column);
}

$changed = [];

$columns = $db->isPostgres()
    ? [
        'can_write' => 'ALTER TABLE users ADD COLUMN can_write SMALLINT NOT NULL DEFAULT 0',
        'talent_enabled' => 'ALTER TABLE users ADD COLUMN talent_enabled SMALLINT NOT NULL DEFAULT 0',
        'wallet_enabled' => 'ALTER TABLE users ADD COLUMN wallet_enabled SMALLINT NOT NULL DEFAULT 0',
        'payout_enabled' => 'ALTER TABLE users ADD COLUMN payout_enabled SMALLINT NOT NULL DEFAULT 0',
        'permissions_updated_at' => 'ALTER TABLE users ADD COLUMN permissions_updated_at TIMESTAMP NULL',
    ]
    : [
        'can_write' => 'ALTER TABLE users ADD COLUMN can_write TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
        'talent_enabled' => 'ALTER TABLE users ADD COLUMN talent_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER can_write',
        'wallet_enabled' => 'ALTER TABLE users ADD COLUMN wallet_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER talent_enabled',
        'payout_enabled' => 'ALTER TABLE users ADD COLUMN payout_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER wallet_enabled',
        'permissions_updated_at' => 'ALTER TABLE users ADD COLUMN permissions_updated_at DATETIME NULL AFTER updated_at',
    ];

foreach ($columns as $column => $sql) {
    if (!column_exists($db, 'users', $column)) {
        $db->query($sql);
        $changed[] = "users.{$column}";
    }
}

if ($db->isPostgres()) {
    $db->query('UPDATE users u
                SET can_write=1,permissions_updated_at=NOW()
                WHERE u.status=\'active\' AND u.can_write=0
                  AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role=\'author\')');
    $db->query('UPDATE users u
                SET wallet_enabled=1,
                    talent_enabled=CASE WHEN w.points_balance<>0 THEN 1 ELSE u.talent_enabled END,
                    permissions_updated_at=NOW()
                FROM wallets w WHERE w.user_id=u.id');
    $db->query('UPDATE users u
                SET can_write=1,talent_enabled=1,wallet_enabled=1,payout_enabled=1,permissions_updated_at=NOW()
                WHERE u.status=\'active\'
                  AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role=\'admin\')');
} else {
    $db->query('UPDATE users u JOIN user_roles ur ON ur.user_id=u.id AND ur.role=\'author\' SET u.can_write=1, u.permissions_updated_at=NOW() WHERE u.status=\'active\' AND u.can_write=0');
    $db->query('UPDATE users u JOIN wallets w ON w.user_id=u.id SET u.wallet_enabled=1, u.talent_enabled=CASE WHEN w.points_balance <> 0 THEN 1 ELSE u.talent_enabled END, u.permissions_updated_at=NOW()');
    $db->query('UPDATE users u JOIN user_roles ur ON ur.user_id=u.id AND ur.role=\'admin\' SET u.can_write=1, u.talent_enabled=1, u.wallet_enabled=1, u.payout_enabled=1, u.permissions_updated_at=NOW() WHERE u.status=\'active\'');
}

echo json_encode([
    'ok' => true,
    'changed' => $changed,
    'next' => 'Panel /admin/users pokazuje teraz ręczne zgody: Pisanie, Talent, Wallet, Wypłaty.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
