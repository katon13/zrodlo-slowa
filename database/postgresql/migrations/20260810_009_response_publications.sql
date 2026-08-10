ALTER TABLE "articles"
    ADD COLUMN IF NOT EXISTS "response_to_article_id" bigint DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_reward_qualified" boolean DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_reward_points" integer DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_reward_job_public_id" varchar(36) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS "response_reward_queued_at" timestamp without time zone DEFAULT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_articles_response_to_article'
    ) THEN
        ALTER TABLE "articles"
            ADD CONSTRAINT "fk_articles_response_to_article"
            FOREIGN KEY ("response_to_article_id") REFERENCES "articles" ("id") ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_articles_response_reward_job'
    ) THEN
        ALTER TABLE "articles"
            ADD CONSTRAINT "fk_articles_response_reward_job"
            FOREIGN KEY ("response_reward_job_public_id") REFERENCES "background_jobs" ("public_id") ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'articles_response_reward_points'
    ) THEN
        ALTER TABLE "articles"
            ADD CONSTRAINT "articles_response_reward_points"
            CHECK ("response_reward_points" IS NULL OR "response_reward_points" BETWEEN 0 AND 1000000);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'articles_response_reward_snapshot'
    ) THEN
        ALTER TABLE "articles"
            ADD CONSTRAINT "articles_response_reward_snapshot"
            CHECK (
                "response_reward_qualified" IS NULL
                OR (
                    "response_to_article_id" IS NOT NULL
                    AND "response_reward_points" IS NOT NULL
                    AND "response_reward_job_public_id" IS NOT NULL
                    AND "response_reward_queued_at" IS NOT NULL
                    AND (
                        ("response_reward_qualified" = TRUE AND "response_reward_points" > 0)
                        OR ("response_reward_qualified" = FALSE AND "response_reward_points" = 0)
                    )
                )
            );
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS "idx_articles_response_publications"
ON "articles" ("response_to_article_id", "status", "published_at" DESC, "id" DESC);

CREATE INDEX IF NOT EXISTS "idx_articles_response_reward_job"
ON "articles" ("response_reward_job_public_id")
WHERE "response_reward_job_public_id" IS NOT NULL;

DELETE FROM "activity_reward_rules" WHERE "activity_type" = 'comment_bonus';

INSERT INTO "activity_reward_rules" (
    "activity_type","points_amount","amount_minor","label","live_message_template",
    "title_key","message_key","description_key","daily_limit","is_active","created_at","updated_at"
) VALUES (
    'response_publication_bonus',0,0,'Opublikowana odpowiedź publikacją','Talent za opublikowaną opinię lub polemikę',
    'bonus.type.response_publication','bonus.message.response_publication','bonus.description.response_publication',0,0,NOW(),NOW()
) ON CONFLICT ("activity_type") DO UPDATE SET
    "amount_minor"=0,
    "daily_limit"=0,
    "title_key"=EXCLUDED."title_key",
    "message_key"=EXCLUDED."message_key",
    "description_key"=EXCLUDED."description_key",
    "updated_at"=NOW();
