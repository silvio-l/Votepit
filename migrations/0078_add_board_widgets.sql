-- 0078_add_board_widgets — embeddable board widget (ADR 0005, issue 20).
-- One widget configuration per board (board_id UNIQUE):
--   allowed_origins  : JSON array of at most 10 exact `https://host[:port]`
--                      origins (WidgetOriginValidator). ONLY the widget route
--                      relaxes `frame-ancestors` to this list; an empty list
--                      disables the widget (route 404, fail-secure).
--   secret_encrypted : per-board HS256 secret the customer's backend signs
--                      identify JWTs with. Encrypted at rest (EncryptionService,
--                      own key-separation context 'board_widget') because,
--                      unlike an API token, it must be readable again to
--                      verify signatures. NULL until first generated.
--   secret_rotated_at: when the secret was last (re)generated — also part of
--                      the widget-token fingerprint, so rotating revokes every
--                      outstanding widget token.
--   last_seen_origin / last_seen_at: install detection for the owner's
--                      settings page (an allowed origin, never a full URL).
-- No PII: identified widget users are resolved via users.email_hmac only.
-- A plan downgrade keeps this row untouched; the widget route checks
-- PlanPolicy::widgetAllowed() per request instead.
CREATE TABLE IF NOT EXISTS board_widgets (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    board_id          BIGINT UNSIGNED NOT NULL,
    allowed_origins   TEXT NOT NULL,
    secret_encrypted  VARCHAR(512) NULL,
    secret_rotated_at DATETIME NULL,
    last_seen_origin  VARCHAR(255) NULL,
    last_seen_at      DATETIME NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_board_widgets_board (board_id),
    CONSTRAINT fk_board_widgets_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
