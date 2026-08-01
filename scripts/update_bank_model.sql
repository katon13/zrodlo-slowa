-- Wallets update
ALTER TABLE wallets 
ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN locked_reason VARCHAR(255) NULL,
ADD COLUMN locked_at DATETIME NULL,
ADD COLUMN locked_by BIGINT UNSIGNED NULL;

-- Transactions update
ALTER TABLE wallet_transactions
ADD COLUMN balance_before_minor BIGINT NOT NULL DEFAULT 0 AFTER amount_minor,
ADD COLUMN previous_hash VARCHAR(128) NULL AFTER created_at,
ADD COLUMN entry_hash VARCHAR(128) NULL AFTER previous_hash,
ADD COLUMN hash_algorithm VARCHAR(20) DEFAULT 'sha256' AFTER entry_hash,
ADD COLUMN hash_version INT DEFAULT 1 AFTER hash_algorithm,
ADD COLUMN signed_at DATETIME NULL AFTER hash_version;

-- New table: financial_approvals
CREATE TABLE financial_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_type VARCHAR(50) NOT NULL,
    operation_payload JSON NOT NULL,
    amount BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    wallet_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    requested_role VARCHAR(50) NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_role VARCHAR(50) NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled', 'executed', 'failed') NOT NULL DEFAULT 'pending',
    reason TEXT NULL,
    reject_reason TEXT NULL,
    created_at DATETIME NOT NULL,
    approved_at DATETIME NULL,
    rejected_at DATETIME NULL,
    executed_at DATETIME NULL,
    INDEX idx_fin_app_status (status),
    INDEX idx_fin_app_user (user_id),
    INDEX idx_fin_app_requested (requested_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New table: financial_audit_log
CREATE TABLE financial_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    actor_role VARCHAR(50) NOT NULL,
    amount BIGINT NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    context_json JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_fin_audit_wallet (wallet_id),
    INDEX idx_fin_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trigger for non-negative balance
DELIMITER //
DROP TRIGGER IF EXISTS trg_wallets_before_update //
CREATE TRIGGER trg_wallets_before_update
BEFORE UPDATE ON wallets
FOR EACH ROW
BEGIN
    IF NEW.main_available_minor < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Błąd: Saldo główne nie może być ujemne.';
    END IF;
    IF NEW.slowo_available_minor < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Błąd: Saldo SŁOWO nie może być ujemne.';
    END IF;
END //
DELIMITER ;
