
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_bonus_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `activity_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_minor` bigint NOT NULL DEFAULT '0',
  `points_amount` int NOT NULL DEFAULT '0',
  `message` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_abn_user_seen` (`user_id`,`seen_at`,`created_at`),
  KEY `idx_slowo_bonus_notifications_user_created` (`user_id`,`created_at`),
  KEY `idx_bonus_notif_user_type_created_keys` (`user_id`,`activity_type`,`created_at`),
  CONSTRAINT `fk_abn_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_reward_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `points_amount` int NOT NULL,
  `amount_minor` bigint NOT NULL DEFAULT '0',
  `wallet_transaction_id` bigint unsigned DEFAULT NULL,
  `live_message` varchar(255) DEFAULT NULL,
  `title_key` varchar(160) DEFAULT NULL,
  `message_key` varchar(160) DEFAULT NULL,
  `description_key` varchar(160) DEFAULT NULL,
  `awarded_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_arl_user_activity` (`user_id`,`activity_type`),
  KEY `idx_arl_awarded_at` (`awarded_at`),
  KEY `idx_arl_transaction` (`wallet_transaction_id`),
  KEY `idx_slowo_activity_logs_user_awarded` (`user_id`,`awarded_at`),
  KEY `idx_activity_reward_logs_user_awarded_stage5` (`user_id`,`awarded_at`),
  CONSTRAINT `fk_arl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_arl_wallet_transaction` FOREIGN KEY (`wallet_transaction_id`) REFERENCES `wallet_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_reward_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `activity_type` varchar(50) NOT NULL,
  `points_amount` int NOT NULL DEFAULT '0',
  `amount_minor` bigint NOT NULL DEFAULT '0',
  `label` varchar(120) DEFAULT NULL,
  `live_message_template` varchar(255) DEFAULT NULL,
  `title_key` varchar(160) DEFAULT NULL,
  `message_key` varchar(160) DEFAULT NULL,
  `description_key` varchar(160) DEFAULT NULL,
  `daily_limit` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_type` (`activity_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ad_clicks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `campaign_event_id` bigint unsigned NOT NULL,
  `clicked_at` datetime NOT NULL,
  `target_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ad_clicks_campaign` (`campaign_id`,`clicked_at`),
  KEY `fk_ad_clicks_user` (`user_id`),
  KEY `fk_ad_clicks_event` (`campaign_event_id`),
  CONSTRAINT `fk_ad_clicks_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_clicks_event` FOREIGN KEY (`campaign_event_id`) REFERENCES `campaign_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_clicks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ad_views` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `campaign_event_id` bigint unsigned NOT NULL,
  `viewed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ad_views_campaign` (`campaign_id`,`viewed_at`),
  KEY `fk_ad_views_event` (`campaign_event_id`),
  KEY `idx_slowo_ad_views_user_viewed` (`user_id`,`viewed_at`),
  CONSTRAINT `fk_ad_views_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_views_event` FOREIGN KEY (`campaign_event_id`) REFERENCES `campaign_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_slowo_admin_audit_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_budget_periods` (
  `period_start` date NOT NULL,
  `budget_minor` bigint NOT NULL,
  `reserved_minor` bigint NOT NULL DEFAULT '0',
  `spent_minor` bigint NOT NULL DEFAULT '0',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`period_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_job_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_job_id` bigint unsigned NOT NULL,
  `event_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `status_from` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_to` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ai_job_events_job` (`ai_job_id`,`created_at`),
  KEY `idx_ai_job_events_type` (`event_type`,`created_at`),
  KEY `idx_ai_job_events_actor` (`actor_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `article_id` bigint unsigned DEFAULT NULL,
  `translation_id` bigint unsigned DEFAULT NULL,
  `type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'openai',
  `model` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `prompt_code` varchar(96) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prompt_version` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `input_hash` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `input_json` json DEFAULT NULL,
  `output_json` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `tokens_input` int DEFAULT NULL,
  `tokens_output` int DEFAULT NULL,
  `estimated_cost_minor` bigint NOT NULL DEFAULT '0',
  `actual_cost_minor` bigint NOT NULL DEFAULT '0',
  `budget_period` date DEFAULT NULL,
  `budget_status` enum('none','reserved','spent','released') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `queued_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_id` (`public_id`),
  KEY `idx_ai_jobs_article` (`article_id`,`created_at`),
  KEY `idx_ai_jobs_status_type` (`status`,`type`),
  KEY `idx_ai_jobs_provider` (`provider`,`model`),
  KEY `idx_ai_jobs_user` (`user_id`,`created_at`),
  KEY `idx_ai_jobs_budget` (`budget_period`,`budget_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_prompt_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(96) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `task_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'v1',
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'openai',
  `model` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `system_prompt` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_template` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `output_schema_json` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_ai_prompt_task_active` (`task_type`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `article_access_grants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `article_id` bigint unsigned NOT NULL,
  `payment_id` bigint unsigned DEFAULT NULL,
  `status` enum('active','revoked','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `revoked_at` datetime DEFAULT NULL,
  `source` enum('wallet','payment','legacy_edd','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'payment',
  `granted_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aag_user_article` (`user_id`,`article_id`),
  KEY `fk_aag_article` (`article_id`),
  KEY `fk_aag_payment` (`payment_id`),
  KEY `idx_slowo_article_access_user_article` (`user_id`,`article_id`,`status`),
  CONSTRAINT `fk_aag_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aag_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_aag_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `article_categories` (
  `article_id` bigint unsigned NOT NULL,
  `category_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`article_id`,`category_id`),
  KEY `fk_ac_category` (`category_id`),
  CONSTRAINT `fk_ac_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ac_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `article_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `article_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `event` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_article_events_article` (`article_id`),
  KEY `fk_article_events_user` (`user_id`),
  KEY `idx_article_events_proofread_lookup` (`event`,`article_id`,`created_at`),
  CONSTRAINT `fk_article_events_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_article_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `article_purchases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `article_id` bigint unsigned NOT NULL,
  `buyer_user_id` bigint unsigned NOT NULL,
  `author_user_id` bigint unsigned NOT NULL,
  `payment_id` bigint unsigned DEFAULT NULL,
  `total_amount_minor` bigint NOT NULL,
  `author_amount_minor` bigint NOT NULL,
  `platform_amount_minor` bigint NOT NULL,
  `author_share_percent` decimal(5,2) NOT NULL DEFAULT '40.00',
  `platform_share_percent` decimal(5,2) NOT NULL DEFAULT '40.00',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `status` enum('paid','refunded','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paid',
  `access_granted_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_article_purchase_buyer_article` (`buyer_user_id`,`article_id`),
  KEY `idx_article_purchases_article` (`article_id`),
  KEY `idx_article_purchases_author` (`author_user_id`),
  KEY `fk_article_purchases_payment` (`payment_id`),
  CONSTRAINT `fk_article_purchases_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_article_purchases_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_article_purchases_buyer` FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_article_purchases_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `article_reads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `article_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_article_reads_article` (`article_id`),
  KEY `idx_slowo_article_reads_user_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_article_reads_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_article_reads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `article_supports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `article_id` bigint unsigned NOT NULL,
  `reader_id` bigint unsigned NOT NULL,
  `author_id` bigint unsigned NOT NULL,
  `payment_id` bigint unsigned NOT NULL,
  `amount_minor` bigint NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_article_supports_article` (`article_id`),
  KEY `fk_support_reader` (`reader_id`),
  KEY `fk_support_author` (`author_id`),
  KEY `fk_support_payment` (`payment_id`),
  CONSTRAINT `fk_support_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_reader` FOREIGN KEY (`reader_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `article_translations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `article_id` bigint unsigned NOT NULL,
  `language` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `source_language` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pl',
  `target_language` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lead` text COLLATE utf8mb4_unicode_ci,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `seo_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_description` text COLLATE utf8mb4_unicode_ci,
  `seo_keywords` text COLLATE utf8mb4_unicode_ci,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `translation_instructions` longtext COLLATE utf8mb4_unicode_ci,
  `ai_provider` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_job_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `published_by` bigint unsigned DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_article_translation_lang` (`article_id`,`target_language`),
  KEY `idx_article_translations_article` (`article_id`,`status`),
  KEY `idx_article_translations_ai_job` (`ai_job_id`),
  KEY `idx_article_translations_language` (`target_language`,`status`),
  KEY `idx_article_translations_article_id` (`article_id`),
  KEY `idx_article_translations_status` (`status`),
  KEY `idx_article_translations_public_seo` (`status`,`language`,`slug`,`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `article_translation_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `translation_id` bigint unsigned NOT NULL,
  `version_no` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lead` text COLLATE utf8mb4_unicode_ci,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `seo_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_description` text COLLATE utf8mb4_unicode_ci,
  `seo_keywords` text COLLATE utf8mb4_unicode_ci,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `translation_instructions` longtext COLLATE utf8mb4_unicode_ci,
  `changed_by` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_article_translation_version` (`translation_id`,`version_no`),
  KEY `idx_article_translation_versions_created` (`translation_id`,`created_at`),
  KEY `fk_article_translation_versions_user` (`changed_by`),
  CONSTRAINT `fk_article_translation_versions_translation` FOREIGN KEY (`translation_id`) REFERENCES `article_translations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_article_translation_versions_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `article_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `article_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lead` text COLLATE utf8mb4_unicode_ci,
  `body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_versions_article` (`article_id`),
  CONSTRAINT `fk_versions_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `articles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `legacy_source` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `author_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lead` text COLLATE utf8mb4_unicode_ci,
  `body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('draft','submitted','review','approved','published','rejected','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `access_mode` enum('free','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'free',
  `price_minor` bigint NOT NULL DEFAULT '0',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `is_premium` tinyint(1) NOT NULL DEFAULT '0',
  `is_unique` tinyint(1) NOT NULL DEFAULT '0',
  `pricing_status` enum('not_priced','priced','free','blocked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_priced',
  `author_share_percent` decimal(5,2) NOT NULL DEFAULT '40.00',
  `platform_share_percent` decimal(5,2) NOT NULL DEFAULT '40.00',
  `editor_valuation_note` text COLLATE utf8mb4_unicode_ci,
  `article_label` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valued_by_admin_id` bigint unsigned DEFAULT NULL,
  `valued_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `source_language` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pl',
  `display_order` int NOT NULL DEFAULT '0',
  `editorial_weight` int NOT NULL DEFAULT '0',
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `revision_of_article_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_articles_status` (`status`),
  KEY `idx_articles_legacy` (`legacy_source`,`legacy_id`),
  KEY `idx_articles_pricing` (`access_mode`,`pricing_status`,`is_premium`,`is_unique`),
  KEY `fk_articles_valued_by_admin` (`valued_by_admin_id`),
  KEY `idx_slowo_articles_status_updated` (`status`,`updated_at`),
  KEY `idx_slowo_articles_author_updated` (`author_id`,`updated_at`),
  KEY `idx_slowo_articles_status_published` (`status`,`published_at`),
  KEY `idx_articles_public_seo` (`status`,`slug`,`published_at`,`updated_at`),
  KEY `idx_articles_revision` (`revision_of_article_id`,`status`),
  CONSTRAINT `fk_articles_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_articles_revision` FOREIGN KEY (`revision_of_article_id`) REFERENCES `articles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_articles_valued_by_admin` FOREIGN KEY (`valued_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_login_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_hash` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent_hash` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_auth_login_user_created` (`user_id`,`created_at`),
  KEY `idx_auth_login_result_created` (`result`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `campaign_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `event_type` enum('view','click','sponsored_read','ppv_purchase','live_join','survey_completed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` date NOT NULL,
  `cost_minor` bigint NOT NULL DEFAULT '0',
  `reward_minor` bigint NOT NULL DEFAULT '0',
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `watch_seconds` int NOT NULL DEFAULT '0',
  `is_rewarded` tinyint(1) NOT NULL DEFAULT '1',
  `fraud_risk_score` int NOT NULL DEFAULT '0',
  `fraud_status` enum('normal','observe','suspect','hold_payout') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campaign_user_daily_event` (`campaign_id`,`user_id`,`event_type`,`event_date`),
  KEY `idx_campaign_events_campaign` (`campaign_id`,`created_at`),
  KEY `idx_campaign_events_user` (`user_id`,`created_at`),
  KEY `idx_slowo_campaign_events_user_created` (`user_id`,`created_at`),
  KEY `idx_campaign_events_fraud` (`fraud_status`,`fraud_risk_score`,`created_at`),
  KEY `idx_campaign_events_user_type_date_stage5` (`user_id`,`event_type`,`event_date`),
  CONSTRAINT `fk_campaign_events_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_campaign_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_name` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(220) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('ad_click','display_ad','sponsored_article','ad_view','ppv','live','survey_ad') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'display_ad',
  `description` text COLLATE utf8mb4_unicode_ci,
  `target_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `budget_minor` bigint NOT NULL DEFAULT '0',
  `cost_per_view_minor` bigint NOT NULL DEFAULT '0',
  `cost_per_click_minor` bigint NOT NULL DEFAULT '0',
  `cost_per_completed_survey_minor` bigint NOT NULL DEFAULT '0',
  `reward_for_user_minor` bigint NOT NULL DEFAULT '0',
  `status` enum('draft','active','paused','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created_by_admin_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_status_dates` (`status`,`starts_at`,`ends_at`),
  KEY `idx_campaign_type` (`type`),
  KEY `fk_campaign_admin` (`created_by_admin_id`),
  KEY `idx_slowo_campaigns_status_created` (`status`,`created_at`),
  CONSTRAINT `fk_campaign_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `legacy_source` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'category',
  `created_at` datetime NOT NULL,
  `show_in_menu` tinyint(1) NOT NULL DEFAULT '1',
  `menu_order` int NOT NULL DEFAULT '100',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_legacy` (`legacy_source`,`legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `category_translations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint unsigned NOT NULL,
  `language` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_language` (`category_id`,`language`),
  CONSTRAINT `fk_ct_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `donation_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `target_amount_minor` bigint NOT NULL DEFAULT '0',
  `current_amount_minor` bigint NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_donation_campaign_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `donations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint unsigned NOT NULL,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `campaign` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_amount_minor` bigint DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_donation_payment` (`payment_id`),
  KEY `fk_donation_campaign` (`campaign_id`),
  CONSTRAINT `fk_donation_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `donation_campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_donation_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_verification_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `token_hash` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_evt_user_created` (`user_id`,`created_at`),
  KEY `idx_evt_token` (`token_hash`),
  CONSTRAINT `fk_evt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_balance_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `legacy_wallet_id` bigint unsigned DEFAULT NULL,
  `legacy_points` bigint NOT NULL DEFAULT '0',
  `imported_points` bigint NOT NULL DEFAULT '0',
  `diff_points` bigint NOT NULL DEFAULT '0',
  `legacy_amount_minor` bigint NOT NULL DEFAULT '0',
  `imported_available_minor` bigint NOT NULL DEFAULT '0',
  `diff_minor` bigint NOT NULL DEFAULT '0',
  `status` enum('ok','adjusted','missing_snapshot','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ok',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_balance_report_run` (`run_id`),
  KEY `fk_balance_report_user` (`user_id`),
  CONSTRAINT `fk_balance_report_run` FOREIGN KEY (`run_id`) REFERENCES `finance_import_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_balance_report_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_import_errors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint unsigned NOT NULL,
  `source_table` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_import_errors_run` (`run_id`),
  CONSTRAINT `fk_import_errors_run` FOREIGN KEY (`run_id`) REFERENCES `finance_import_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_import_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('started','finished','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'started',
  `summary_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_legacy_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint unsigned DEFAULT NULL,
  `source_table` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `ref_transaction_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occurred_at` datetime DEFAULT NULL,
  `payload_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_legacy_event` (`source_table`,`legacy_id`),
  KEY `idx_legacy_event_ref` (`ref_transaction_id`),
  KEY `fk_legacy_events_run` (`run_id`),
  CONSTRAINT `fk_legacy_events_run` FOREIGN KEY (`run_id`) REFERENCES `finance_import_runs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_legacy_wallet_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `legacy_wallet_id` bigint unsigned NOT NULL,
  `legacy_points` bigint NOT NULL,
  `legacy_amount_minor` bigint NOT NULL,
  `status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_legacy_wallet_snapshot` (`legacy_wallet_id`),
  KEY `idx_legacy_snapshot_user` (`user_id`),
  KEY `fk_snapshot_run` (`run_id`),
  CONSTRAINT `fk_snapshot_run` FOREIGN KEY (`run_id`) REFERENCES `finance_import_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_snapshot_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_ledger_head` (
  `id` tinyint unsigned NOT NULL,
  `last_transaction_id` bigint unsigned DEFAULT NULL,
  `last_entry_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hash_version` int NOT NULL DEFAULT '2',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `financial_ledger_head` (`id`,`last_transaction_id`,`last_entry_hash`,`hash_version`,`updated_at`)
VALUES (1,NULL,'0000000000000000000000000000000000000000000000000000000000000000',2,NOW());
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_approvals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `operation_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation_payload` json NOT NULL,
  `amount` bigint NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `wallet_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `requested_by` bigint unsigned NOT NULL,
  `requested_role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled','executed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `admin_note` text COLLATE utf8mb4_unicode_ci,
  `reject_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `executed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fin_app_status` (`status`),
  KEY `idx_fin_app_user` (`user_id`),
  KEY `idx_fin_app_requested` (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `wallet_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `actor_role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` bigint NOT NULL,
  `before_json` json DEFAULT NULL,
  `after_json` json DEFAULT NULL,
  `context_json` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fin_audit_wallet` (`wallet_id`),
  KEY `idx_fin_audit_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fraud_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `risk_score` int NOT NULL DEFAULT '0',
  `status` enum('normal','observe','suspect','hold_payout') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `reasons_json` json DEFAULT NULL,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fraud_events_user_created` (`user_id`,`created_at`),
  KEY `idx_fraud_events_score_created` (`risk_score`,`created_at`),
  KEY `idx_fraud_events_status_created` (`status`,`created_at`),
  KEY `idx_fraud_events_ref` (`reference_type`,`reference_id`),
  CONSTRAINT `fk_fraud_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `legacy_edd_product_map` (
  `edd_product_id` bigint unsigned NOT NULL,
  `target_type` enum('article','wallet_package','donation','donation_campaign') COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint unsigned DEFAULT NULL,
  `confidence` int DEFAULT '100',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`edd_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `live_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `title` varchar(220) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','scheduled','live','ended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_live_campaign` (`campaign_id`),
  CONSTRAINT `fk_live_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `idempotency_key` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('queued','sending','retry','sent','failed','dead_letter','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `max_attempts` int unsigned NOT NULL DEFAULT '5',
  `available_at` datetime NOT NULL,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `message_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `dead_lettered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mail_queue_idempotency` (`idempotency_key`),
  KEY `idx_mail_queue_claim` (`status`,`available_at`,`id`),
  KEY `idx_mail_queue_lock` (`locked_by`,`status`),
  KEY `fk_mail_user` (`user_id`),
  CONSTRAINT `fk_mail_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `main_banner_translations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `banner_id` int unsigned NOT NULL,
  `language` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kicker` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lead_text` text COLLATE utf8mb4_unicode_ci,
  `body_text` text COLLATE utf8mb4_unicode_ci,
  `button_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_main_banner_translations_lang` (`banner_id`,`language`),
  KEY `idx_main_banner_translations_language` (`language`),
  CONSTRAINT `fk_main_banner_translations_banner` FOREIGN KEY (`banner_id`) REFERENCES `main_banners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `main_banners` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'home-main',
  `button_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '/register',
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '/assets/img/articles/hero-pier.svg',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_main_banners_slug` (`slug`),
  KEY `idx_main_banners_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `legacy_source` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `owner_user_id` bigint unsigned DEFAULT NULL,
  `article_id` bigint unsigned DEFAULT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `image_position` int DEFAULT '50',
  PRIMARY KEY (`id`),
  KEY `idx_media_legacy` (`legacy_source`,`legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_resets_token` (`token_hash`),
  KEY `fk_password_resets_user` (`user_id`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint unsigned NOT NULL,
  `event_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_payment_events_payment` (`payment_id`),
  CONSTRAINT `fk_payment_events_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_gateway_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_order_id` bigint unsigned DEFAULT NULL,
  `stripe_session_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `processed_at` datetime DEFAULT NULL,
  `processing_status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'received',
  `payload_json` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_event` (`provider`,`event_id`),
  KEY `idx_gateway_events_order` (`payment_order_id`),
  KEY `idx_gateway_events_type_received` (`event_type`,`received_at`),
  CONSTRAINT `fk_gateway_events_order` FOREIGN KEY (`payment_order_id`) REFERENCES `payment_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint unsigned NOT NULL,
  `legacy_source` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `item_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `amount_minor` bigint NOT NULL,
  `raw_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_payment_items_payment` (`payment_id`),
  CONSTRAINT `fk_payment_items_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_minor` bigint NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `topup_package_id` bigint unsigned DEFAULT NULL,
  `stripe_session_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credited_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `metadata_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_id` (`public_id`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `idx_payment_orders_user_created` (`user_id`,`created_at`),
  KEY `idx_payment_orders_provider_status` (`provider`,`status`),
  KEY `idx_payment_orders_stripe_session` (`stripe_session_id`),
  KEY `idx_payment_orders_stripe_pi` (`stripe_payment_intent_id`),
  KEY `fk_payment_orders_topup_package` (`topup_package_id`),
  CONSTRAINT `fk_payment_orders_topup_package` FOREIGN KEY (`topup_package_id`) REFERENCES `wallet_topup_packages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payment_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_webhook_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_id` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` json DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_webhook_event` (`provider`,`external_id`,`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `legacy_source` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_status` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('donation','wallet_topup','article_payment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','paid','failed','refunded','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `amount_minor` bigint NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `payer_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_id` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_key` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `raw_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payments_legacy` (`legacy_source`,`legacy_id`),
  KEY `idx_payments_status` (`status`),
  KEY `fk_payments_user` (`user_id`),
  CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payout_methods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `type` enum('bank','blik','paypal','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_ref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_payout_method_user` (`user_id`),
  CONSTRAINT `fk_payout_method_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payout_status_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payout_id` bigint unsigned NOT NULL,
  `from_status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by_user_id` bigint unsigned DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payout_status_logs_payout` (`payout_id`,`created_at`),
  KEY `idx_payout_status_logs_user` (`changed_by_user_id`,`created_at`),
  CONSTRAINT `fk_payout_status_logs_payout` FOREIGN KEY (`payout_id`) REFERENCES `payouts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payout_status_logs_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payouts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `payout_method_id` bigint unsigned DEFAULT NULL,
  `amount_minor` bigint NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `status` enum('requested','approved','paid','rejected','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',
  `note` text COLLATE utf8mb4_unicode_ci,
  `admin_note` text COLLATE utf8mb4_unicode_ci,
  `requested_at` datetime NOT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `fraud_risk_score` int NOT NULL DEFAULT '0',
  `fraud_status` enum('normal','observe','suspect','hold_payout') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `fraud_checked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_payout_user` (`user_id`),
  KEY `fk_payout_method` (`payout_method_id`),
  KEY `idx_slowo_payouts_status_requested` (`status`,`requested_at`),
  KEY `idx_payouts_fraud` (`fraud_status`,`fraud_risk_score`,`requested_at`),
  CONSTRAINT `fk_payout_method` FOREIGN KEY (`payout_method_id`) REFERENCES `payout_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payout_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `platform_revenues` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint unsigned DEFAULT NULL,
  `article_id` bigint unsigned DEFAULT NULL,
  `buyer_user_id` bigint unsigned DEFAULT NULL,
  `author_user_id` bigint unsigned DEFAULT NULL,
  `total_amount_minor` bigint NOT NULL,
  `author_income_minor` bigint NOT NULL,
  `publisher_fee_minor` bigint NOT NULL,
  `publisher_fee_percent` decimal(5,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_pr_payment` (`payment_id`),
  KEY `fk_pr_article` (`article_id`),
  KEY `fk_pr_buyer` (`buyer_user_id`),
  KEY `fk_pr_author` (`author_user_id`),
  CONSTRAINT `fk_pr_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pr_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pr_buyer` FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pr_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ppv_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `title` varchar(220) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `price_minor` bigint NOT NULL DEFAULT '0',
  `status` enum('draft','active','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_ppv_campaign` (`campaign_id`),
  CONSTRAINT `fk_ppv_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `payload` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user` (`user_id`),
  KEY `idx_sessions_last_activity` (`last_activity`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sponsored_article_reads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `campaign_event_id` bigint unsigned NOT NULL,
  `article_id` bigint unsigned DEFAULT NULL,
  `read_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_sponsored_reads_campaign` (`campaign_id`),
  KEY `fk_sponsored_reads_user` (`user_id`),
  KEY `fk_sponsored_reads_event` (`campaign_event_id`),
  KEY `fk_sponsored_reads_article` (`article_id`),
  CONSTRAINT `fk_sponsored_reads_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sponsored_reads_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sponsored_reads_event` FOREIGN KEY (`campaign_event_id`) REFERENCES `campaign_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sponsored_reads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `survey_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `survey_id` bigint unsigned NOT NULL,
  `question_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `question_type` enum('single_choice','multiple_choice','text') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single_choice',
  `options_json` json DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_required` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_survey_questions_survey` (`survey_id`,`sort_order`),
  CONSTRAINT `fk_survey_questions_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `survey_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `survey_id` bigint unsigned NOT NULL,
  `generated_by_admin_id` bigint unsigned DEFAULT NULL,
  `title` varchar(220) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_reports_survey` (`survey_id`),
  KEY `fk_survey_reports_admin` (`generated_by_admin_id`),
  CONSTRAINT `fk_survey_reports_admin` FOREIGN KEY (`generated_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_survey_reports_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `survey_response_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `response_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `answer_text` text COLLATE utf8mb4_unicode_ci,
  `answer_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_survey_response_items_response` (`response_id`),
  KEY `idx_survey_response_items_question` (`question_id`),
  CONSTRAINT `fk_survey_response_items_question` FOREIGN KEY (`question_id`) REFERENCES `survey_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_survey_response_items_response` FOREIGN KEY (`response_id`) REFERENCES `survey_responses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `survey_responses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `survey_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `reward_amount_minor` bigint NOT NULL DEFAULT '0',
  `reward_status` enum('pending','paid','blocked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `wallet_transaction_id` bigint unsigned DEFAULT NULL,
  `completed_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `answer_seconds` int NOT NULL DEFAULT '0',
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fraud_risk_score` int NOT NULL DEFAULT '0',
  `fraud_status` enum('normal','observe','suspect','hold_payout') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_survey_response_user` (`survey_id`,`user_id`),
  KEY `idx_survey_responses_user` (`user_id`),
  KEY `fk_survey_responses_wallet_tx` (`wallet_transaction_id`),
  KEY `idx_slowo_survey_responses_survey_completed` (`survey_id`,`completed_at`),
  KEY `idx_survey_responses_fraud` (`fraud_status`,`fraud_risk_score`,`created_at`),
  KEY `idx_survey_responses_user_created_stage5` (`user_id`,`created_at`),
  CONSTRAINT `fk_survey_responses_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_survey_responses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_survey_responses_wallet_tx` FOREIGN KEY (`wallet_transaction_id`) REFERENCES `wallet_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `surveys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(220) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `type` enum('consumer','political_poll','social_poll','local_poll','advertising','editorial','market_research') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'editorial',
  `client_name` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `budget_minor` bigint NOT NULL DEFAULT '0',
  `reward_amount_minor` bigint NOT NULL DEFAULT '0',
  `max_responses` int unsigned DEFAULT NULL,
  `status` enum('draft','active','paused','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created_by_admin_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_surveys_status_dates` (`status`,`starts_at`,`ends_at`),
  KEY `idx_surveys_type` (`type`),
  KEY `fk_surveys_admin` (`created_by_admin_id`),
  KEY `idx_slowo_surveys_status_updated` (`status`,`updated_at`),
  CONSTRAINT `fk_surveys_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_activity_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `activity_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_activity_events_user` (`user_id`,`created_at`),
  KEY `idx_user_activity_events_type` (`activity_type`,`created_at`),
  CONSTRAINT `fk_user_activity_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_delete_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deleted_user_id` bigint unsigned NOT NULL,
  `deleted_by_admin_id` bigint unsigned DEFAULT NULL,
  `mode` enum('report','deactivate','anonymize','hard_clean') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'report',
  `summary_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_delete_reports_user` (`deleted_user_id`,`created_at`),
  KEY `idx_user_delete_reports_admin` (`deleted_by_admin_id`,`created_at`),
  CONSTRAINT `fk_user_delete_reports_admin` FOREIGN KEY (`deleted_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_oauth_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `provider` enum('google','apple') COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_user_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `provider_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_avatar_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linked_at` datetime NOT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `provider` (`provider`,`provider_user_id`),
  KEY `user_id` (`user_id`),
  KEY `provider_email` (`provider_email`),
  CONSTRAINT `user_oauth_accounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `avatar_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_profiles_user` (`user_id`),
  CONSTRAINT `fk_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_roles` (
  `user_id` bigint unsigned NOT NULL,
  `role` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`user_id`,`role`),
  KEY `idx_slowo_user_roles_user_role` (`user_id`,`role`),
  KEY `idx_slowo_user_roles_role_user` (`role`,`user_id`),
  KEY `idx_slowo_user_roles_role_user_stage4` (`role`,`user_id`),
  CONSTRAINT `fk_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `legacy_source` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `login_name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','inactive','suspended','blocked','pending','pending_author','rejected','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `can_write` tinyint(1) NOT NULL DEFAULT '0',
  `talent_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `wallet_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `payout_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `permissions_updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by_admin_id` bigint unsigned DEFAULT NULL,
  `deletion_mode` enum('none','anonymized','hard_clean') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `anonymized_at` datetime DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `two_factor_secret` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auth_security_level` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'basic',
  `force_2fa_setup` tinyint(1) NOT NULL DEFAULT '0',
  `force_password_change` tinyint(1) NOT NULL DEFAULT '0',
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip_hash` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session_version` int unsigned NOT NULL DEFAULT '0',
  `display_currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'AUTO',
  `interface_language` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_updated_at` datetime DEFAULT NULL,
  `article_submit_blocked_until` datetime DEFAULT NULL,
  `article_submit_block_reason` text COLLATE utf8mb4_unicode_ci,
  `article_submit_blocked_by` bigint unsigned DEFAULT NULL,
  `article_submit_blocked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_login_name` (`login_name`),
  KEY `idx_users_legacy` (`legacy_source`,`legacy_id`),
  KEY `idx_users_deletion` (`status`,`deleted_at`,`deletion_mode`),
  KEY `fk_users_deleted_by_admin` (`deleted_by_admin_id`),
  KEY `idx_slowo_users_status_created` (`status`,`created_at`),
  KEY `idx_users_email_verified` (`email_verified_at`),
  KEY `idx_users_two_factor_enabled` (`two_factor_enabled`),
  KEY `idx_users_auth_security_level` (`auth_security_level`),
  KEY `idx_users_article_submit_blocked_until` (`article_submit_blocked_until`),
  CONSTRAINT `fk_users_deleted_by_admin` FOREIGN KEY (`deleted_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallet_price_packages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `legacy_source` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `points` bigint NOT NULL,
  `amount_minor` bigint NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallet_price_package` (`points`,`amount_minor`,`currency`),
  KEY `idx_wallet_packages_legacy` (`legacy_source`,`legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallet_topup_packages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount_minor` bigint NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `talent_amount` bigint DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_wallet_topup_packages_active_sort` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallet_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `wallet_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `source_module` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` enum('main','slowo','points') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'slowo',
  `status` enum('pending','posted','reserved','cancelled','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'posted',
  `amount_minor` bigint NOT NULL DEFAULT '0',
  `balance_before_minor` bigint NOT NULL DEFAULT '0',
  `points` bigint NOT NULL DEFAULT '0',
  `balance_after_minor` bigint NOT NULL DEFAULT '0',
  `points_after` bigint NOT NULL DEFAULT '0',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `counterparty_user_id` bigint unsigned DEFAULT NULL,
  `ref_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ref_id` bigint unsigned DEFAULT NULL,
  `legacy_source` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` bigint unsigned DEFAULT NULL,
  `idempotency_key` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `previous_hash` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entry_hash` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hash_algorithm` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'hmac-sha256',
  `hash_version` int DEFAULT '2',
  `signed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallet_tx_idempotency` (`idempotency_key`),
  KEY `idx_wallet_tx_user` (`user_id`),
  KEY `idx_wallet_tx_legacy` (`legacy_source`,`legacy_id`),
  KEY `fk_tx_wallet` (`wallet_id`),
  KEY `fk_tx_counterparty` (`counterparty_user_id`),
  KEY `idx_slowo_wallet_tx_user_created` (`user_id`,`created_at`),
  KEY `idx_slowo_wallet_tx_user_type_created` (`user_id`,`type`,`created_at`),
  KEY `idx_wallet_tx_user_type_created_keys` (`user_id`,`type`,`created_at`),
  CONSTRAINT `fk_tx_counterparty` FOREIGN KEY (`counterparty_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tx_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tx_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallet_transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `direction` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_wallet` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_wallet` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_amount` bigint NOT NULL,
  `target_amount` bigint NOT NULL,
  `fee_amount` bigint NOT NULL DEFAULT '0',
  `rate_numerator` bigint NOT NULL DEFAULT '1',
  `rate_denominator` bigint NOT NULL DEFAULT '1',
  `risk_score` int NOT NULL DEFAULT '0',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wallet_transfers_user_created` (`user_id`,`created_at`),
  KEY `idx_wallet_transfers_status_created` (`status`,`created_at`),
  KEY `idx_wallet_transfers_direction` (`direction`),
  KEY `fk_wallet_transfers_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_wallet_transfers_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wallet_transfers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `main_available_minor` bigint NOT NULL DEFAULT '0',
  `main_reserved_minor` bigint NOT NULL DEFAULT '0',
  `slowo_available_minor` bigint NOT NULL DEFAULT '0',
  `slowo_reserved_minor` bigint NOT NULL DEFAULT '0',
  `available_minor` bigint NOT NULL DEFAULT '0',
  `pending_minor` bigint NOT NULL DEFAULT '0',
  `reserved_minor` bigint NOT NULL DEFAULT '0',
  `points_balance` bigint NOT NULL DEFAULT '0',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `legacy_wallet_id` bigint unsigned DEFAULT NULL,
  `legacy_wallet_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `locked_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_wallet_legacy` (`legacy_wallet_id`),
  CONSTRAINT `fk_wallet_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `trg_wallets_before_update` BEFORE UPDATE ON `wallets` FOR EACH ROW BEGIN
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
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

-- ETAP 7: nowa instalacja startuje od pustej, zweryfikowanej księgi per-portfel.
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
INSERT INTO `financial_ledger_migration_state` (`id`,`mode`,`compliance_report_json`,`compliance_report_hash`,`verified_at`,`activated_at`,`updated_at`)
VALUES (1,'per_wallet','{"legacy_transactions":0,"wallet_transactions":0,"wallets":0,"errors":[]}','81419e7755b60b2dc579724ef82203118ea3fa5569380637bcef247a5028c414',NOW(),NOW(),NOW());

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

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


