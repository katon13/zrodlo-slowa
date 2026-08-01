ALTER TABLE `sessions`
  ADD KEY `idx_sessions_user` (`user_id`),
  ADD KEY `idx_sessions_last_activity` (`last_activity`),
  ADD CONSTRAINT `fk_sessions_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
