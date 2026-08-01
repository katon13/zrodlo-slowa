-- Krytyczna migracja integralności finansowej.
-- Uruchamiana przez wersjonowany MigrationService; jest bezpieczna do ponownego wykonania
-- w zakresie CREATE/ADD, ale historia migracji gwarantuje pojedyncze zastosowanie.

CREATE TABLE IF NOT EXISTS `financial_ledger_head` (
  `id` tinyint unsigned NOT NULL,
  `last_transaction_id` bigint unsigned DEFAULT NULL,
  `last_entry_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hash_version` int NOT NULL DEFAULT '2',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `financial_ledger_head`
  (`id`,`last_transaction_id`,`last_entry_hash`,`hash_version`,`updated_at`)
VALUES
  (1,NULL,'0000000000000000000000000000000000000000000000000000000000000000',2,NOW());

SET @zs_migration_sql = IF(
  (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'financial_approvals'
      AND COLUMN_NAME = 'admin_note'
  ) = 0,
  'ALTER TABLE `financial_approvals` ADD COLUMN `admin_note` text COLLATE utf8mb4_unicode_ci NULL AFTER `reason`',
  'SELECT 1'
);
PREPARE zs_migration_statement FROM @zs_migration_sql;
EXECUTE zs_migration_statement;
DEALLOCATE PREPARE zs_migration_statement;

ALTER TABLE `wallet_transactions`
  MODIFY COLUMN `source_module` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  MODIFY COLUMN `type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  MODIFY COLUMN `hash_algorithm` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'hmac-sha256',
  MODIFY COLUMN `hash_version` int DEFAULT '2';

UPDATE `financial_ledger_head`
SET
  `last_transaction_id` = (SELECT `id` FROM `wallet_transactions` ORDER BY `id` DESC LIMIT 1),
  `last_entry_hash` = COALESCE(
    (SELECT `entry_hash` FROM `wallet_transactions` ORDER BY `id` DESC LIMIT 1),
    '0000000000000000000000000000000000000000000000000000000000000000'
  ),
  `hash_version` = COALESCE(
    (SELECT `hash_version` FROM `wallet_transactions` ORDER BY `id` DESC LIMIT 1),
    2
  ),
  `updated_at` = NOW()
WHERE `id` = 1;

DROP TRIGGER IF EXISTS `trg_wallets_before_update`;

DELIMITER ;;
CREATE TRIGGER `trg_wallets_before_update`
BEFORE UPDATE ON `wallets`
FOR EACH ROW
BEGIN
    IF NEW.main_available_minor < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Błąd: saldo główne nie może być ujemne.';
    END IF;
    IF NEW.slowo_available_minor < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Błąd: saldo SŁOWO nie może być ujemne.';
    END IF;
    IF NEW.main_reserved_minor < 0 OR NEW.slowo_reserved_minor < 0 OR NEW.reserved_minor < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Błąd: saldo zarezerwowane nie może być ujemne.';
    END IF;
    IF NEW.points_balance < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Błąd: saldo Talentów nie może być ujemne.';
    END IF;
END;;
DELIMITER ;
