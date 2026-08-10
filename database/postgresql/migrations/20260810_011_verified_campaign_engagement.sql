ALTER TABLE "campaigns"
    ADD COLUMN IF NOT EXISTS "client_email" varchar(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "order_reference" varchar(120) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "budget_confirmed" boolean NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS "max_verified_events" integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS "duplicate_attempts_count" integer NOT NULL DEFAULT 0;

UPDATE "campaigns"
SET "budget_confirmed" = TRUE
WHERE "status" = 'active' AND "budget_confirmed" = FALSE;

ALTER TABLE "campaign_events"
    ADD COLUMN IF NOT EXISTS "public_id" varchar(36) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "verification_status" varchar(24) NOT NULL DEFAULT 'verified',
    ADD COLUMN IF NOT EXISTS "proof_type" varchar(40) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "proof_reference" varchar(190) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "verified_at" timestamp without time zone DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "idempotency_key" varchar(190) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "talent_activity_type" varchar(80) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "talent_rule_qualified" boolean NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS "talent_points_snapshot" integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS "talent_job_public_id" varchar(36) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "reward_points" integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS "verification_reason" varchar(190) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "metadata_json" jsonb NOT NULL DEFAULT '{}'::jsonb;

UPDATE "campaign_events"
SET "public_id" = COALESCE("public_id", 'legacy-' || "id"::text),
    "verified_at" = COALESCE("verified_at", "created_at"),
    "verification_status" = CASE
        WHEN "cost_minor" > 0 THEN 'verified'
        ELSE 'rejected'
    END,
    "proof_type" = COALESCE("proof_type", 'legacy'),
    "verification_reason" = COALESCE(
        "verification_reason",
        CASE WHEN "cost_minor" > 0 THEN 'legacy_verified' ELSE 'legacy_rejected' END
    );

ALTER TABLE "campaign_events"
    ALTER COLUMN "public_id" SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'campaigns_max_verified_events'
    ) THEN
        ALTER TABLE "campaigns"
            ADD CONSTRAINT "campaigns_max_verified_events"
            CHECK ("max_verified_events" BETWEEN 0 AND 100000000);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'campaigns_duplicate_attempts_count'
    ) THEN
        ALTER TABLE "campaigns"
            ADD CONSTRAINT "campaigns_duplicate_attempts_count"
            CHECK ("duplicate_attempts_count" >= 0);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'campaign_events_verification_status'
    ) THEN
        ALTER TABLE "campaign_events"
            ADD CONSTRAINT "campaign_events_verification_status"
            CHECK ("verification_status" IN ('verified','rejected'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'campaign_events_talent_points_snapshot'
    ) THEN
        ALTER TABLE "campaign_events"
            ADD CONSTRAINT "campaign_events_talent_points_snapshot"
            CHECK ("talent_points_snapshot" BETWEEN 0 AND 1000000);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'campaign_events_reward_points'
    ) THEN
        ALTER TABLE "campaign_events"
            ADD CONSTRAINT "campaign_events_reward_points"
            CHECK ("reward_points" BETWEEN 0 AND 1000000);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_campaign_events_talent_job'
    ) THEN
        ALTER TABLE "campaign_events"
            ADD CONSTRAINT "fk_campaign_events_talent_job"
            FOREIGN KEY ("talent_job_public_id") REFERENCES "background_jobs" ("public_id") ON DELETE RESTRICT;
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS "uq_campaign_events_public_id"
ON "campaign_events" ("public_id");

CREATE UNIQUE INDEX IF NOT EXISTS "uq_campaign_events_idempotency_key"
ON "campaign_events" ("idempotency_key")
WHERE "idempotency_key" IS NOT NULL;

CREATE INDEX IF NOT EXISTS "idx_campaign_events_verification"
ON "campaign_events" ("campaign_id", "verification_status", "created_at" DESC);

CREATE INDEX IF NOT EXISTS "idx_campaign_events_talent_job"
ON "campaign_events" ("talent_job_public_id")
WHERE "talent_job_public_id" IS NOT NULL;
