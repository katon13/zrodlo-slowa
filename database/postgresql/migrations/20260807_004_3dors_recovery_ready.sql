-- Uproszczony model recovery i jednoznaczny routing operacji finansowych.
-- Bez device_purpose i bez klasy urządzeń MASTER/OPERATIONAL.

UPDATE "security_mobile_operation_policies"
SET "application_variant" = 'admin',
    "updated_at" = NOW()
WHERE "action_type" IN ('payout_details.change', 'wallet.own_operation')
  AND "application_variant" <> 'admin';
