<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDOException;

final class SelectedMigrationArchiveTest extends DatabaseTestCase
{
    public function testLegacyWalletArchiveRejectsUpdates(): void
    {
        $runId = $this->database->insert(
            "INSERT INTO selected_migration_runs(
                public_id,mode,source_database,source_admin_id,source_manifest_hash,financial_approval,status,started_at
             ) VALUES(:public,'dry-run','test',4,:hash,'test_approval','running',NOW())",
            ['public' => 'test-' . bin2hex(random_bytes(8)), 'hash' => str_repeat('a', 64)]
        );
        $archiveId = $this->database->insert(
            'INSERT INTO selected_migration_legacy_wallet_transactions(
                run_id,source_transaction_id,source_wallet_id,source_user_id,source_row_json,source_row_checksum
             ) VALUES(:run,1,4,4,:row,:checksum)',
            ['run' => $runId, 'row' => '{}', 'checksum' => str_repeat('b', 64)]
        );

        try {
            $this->database->query(
                'UPDATE selected_migration_legacy_wallet_transactions SET source_row_checksum=:checksum WHERE id=:id',
                ['checksum' => str_repeat('c', 64), 'id' => $archiveId]
            );
            self::fail('Archiwum pozwoliło na modyfikację.');
        } catch (PDOException $error) {
            self::assertStringContainsString('niezmienne', $error->getMessage());
        }
    }
}
