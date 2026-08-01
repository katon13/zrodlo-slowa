-- Rewizja opublikowanego tekstu nie może zmieniać publicznej treści przed ponowną akceptacją.

ALTER TABLE `articles`
  ADD COLUMN `revision_of_article_id` bigint unsigned NULL AFTER `is_featured`,
  ADD KEY `idx_articles_revision` (`revision_of_article_id`,`status`),
  ADD CONSTRAINT `fk_articles_revision`
    FOREIGN KEY (`revision_of_article_id`) REFERENCES `articles` (`id`) ON DELETE SET NULL;
