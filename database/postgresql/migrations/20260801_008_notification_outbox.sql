ALTER TABLE "activity_bonus_notifications"
    ADD COLUMN IF NOT EXISTS "source_event_key" varchar(190) DEFAULT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS "uq_activity_bonus_notifications_source_event_key"
    ON "activity_bonus_notifications" ("source_event_key")
    WHERE "source_event_key" IS NOT NULL;
