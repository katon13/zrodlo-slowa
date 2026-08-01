CREATE INDEX IF NOT EXISTS "idx_activity_bonus_notifications_user_seen_id"
    ON "activity_bonus_notifications" ("user_id", "seen_at", "id");
