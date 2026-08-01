<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SelectedMigrationManifestTest extends TestCase
{
    public function testEveryDecisionUsesAnExplicitSupportedStrategy(): void
    {
        $manifest = require dirname(__DIR__, 2) . '/config/mysql_to_postgresql_selected_migration.php';
        $allowed = ['copy_selected', 'copy_transform', 'reassign_to_admin', 'rebuild', 'skip', 'target_only'];
        self::assertSame(4, $manifest['source_admin_id']);
        self::assertSame(129362, $manifest['opening_points']);
        self::assertNotEmpty($manifest['tables']);
        foreach ($manifest['tables'] as $table => $strategy) {
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $table);
            self::assertContains($strategy, $allowed, "Brak jawnej strategii dla {$table}.");
        }
        self::assertSame('reassign_to_admin', $manifest['tables']['articles']);
        self::assertSame('rebuild', $manifest['tables']['wallet_transactions']);
        self::assertSame('skip', $manifest['tables']['sessions']);
        self::assertContains('selected_migration_legacy_wallet_transactions', $manifest['target_only_tables']);
    }
}
