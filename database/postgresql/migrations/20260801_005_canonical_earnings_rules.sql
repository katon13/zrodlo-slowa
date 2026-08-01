INSERT INTO "activity_reward_rules" (
    "activity_type",
    "points_amount",
    "amount_minor",
    "label",
    "live_message_template",
    "title_key",
    "message_key",
    "description_key",
    "daily_limit",
    "is_active",
    "created_at",
    "updated_at"
)
VALUES
    ('registration_bonus', 0, 0, NULL, NULL, 'bonus.type.registration', 'bonus.message.registration', 'bonus.description.registration', 0, 0, NOW(), NOW()),
    ('day_visit_bonus', 0, 0, NULL, NULL, 'bonus.type.day_visit', 'bonus.message.day_visit', 'bonus.description.day_visit', 0, 0, NOW(), NOW()),
    ('login_bonus', 0, 0, NULL, NULL, 'bonus.type.login', 'bonus.message.login', 'bonus.description.login', 0, 0, NOW(), NOW()),
    ('article_read_bonus', 0, 0, NULL, NULL, 'bonus.type.article_read', 'bonus.message.article_read', 'bonus.description.article_read', 0, 0, NOW(), NOW()),
    ('comment_bonus', 0, 0, NULL, NULL, 'bonus.type.comment', 'bonus.message.comment', 'bonus.description.comment', 0, 0, NOW(), NOW()),
    ('share_bonus', 0, 0, NULL, NULL, 'bonus.type.share', 'bonus.message.share', 'bonus.description.share', 0, 0, NOW(), NOW()),
    ('link_click_bonus', 0, 0, NULL, NULL, 'bonus.type.link_click', 'bonus.message.link_click', 'bonus.description.link_click', 0, 0, NOW(), NOW()),
    ('like_bonus', 0, 0, NULL, NULL, 'bonus.type.like', 'bonus.message.like', 'bonus.description.like', 0, 0, NOW(), NOW()),
    ('bug_report_bonus', 0, 0, NULL, NULL, 'bonus.type.bug_report', 'bonus.message.bug_report', 'bonus.description.bug_report', 0, 0, NOW(), NOW()),
    ('survey_reward', 0, 0, NULL, NULL, 'bonus.type.survey_reward', 'bonus.message.survey_reward', 'bonus.description.survey_reward', 0, 0, NOW(), NOW()),
    ('sponsored_article_read_bonus', 0, 0, NULL, NULL, 'bonus.type.sponsored_article_read', 'bonus.message.sponsored_article_read', 'bonus.description.sponsored_article_read', 0, 0, NOW(), NOW()),
    ('ad_view_reward', 0, 0, NULL, NULL, 'bonus.type.ad_view_reward', 'bonus.message.ad_view_reward', 'bonus.description.ad_view_reward', 0, 0, NOW(), NOW()),
    ('ad_click_reward', 0, 0, NULL, NULL, 'bonus.type.ad_click_reward', 'bonus.message.ad_click_reward', 'bonus.description.ad_click_reward', 0, 0, NOW(), NOW()),
    ('newsletter_open_reward', 0, 0, NULL, NULL, 'bonus.type.newsletter_open_reward', 'bonus.message.newsletter_open_reward', 'bonus.description.newsletter_open_reward', 0, 0, NOW(), NOW()),
    ('ppv_reward', 0, 0, NULL, NULL, 'bonus.type.ppv_reward', 'bonus.message.ppv_reward', 'bonus.description.ppv_reward', 0, 0, NOW(), NOW()),
    ('live_event_reward', 0, 0, NULL, NULL, 'bonus.type.live_event_reward', 'bonus.message.live_event_reward', 'bonus.description.live_event_reward', 0, 0, NOW(), NOW())
ON CONFLICT ("activity_type") DO NOTHING;
