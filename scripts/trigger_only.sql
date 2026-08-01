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
