-- ETAP 7: księga per-portfel, kontrolowane przełączenie i okresowe anchory Merkle.

ALTER TABLE `wallet_transactions`
  ADD COLUMN `wallet_previous_hash` char(64) COLLATE utf8mb4_unicode_ci NULL AFTER `signed_at`,
  ADD COLUMN `wallet_entry_hash` char(64) COLLATE utf8mb4_unicode_ci NULL AFTER `wallet_previous_hash`,
  ADD COLUMN `wallet_hash_algorithm` varchar(20) COLLATE utf8mb4_unicode_ci NULL AFTER `wallet_entry_hash`,
  ADD COLUMN `wallet_hash_version` int NULL AFTER `wallet_hash_algorithm`,
  ADD COLUMN `wallet_signed_at` datetime NULL AFTER `wallet_hash_version`,
  ADD KEY `idx_wallet_transactions_wallet_chain` (`wallet_id`,`id`);

CREATE TABLE `financial_ledger_migration_state` (
  `id` tinyint unsigned NOT NULL,
  `mode` enum('legacy_global','per_wallet') COLLATE utf8mb4_unicode_ci NOT NULL,
  `legacy_cutover_transaction_id` bigint unsigned DEFAULT NULL,
  `compliance_report_json` json DEFAULT NULL,
  `compliance_report_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `financial_ledger_migration_state` (`id`,`mode`,`updated_at`)
VALUES (1,'legacy_global',NOW());

CREATE TABLE `financial_wallet_ledger_heads` (
  `wallet_id` bigint unsigned NOT NULL,
  `last_transaction_id` bigint unsigned DEFAULT NULL,
  `last_entry_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_count` bigint unsigned NOT NULL DEFAULT 0,
  `hash_version` int NOT NULL DEFAULT 2,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`wallet_id`),
  KEY `fk_financial_wallet_heads_transaction` (`last_transaction_id`),
  CONSTRAINT `fk_financial_wallet_heads_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_financial_wallet_heads_transaction` FOREIGN KEY (`last_transaction_id`) REFERENCES `wallet_transactions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `financial_operations` (
  `idempotency_key` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('processing','completed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `wallet_id` bigint unsigned DEFAULT NULL,
  `transaction_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`idempotency_key`),
  UNIQUE KEY `uq_financial_operations_transaction` (`transaction_id`),
  KEY `fk_financial_operations_wallet` (`wallet_id`),
  CONSTRAINT `fk_financial_operations_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_financial_operations_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `wallet_transactions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `financial_ledger_anchors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `period_start` datetime NOT NULL,
  `period_end` datetime NOT NULL,
  `cutoff_transaction_id` bigint unsigned DEFAULT NULL,
  `wallet_count` int unsigned NOT NULL DEFAULT 0,
  `transaction_count` bigint unsigned NOT NULL DEFAULT 0,
  `merkle_root` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `previous_anchor_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `anchor_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hash_algorithm` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hmac-sha256',
  `hash_version` int NOT NULL DEFAULT 2,
  `manifest_json` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_financial_ledger_anchors_period` (`period_start`,`period_end`),
  KEY `idx_financial_ledger_anchors_created` (`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
