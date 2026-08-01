CREATE INDEX IF NOT EXISTS "idx_sessions_user"
ON "sessions" ("user_id");

CREATE INDEX IF NOT EXISTS "idx_sessions_last_activity"
ON "sessions" ("last_activity");

DO $migration$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_sessions_user'
    ) THEN
        ALTER TABLE "sessions"
        ADD CONSTRAINT "fk_sessions_user"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE;
    END IF;
END
$migration$;
