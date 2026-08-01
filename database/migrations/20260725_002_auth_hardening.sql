-- Unieważnianie wszystkich sesji użytkownika po zmianie hasła lub incydencie.

SET @zs_migration_sql = IF(
  (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'session_version'
  ) = 0,
  'ALTER TABLE `users` ADD COLUMN `session_version` int unsigned NOT NULL DEFAULT ''0'' AFTER `last_login_ip_hash`',
  'SELECT 1'
);
PREPARE zs_migration_statement FROM @zs_migration_sql;
EXECUTE zs_migration_statement;
DEALLOCATE PREPARE zs_migration_statement;
