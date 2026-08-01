ALTER TABLE "users"
    ADD COLUMN IF NOT EXISTS "login_name" varchar(64) NULL;

CREATE UNIQUE INDEX IF NOT EXISTS "uq_users_login_name"
    ON "users" (LOWER("login_name"))
    WHERE "login_name" IS NOT NULL;
