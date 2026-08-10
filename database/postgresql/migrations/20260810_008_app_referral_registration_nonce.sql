ALTER TABLE "app_referral_invitations"
    ADD COLUMN IF NOT EXISTS "registration_nonce_hash" char(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "registration_nonce_expires_at" timestamp without time zone DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "registration_nonce_used_at" timestamp without time zone DEFAULT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS "uq_app_referral_registration_nonce"
ON "app_referral_invitations" ("registration_nonce_hash")
WHERE "registration_nonce_hash" IS NOT NULL;
