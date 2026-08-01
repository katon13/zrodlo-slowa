ALTER TABLE `users`
    ADD COLUMN `login_name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `legacy_id`;

CREATE UNIQUE INDEX `uq_users_login_name` ON `users` (`login_name`);
