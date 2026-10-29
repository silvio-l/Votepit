-- 0079_add_board_chat_integrations — per-board Slack/Discord chat
-- notifications (competitor-parity issue 18). At most one incoming-webhook
-- URL per (board, provider). The URL itself embeds the webhook's bearer
-- token, so it is stored ENCRYPTED at rest (EncryptionService, own key-
-- separation context 'board_chat_webhook') and never returned to the
-- client after saving — url_hint is the non-secret display form
-- ("hooks.slack.com/…a1b2").
--
-- last_delivery_* is a single-row "did the last message arrive?" status for
-- the admin UI (no delivery log table, no message bodies, no PII).
CREATE TABLE IF NOT EXISTS board_chat_integrations (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    board_id             BIGINT UNSIGNED NOT NULL,
    provider             VARCHAR(16) NOT NULL,
    url_encrypted        VARCHAR(1024) NOT NULL,
    url_hint             VARCHAR(96) NOT NULL,
    last_delivery_at     TIMESTAMP NULL DEFAULT NULL,
    last_delivery_ok     TINYINT(1) NULL DEFAULT NULL,
    last_delivery_error  VARCHAR(64) NULL DEFAULT NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_board_chat_integrations_board_provider (board_id, provider),
    CONSTRAINT fk_board_chat_integrations_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
