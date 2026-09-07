-- 0023_add_support_and_faq — in-dashboard customer support contact +
-- operator inbox, plus an operator-maintained FAQ used both to deflect
-- support requests (category-matched suggestions shown before the customer
-- submits) and as a standalone help surface.
--
-- Categories (support_requests.category AND faq_entries.category) are ONE
-- shared enum — see Votepit\Support\SupportCategory (SQL CHECK below is a
-- literal mirror of SupportCategory::ALL; keep both in sync by hand, SQL
-- can't reference PHP constants).
--
-- support_requests: unlike abuse_reports (0013), this is reachable ONLY
-- from within an authenticated account dashboard (AuthZMiddleware::
-- accountAdmin() — every account_members role is owner|moderator, so this
-- is effectively "any team member"), never anonymously — account_id/user_id
-- are therefore NOT NULL and CASCADE-deleted with their account/user,
-- mirroring invites (0009) rather than abuse_reports' nullable/SET NULL
-- pattern.
--
-- contact_email_enc: OPTIONAL reply-to address, encrypted at rest
-- (Votepit\Security\EncryptionService, dedicated HKDF context
-- 'support_request' — see AppFactory). Needed because, per ADR 0002, a
-- user's real email is never persisted anywhere in this codebase (only
-- email_hmac) — without this field the operator would have no way to reply
-- by email to a ticket. Same rationale as abuse_reports.reporter_email_enc.
--
-- operator_reply/replied_by/replied_at: the one reply a ticket can carry
-- in-app (mirrors abuse_reports' reviewed_by/reviewed_at shape). A richer
-- threaded conversation is out of scope for this first cut.
CREATE TABLE IF NOT EXISTS support_requests (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id         BIGINT UNSIGNED NOT NULL,
    user_id            BIGINT UNSIGNED NOT NULL,
    category           VARCHAR(32)     NOT NULL,
    subject            VARCHAR(200)    NOT NULL,
    message            TEXT            NOT NULL,
    contact_email_enc  TEXT            NULL,
    status             VARCHAR(16)     NOT NULL DEFAULT 'open',
    operator_reply     TEXT            NULL,
    replied_by         BIGINT UNSIGNED NULL,
    replied_at         DATETIME        NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_support_requests_status (status, created_at),
    KEY idx_support_requests_account (account_id, created_at),
    CONSTRAINT fk_support_requests_account    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_requests_user       FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_support_requests_replied_by FOREIGN KEY (replied_by) REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT chk_support_requests_status    CHECK (status IN ('open', 'answered', 'closed')),
    CONSTRAINT chk_support_requests_category  CHECK (category IN ('billing', 'technical', 'account', 'feature_request', 'privacy', 'other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- faq_entries: platform-wide (NOT account-scoped — one shared knowledge
-- base across every tenant, operator-maintained like the legal footer
-- links). question/answer are stored per-language rather than pointing at
-- an i18n dictionary key, because FAQ content is operator-authored data,
-- not developer-authored UI copy (see FaqRepository/FaqAction) —
-- consistent with how board branding's `intro` field is free-form
-- plaintext, not a dictionary entry. is_published gates visibility on every
-- read surface (contact-form deflection AND a standalone FAQ view);
-- unpublished rows stay visible to the operator only (draft workflow).
CREATE TABLE IF NOT EXISTS faq_entries (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category      VARCHAR(32)     NOT NULL,
    question_de   TEXT            NOT NULL,
    question_en   TEXT            NOT NULL,
    answer_de     TEXT            NOT NULL,
    answer_en     TEXT            NOT NULL,
    sort_order    INT             NOT NULL DEFAULT 0,
    is_published  TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_faq_entries_published (is_published, category, sort_order),
    CONSTRAINT chk_faq_entries_category CHECK (category IN ('billing', 'technical', 'account', 'feature_request', 'privacy', 'other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
