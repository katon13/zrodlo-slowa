-- ETAP 6: trwałe kolejki workerów, leasing, idempotencja i dead-letter.

CREATE TABLE `background_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `job_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('queued','running','retry','completed','dead_letter','rejected','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `priority` int NOT NULL DEFAULT '0',
  `payload_json` json NOT NULL,
  `result_json` json DEFAULT NULL,
  `idempotency_key` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `required_permission` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_ip` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `retry_policy` enum('automatic','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'automatic',
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `max_attempts` int unsigned NOT NULL DEFAULT '5',
  `available_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lease_expires_at` datetime DEFAULT NULL,
  `locked_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `dead_lettered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_background_jobs_public_id` (`public_id`),
  UNIQUE KEY `uq_background_jobs_idempotency` (`queue_name`,`idempotency_key`),
  KEY `idx_background_jobs_claim` (`queue_name`,`status`,`priority`,`available_at`,`id`),
  KEY `idx_background_jobs_lease` (`status`,`lease_expires_at`),
  KEY `fk_background_jobs_actor` (`actor_user_id`),
  CONSTRAINT `fk_background_jobs_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `background_job_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `background_job_id` bigint unsigned NOT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `worker_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_background_job_events_job` (`background_job_id`,`created_at`),
  CONSTRAINT `fk_background_job_events_job` FOREIGN KEY (`background_job_id`) REFERENCES `background_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scheduler_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scheduled_for` datetime NOT NULL,
  `status` enum('running','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `result_json` json DEFAULT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheduler_runs_slot` (`task_name`,`scheduled_for`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `mail_queue`
  MODIFY COLUMN `status` enum('queued','sending','retry','sent','failed','dead_letter','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  ADD COLUMN `idempotency_key` varchar(190) COLLATE utf8mb4_unicode_ci NULL AFTER `body`,
  ADD COLUMN `dead_lettered_at` datetime NULL AFTER `failed_at`;

UPDATE `mail_queue` SET `idempotency_key`=CONCAT('legacy-mail:',`id`) WHERE `idempotency_key` IS NULL;

ALTER TABLE `mail_queue`
  MODIFY COLUMN `idempotency_key` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  ADD UNIQUE KEY `uq_mail_queue_idempotency` (`idempotency_key`);
