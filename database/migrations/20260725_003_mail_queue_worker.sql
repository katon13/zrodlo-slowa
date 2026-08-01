-- Niezawodna kolejka e-mail: leasing, retry z backoffem i diagnostyka dostarczenia.

ALTER TABLE `mail_queue`
  MODIFY COLUMN `status` enum('queued','sending','retry','sent','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  ADD COLUMN `attempts` int unsigned NOT NULL DEFAULT '0' AFTER `status`,
  ADD COLUMN `max_attempts` int unsigned NOT NULL DEFAULT '5' AFTER `attempts`,
  ADD COLUMN `available_at` datetime NULL AFTER `max_attempts`,
  ADD COLUMN `locked_at` datetime NULL AFTER `available_at`,
  ADD COLUMN `locked_by` varchar(100) COLLATE utf8mb4_unicode_ci NULL AFTER `locked_at`,
  ADD COLUMN `message_id` varchar(255) COLLATE utf8mb4_unicode_ci NULL AFTER `error`,
  ADD COLUMN `updated_at` datetime NULL AFTER `created_at`,
  ADD COLUMN `failed_at` datetime NULL AFTER `sent_at`;

UPDATE `mail_queue`
SET
  `available_at` = COALESCE(`available_at`,`created_at`,NOW()),
  `updated_at` = COALESCE(`updated_at`,`created_at`,NOW());

ALTER TABLE `mail_queue`
  MODIFY COLUMN `available_at` datetime NOT NULL,
  MODIFY COLUMN `updated_at` datetime NOT NULL,
  ADD KEY `idx_mail_queue_claim` (`status`,`available_at`,`id`),
  ADD KEY `idx_mail_queue_lock` (`locked_by`,`status`);
