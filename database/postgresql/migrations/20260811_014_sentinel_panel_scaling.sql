-- 3DORS Wartownik: bounded server-side lists for alerts, sessions and login groups.

CREATE INDEX IF NOT EXISTS "idx_security_alerts_status_severity_changed"
ON "security_alerts" ("status","severity","status_changed_at" DESC,"id" DESC);

CREATE INDEX IF NOT EXISTS "idx_security_alerts_status_last_event"
ON "security_alerts" ("status","last_event_at" DESC,"id" DESC);

CREATE INDEX IF NOT EXISTS "idx_auth_login_events_sentinel_page"
ON "auth_login_events" ("created_at" DESC,"result","user_id","id" DESC);

CREATE INDEX IF NOT EXISTS "idx_security_events_action_actor_time"
ON "security_events" ("action","actor_id","occurred_at" DESC,"id" DESC);

CREATE INDEX IF NOT EXISTS "idx_users_lower_display_name_pattern"
ON "users" ((LOWER("display_name")) text_pattern_ops);

CREATE INDEX IF NOT EXISTS "idx_users_lower_email_pattern"
ON "users" ((LOWER("email")) text_pattern_ops);
