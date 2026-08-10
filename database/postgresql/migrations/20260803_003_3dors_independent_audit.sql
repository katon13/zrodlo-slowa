ALTER TABLE "security_mobile_enrollments"
    ADD COLUMN IF NOT EXISTS "device_completed_at" timestamp without time zone NULL,
    ADD COLUMN IF NOT EXISTS "panel_confirmed_by" bigint NULL;

UPDATE "security_mobile_enrollments"
SET "device_completed_at" = "used_at"
WHERE "device_completed_at" IS NULL AND "used_at" IS NOT NULL;

ALTER TABLE "security_mobile_enrollments"
    DROP CONSTRAINT IF EXISTS "fk_security_mobile_enrollments_panel_confirmed_by";
ALTER TABLE "security_mobile_enrollments"
    ADD CONSTRAINT "fk_security_mobile_enrollments_panel_confirmed_by"
    FOREIGN KEY ("panel_confirmed_by") REFERENCES "users" ("id") ON DELETE SET NULL;

ALTER TABLE "security_mobile_credentials"
    ADD COLUMN IF NOT EXISTS "attestation_verified" smallint NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS "attestation_verified_at" timestamp without time zone NULL;

ALTER TABLE "security_mobile_credentials"
    DROP CONSTRAINT IF EXISTS "security_mobile_credentials_attestation_verified";
ALTER TABLE "security_mobile_credentials"
    ADD CONSTRAINT "security_mobile_credentials_attestation_verified"
    CHECK ("attestation_verified" IN (0, 1));

-- legacy-v1 is a transitional system entitlement, not proof that a legal
-- document was signed. Elapsed rows must not block a new active entitlement.
UPDATE "author_agreements"
SET "status" = 'expired', "updated_at" = NOW()
WHERE "status" = 'active' AND "valid_until" IS NOT NULL AND "valid_until" <= NOW();

CREATE OR REPLACE FUNCTION "dors3_expire_prior_author_agreements"()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW."status" <> 'active' THEN
        RETURN NEW;
    END IF;

    IF NEW."valid_until" IS NOT NULL AND NEW."valid_until" <= NOW() THEN
        NEW."status" := 'expired';
        NEW."updated_at" := NOW();
        RETURN NEW;
    END IF;

    UPDATE "author_agreements"
    SET "status" = 'expired', "updated_at" = NOW()
    WHERE "user_id" = NEW."user_id"
      AND "organization_id" = NEW."organization_id"
      AND "status" = 'active'
      AND "valid_until" IS NOT NULL
      AND "valid_until" <= NOW()
      AND (TG_OP = 'INSERT' OR "id" <> NEW."id");

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS "trg_dors3_expire_prior_author_agreements" ON "author_agreements";
CREATE TRIGGER "trg_dors3_expire_prior_author_agreements"
BEFORE INSERT OR UPDATE OF "status", "valid_until", "user_id", "organization_id"
ON "author_agreements"
FOR EACH ROW
EXECUTE FUNCTION "dors3_expire_prior_author_agreements"();
