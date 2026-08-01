ALTER TABLE "activity_reward_logs"
    ADD COLUMN IF NOT EXISTS "operation_key" varchar(190) NULL,
    ADD COLUMN IF NOT EXISTS "money_wallet_transaction_id" bigint NULL;

CREATE UNIQUE INDEX IF NOT EXISTS "uq_activity_reward_logs_operation_key"
    ON "activity_reward_logs" ("operation_key")
    WHERE "operation_key" IS NOT NULL;

CREATE INDEX IF NOT EXISTS "idx_activity_reward_logs_money_transaction"
    ON "activity_reward_logs" ("money_wallet_transaction_id");

DO $migration$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_activity_reward_logs_money_transaction'
    ) THEN
        ALTER TABLE "activity_reward_logs"
            ADD CONSTRAINT "fk_activity_reward_logs_money_transaction"
            FOREIGN KEY ("money_wallet_transaction_id")
            REFERENCES "wallet_transactions" ("id")
            ON DELETE SET NULL;
    END IF;
END
$migration$;
