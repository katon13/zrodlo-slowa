-- 3DORS Wartownik: one logical operation per alert and an immutable cold archive.
-- Source audit rows are never discarded. The protected maintenance action moves
-- old, unreferenced rows from the hot tables into append-only archive tables.

ALTER TABLE "security_alerts"
ADD COLUMN IF NOT EXISTS "operation_key" varchar(512) NULL,
ADD COLUMN IF NOT EXISTS "event_count" integer NOT NULL DEFAULT 1,
ADD COLUMN IF NOT EXISTS "last_event_at" timestamp without time zone NULL,
ADD COLUMN IF NOT EXISTS "resolution_code" varchar(40) NULL;

ALTER TABLE "security_alerts" DROP CONSTRAINT IF EXISTS "security_alerts_severity";
ALTER TABLE "security_alerts"
ADD CONSTRAINT "security_alerts_severity" CHECK ("severity" IN ('medium','high','critical'));

ALTER TABLE "security_alert_transitions"
ADD COLUMN IF NOT EXISTS "reason_code" varchar(40) NULL;

UPDATE "security_alerts" a
SET "operation_key" = CASE
        WHEN COALESCE(e."metadata"->>'operation','') <> '' AND COALESCE(e."correlation_id",'') <> ''
            THEN CONCAT('operation:',e."correlation_id",'|',e."metadata"->>'operation','|',COALESCE(e."resource_type",''),'|',COALESCE(e."resource_id",''))
        WHEN COALESCE(e."metadata"->>'approval_request_id','') <> ''
            THEN CONCAT('mobile:',e."metadata"->>'approval_request_id')
        ELSE CONCAT('event:',e."event_id")
    END,
    "last_event_at" = COALESCE(a."last_event_at",e."occurred_at")
FROM "security_events" e
WHERE e."id"=a."source_event_id" AND a."operation_key" IS NULL;

-- Preserve lifecycle history and notification recipients while folding legacy
-- duplicate step-up alerts into the latest record for the same operation.
WITH duplicate_map AS (
    SELECT a."id" AS duplicate_id,MAX(a."id") OVER (PARTITION BY a."operation_key") AS survivor_id
    FROM "security_alerts" a
)
UPDATE "security_alert_transitions" t
SET "alert_id"=m.survivor_id
FROM duplicate_map m
WHERE t."alert_id"=m.duplicate_id AND m.duplicate_id<>m.survivor_id;

WITH duplicate_map AS (
    SELECT a."id" AS duplicate_id,MAX(a."id") OVER (PARTITION BY a."operation_key") AS survivor_id
    FROM "security_alerts" a
)
INSERT INTO "security_alert_notifications"(
    "alert_id","recipient_user_id","channel","status","attempts","next_attempt_at",
    "locked_at","mail_queue_id","queued_at","last_error","created_at","updated_at"
)
SELECT m.survivor_id,n."recipient_user_id",n."channel",n."status",n."attempts",n."next_attempt_at",
       n."locked_at",n."mail_queue_id",n."queued_at",n."last_error",n."created_at",n."updated_at"
FROM "security_alert_notifications" n
JOIN duplicate_map m ON m.duplicate_id=n."alert_id" AND m.duplicate_id<>m.survivor_id
ON CONFLICT("alert_id","recipient_user_id","channel") DO NOTHING;

WITH duplicate_map AS (
    SELECT a."id" AS duplicate_id,MAX(a."id") OVER (PARTITION BY a."operation_key") AS survivor_id
    FROM "security_alerts" a
)
DELETE FROM "security_alert_notifications" n
USING duplicate_map m
WHERE n."alert_id"=m.duplicate_id AND m.duplicate_id<>m.survivor_id;

WITH duplicate_map AS (
    SELECT a."id" AS duplicate_id,MAX(a."id") OVER (PARTITION BY a."operation_key") AS survivor_id
    FROM "security_alerts" a
)
DELETE FROM "security_alerts" a
USING duplicate_map m
WHERE a."id"=m.duplicate_id AND m.duplicate_id<>m.survivor_id;

ALTER TABLE "security_alerts" ALTER COLUMN "operation_key" SET NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS "uq_security_alerts_operation_key"
ON "security_alerts" ("operation_key");
CREATE INDEX IF NOT EXISTS "idx_security_alerts_status_changed_id"
ON "security_alerts" ("status","status_changed_at" DESC,"id" DESC);

CREATE TABLE IF NOT EXISTS "security_alert_events" (
    "alert_id" bigint NOT NULL,
    "event_id" bigint NOT NULL,
    "linked_at" timestamp without time zone NOT NULL DEFAULT NOW(),
    PRIMARY KEY ("alert_id","event_id"),
    CONSTRAINT "uq_security_alert_events_event" UNIQUE ("event_id"),
    CONSTRAINT "fk_security_alert_events_alert" FOREIGN KEY ("alert_id") REFERENCES "security_alerts" ("id") ON DELETE CASCADE,
    CONSTRAINT "fk_security_alert_events_event" FOREIGN KEY ("event_id") REFERENCES "security_events" ("id") ON DELETE RESTRICT
);

