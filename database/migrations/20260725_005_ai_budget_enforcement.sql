-- Atomowa rezerwacja i rozliczenie miesięcznego budżetu AI.

CREATE TABLE `ai_budget_periods` (
  `period_start` date NOT NULL,
  `budget_minor` bigint NOT NULL,
  `reserved_minor` bigint NOT NULL DEFAULT '0',
  `spent_minor` bigint NOT NULL DEFAULT '0',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLN',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`period_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ai_jobs`
  ADD COLUMN `actual_cost_minor` bigint NOT NULL DEFAULT '0' AFTER `estimated_cost_minor`,
  ADD COLUMN `budget_period` date NULL AFTER `actual_cost_minor`,
  ADD COLUMN `budget_status` enum('none','reserved','spent','released') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' AFTER `budget_period`,
  ADD KEY `idx_ai_jobs_budget` (`budget_period`,`budget_status`);
