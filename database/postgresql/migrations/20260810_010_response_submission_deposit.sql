ALTER TABLE "activity_reward_rules"
    ADD COLUMN IF NOT EXISTS "submission_deposit_points" integer NOT NULL DEFAULT 0;

ALTER TABLE "articles"
    ADD COLUMN IF NOT EXISTS "response_deposit_points" integer DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_deposit_status" varchar(24) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_deposit_snapshotted_at" timestamp without time zone DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_deposit_charged_at" timestamp without time zone DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_deposit_settled_at" timestamp without time zone DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_deposit_debit_transaction_id" bigint DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_deposit_forfeit_transaction_id" bigint DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_deposit_reversal_transaction_id" bigint DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_deposit_refund_transaction_id" bigint DEFAULT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'activity_reward_rules_submission_deposit_points'
    ) THEN
        ALTER TABLE "activity_reward_rules"
            ADD CONSTRAINT "activity_reward_rules_submission_deposit_points"
            CHECK ("submission_deposit_points" BETWEEN 0 AND 1000000);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'articles_response_deposit_points'
    ) THEN
        ALTER TABLE "articles"
            ADD CONSTRAINT "articles_response_deposit_points"
            CHECK ("response_deposit_points" IS NULL OR "response_deposit_points" BETWEEN 0 AND 1000000);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'articles_response_deposit_status'
    ) THEN
        ALTER TABLE "articles"
            ADD CONSTRAINT "articles_response_deposit_status"
            CHECK (
                "response_deposit_status" IS NULL
                OR "response_deposit_status" IN ('not_required','held','forfeited','refunded')
            );
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'articles_response_deposit_snapshot'
    ) THEN
        ALTER TABLE "articles"
            ADD CONSTRAINT "articles_response_deposit_snapshot"
            CHECK (
                "response_deposit_status" IS NULL
                OR (
                    "response_to_article_id" IS NOT NULL
                    AND "response_deposit_points" IS NOT NULL
                    AND "response_deposit_snapshotted_at" IS NOT NULL
                    AND (
                        ("response_deposit_status" = 'not_required' AND "response_deposit_points" = 0)
                        OR (
                            "response_deposit_status" = 'held'
                            AND "response_deposit_points" > 0
                            AND "response_deposit_debit_transaction_id" IS NOT NULL
                        )
                        OR (
                            "response_deposit_status" = 'forfeited'
                            AND "response_deposit_forfeit_transaction_id" IS NOT NULL
                            AND "response_deposit_settled_at" IS NOT NULL
                        )
                        OR (
                            "response_deposit_status" = 'refunded'
                            AND "response_deposit_refund_transaction_id" IS NOT NULL
                            AND "response_deposit_settled_at" IS NOT NULL
                        )
                    )
                )
            );
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_articles_response_deposit_debit') THEN
        ALTER TABLE "articles" ADD CONSTRAINT "fk_articles_response_deposit_debit"
            FOREIGN KEY ("response_deposit_debit_transaction_id") REFERENCES "wallet_transactions" ("id") ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_articles_response_deposit_forfeit') THEN
        ALTER TABLE "articles" ADD CONSTRAINT "fk_articles_response_deposit_forfeit"
            FOREIGN KEY ("response_deposit_forfeit_transaction_id") REFERENCES "wallet_transactions" ("id") ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_articles_response_deposit_reversal') THEN
        ALTER TABLE "articles" ADD CONSTRAINT "fk_articles_response_deposit_reversal"
            FOREIGN KEY ("response_deposit_reversal_transaction_id") REFERENCES "wallet_transactions" ("id") ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_articles_response_deposit_refund') THEN
        ALTER TABLE "articles" ADD CONSTRAINT "fk_articles_response_deposit_refund"
            FOREIGN KEY ("response_deposit_refund_transaction_id") REFERENCES "wallet_transactions" ("id") ON DELETE RESTRICT;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS "idx_articles_response_deposit_status"
ON "articles" ("response_deposit_status", "updated_at" DESC, "id" DESC)
WHERE "response_to_article_id" IS NOT NULL;