INSERT INTO "security_alert_events"("alert_id","event_id","linked_at")
SELECT a."id",a."source_event_id",COALESCE(a."last_event_at",a."opened_at")
FROM "security_alerts" a
ON CONFLICT("event_id") DO NOTHING;

UPDATE "security_alerts" a
SET "event_count"=(SELECT COUNT(*) FROM "security_alert_events" ae WHERE ae."alert_id"=a."id"),
    "last_event_at"=COALESCE(a."last_event_at",a."opened_at");

CREATE INDEX IF NOT EXISTS "idx_security_events_time_id"
ON "security_events" ("occurred_at" DESC,"id" DESC);
CREATE INDEX IF NOT EXISTS "idx_security_events_resource_time"
ON "security_events" ("resource_type","occurred_at" DESC,"id" DESC);
CREATE INDEX IF NOT EXISTS "idx_security_events_result_time"
ON "security_events" ("result","occurred_at" DESC,"id" DESC);
CREATE INDEX IF NOT EXISTS "idx_auth_login_events_time_id"
ON "auth_login_events" ("created_at" DESC,"id" DESC);
CREATE INDEX IF NOT EXISTS "idx_sessions_activity_user"
ON "sessions" ("last_activity" DESC,"user_id");

CREATE TABLE IF NOT EXISTS "security_event_archive_batches" (
    "id" bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    "public_id" varchar(36) NOT NULL UNIQUE,
    "cutoff_at" timestamp without time zone NOT NULL,
    "archived_by" bigint NOT NULL,
    "authorization_public_id" varchar(36) NOT NULL,
    "security_event_count" integer NOT NULL DEFAULT 0,
    "login_event_count" integer NOT NULL DEFAULT 0,
    "created_at" timestamp without time zone NOT NULL DEFAULT NOW(),
    "completed_at" timestamp without time zone NULL,
    CONSTRAINT "fk_security_event_archive_batches_actor" FOREIGN KEY ("archived_by") REFERENCES "users" ("id") ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS "security_events_archive" (
    "archive_id" bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    "original_id" bigint NOT NULL,
    "event_id" varchar(36) NOT NULL UNIQUE,
    "occurred_at" timestamp without time zone NOT NULL,
    "actor_id" bigint NULL,
    "action" varchar(120) NOT NULL,
    "resource_type" varchar(80) NULL,
    "resource_id" varchar(128) NULL,
    "request_id" varchar(64) NULL,
    "correlation_id" varchar(128) NULL,
    "instance_id" varchar(80) NULL,
    "ip" varchar(64) NULL,
    "user_agent" text NULL,
    "authentication_level" varchar(32) NOT NULL,
    "credential_public_id" varchar(36) NULL,
    "before_state" jsonb NULL,
    "after_state" jsonb NULL,
    "result" varchar(24) NOT NULL,
    "reason" text NULL,
    "risk_level" varchar(16) NOT NULL,
    "metadata" jsonb NOT NULL,
    "archive_batch_id" bigint NOT NULL,
    "archived_at" timestamp without time zone NOT NULL DEFAULT NOW(),
    CONSTRAINT "fk_security_events_archive_batch" FOREIGN KEY ("archive_batch_id") REFERENCES "security_event_archive_batches" ("id") ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS "idx_security_events_archive_time_id"
ON "security_events_archive" ("occurred_at" DESC,"archive_id" DESC);
CREATE INDEX IF NOT EXISTS "idx_security_events_archive_actor_time"
ON "security_events_archive" ("actor_id","occurred_at" DESC);
CREATE INDEX IF NOT EXISTS "idx_security_events_archive_action_time"
ON "security_events_archive" ("action","occurred_at" DESC);

CREATE TABLE IF NOT EXISTS "auth_login_events_archive" (
    "archive_id" bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    "original_id" bigint NOT NULL,
    "user_id" bigint NULL,
    "email" varchar(255) NULL,
    "result" varchar(32) NOT NULL,
    "ip_hash" varchar(128) NULL,
    "user_agent_hash" varchar(128) NULL,
    "created_at" timestamp without time zone NOT NULL,
    "archive_batch_id" bigint NOT NULL,
    "archived_at" timestamp without time zone NOT NULL DEFAULT NOW(),
    CONSTRAINT "uq_auth_login_events_archive_original" UNIQUE ("original_id"),
    CONSTRAINT "fk_auth_login_events_archive_batch" FOREIGN KEY ("archive_batch_id") REFERENCES "security_event_archive_batches" ("id") ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS "idx_auth_login_events_archive_time_id"
ON "auth_login_events_archive" ("created_at" DESC,"archive_id" DESC);

CREATE OR REPLACE FUNCTION protect_security_event_archive()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '3DORS archive rows are immutable';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS "trg_security_events_archive_immutable" ON "security_events_archive";
CREATE TRIGGER "trg_security_events_archive_immutable"
BEFORE UPDATE OR DELETE ON "security_events_archive"
FOR EACH ROW EXECUTE FUNCTION protect_security_event_archive();

DROP TRIGGER IF EXISTS "trg_auth_login_events_archive_immutable" ON "auth_login_events_archive";
CREATE TRIGGER "trg_auth_login_events_archive_immutable"
BEFORE UPDATE OR DELETE ON "auth_login_events_archive"
FOR EACH ROW EXECUTE FUNCTION protect_security_event_archive();
