-- 0057_extend_content_translations_entity_types — widens
-- content_translations.entity_type (0054_create_content_translations.sql)
-- from the original idea/comment pair to also cover the three other
-- user-generated-content surfaces that gained on-demand translation in
-- this follow-up: a board's intro text, an in-app notification's
-- title+body (also covers operator broadcast announcements — they are
-- physically 'notifications' rows with scope='broadcast', see
-- NotificationRepository), and a support-ticket message body.
--
-- TranslationRepository itself needed no code change for this — it was
-- already generic over entity_type/entity_id; only the storage-level
-- constraint needed widening.
ALTER TABLE content_translations
    MODIFY COLUMN entity_type ENUM('idea', 'comment', 'board_intro', 'notification', 'support_message') NOT NULL;
