-- 0054_create_content_translations — cache table for on-demand machine
-- translation of idea/comment content (idea-comment-translation feature).
-- One generic table for both entity types rather than separate idea/comment
-- tables — both are "translate a piece of text tied to an owning row", the
-- only structural difference being that ideas also have a title
-- (translated_title stays NULL for comments).
--
-- source_hash is a SHA-256 over the original text(s) actually translated
-- (title+body for ideas, body for comments) at request time — a lookup
-- whose stored hash does not match the current hash is a cache miss (the
-- author edited the content since the last translation), and the caller
-- overwrites the row via UPSERT (INSERT ... ON DUPLICATE KEY UPDATE) on
-- the UNIQUE KEY below rather than a separate invalidation job/TTL.
--
-- detected_source_locale is the provider-detected source language of the
-- input text (Google auto-detects it as part of the same translate call —
-- see Votepit\Translation\TranslationProvider) — lets the frontend skip
-- presenting content as "translated" when it was already in the viewer's
-- target locale.
CREATE TABLE IF NOT EXISTS content_translations (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type             ENUM('idea', 'comment') NOT NULL,
    entity_id               BIGINT UNSIGNED NOT NULL,
    target_locale           VARCHAR(10) NOT NULL,
    source_hash             CHAR(64) NOT NULL,
    detected_source_locale  VARCHAR(10) NOT NULL,
    translated_title        VARCHAR(500) NULL,
    translated_body         TEXT NOT NULL,
    provider                VARCHAR(32) NOT NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_content_translations_entity_locale (entity_type, entity_id, target_locale)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
