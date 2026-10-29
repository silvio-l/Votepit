-- 0053_add_board_webhooks — per-board outbound webhooks (board-webhooks
-- feature). One webhook target per board (board_id UNIQUE — a board's
-- owner/admin configures exactly one URL, no fan-out list). The signing
-- secret is stored encrypted at rest (EncryptionService, its own key-
-- separation context 'board_webhook' — never the 'smtp' context), because
-- unlike an API token (hash-compared, TokenVault) the secret must be
-- readable again at delivery time to compute the outgoing HMAC signature.
--
-- board_webhook_deliveries is a short delivery log (event, outcome, status
-- code) for troubleshooting — not an audit/compliance table, no PII: it
-- never stores response bodies or recipient data, only what's needed to see
-- "did the last few deliveries succeed".
CREATE TABLE IF NOT EXISTS board_webhooks (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    board_id          BIGINT UNSIGNED NOT NULL,
    url               VARCHAR(2048) NOT NULL,
    secret_encrypted  VARCHAR(512) NOT NULL,
    active            TINYINT(1) NOT NULL DEFAULT 1,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_board_webhooks_board (board_id),
    CONSTRAINT fk_board_webhooks_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_webhook_deliveries (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    board_webhook_id  BIGINT UNSIGNED NOT NULL,
    event             VARCHAR(64) NOT NULL,
    success           TINYINT(1) NOT NULL,
    status_code       SMALLINT UNSIGNED NULL,
    error             VARCHAR(255) NULL,
    attempted_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_board_webhook_deliveries_webhook FOREIGN KEY (board_webhook_id) REFERENCES board_webhooks (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE INDEX idx_board_webhook_deliveries_webhook ON board_webhook_deliveries (board_webhook_id, attempted_at);
