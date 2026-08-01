CREATE TABLE IF NOT EXISTS article_translation_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    translation_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    `lead` TEXT NULL,
    body LONGTEXT NOT NULL,
    seo_title VARCHAR(255) NULL,
    seo_description TEXT NULL,
    seo_keywords TEXT NULL,
    slug VARCHAR(255) NULL,
    status VARCHAR(50) NOT NULL,
    translation_instructions LONGTEXT NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_article_translation_version (translation_id, version_no),
    KEY idx_article_translation_versions_created (translation_id, created_at),
    CONSTRAINT fk_article_translation_versions_translation
        FOREIGN KEY (translation_id) REFERENCES article_translations(id) ON DELETE CASCADE,
    CONSTRAINT fk_article_translation_versions_user
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO article_translation_versions (
    translation_id, version_no, title, `lead`, body, seo_title, seo_description,
    seo_keywords, slug, status, translation_instructions, changed_by, created_at
)
SELECT
    t.id, 1, t.title, t.`lead`, t.body, t.seo_title, t.seo_description,
    t.seo_keywords, t.slug, t.status, t.translation_instructions,
    COALESCE(t.updated_by, t.created_by), COALESCE(t.updated_at, t.created_at, NOW())
FROM article_translations t
WHERE NOT EXISTS (
    SELECT 1 FROM article_translation_versions v WHERE v.translation_id=t.id
);
