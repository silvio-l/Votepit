-- 0112_add_marketing_consent — proof-carrying consent for product-news and
-- promotion mails (.scratch/close-the-loop/marketing-consent.md, W1).
--
-- Votepit may only send advertising (UWG § 7) to a user who gave a separate,
-- optional, double-opt-in consent, or who is an existing paying customer that
-- saw the § 7 Abs. 3 UWG objection notice at signup. This migration stores the
-- evidence for both:
--
--   users.existing_customer_notice_at  — when the § 7 Abs. 3 notice was shown
--                                        at signup (NULL = never shown). Set only
--                                        when the signup magic link is REDEEMED:
--                                        an unredeemed request proves nothing
--                                        about the address owner.
--   users.marketing_email              — the plaintext address marketing mail
--                                        goes to. Set only when the address owner
--                                        has proven control of it: at redeem of
--                                        the signup magic link, or at the
--                                        confirmation of a profile opt-in (the
--                                        confirmed notification e-mail). Cleared
--                                        on withdrawal. ADR 0002 Amendment: this
--                                        is a second deliberate plaintext address
--                                        next to notification_email, with its own
--                                        erasure path (users row cascade).
--   login_tokens.confirms_product_news — the magic link of a signup that ticked
--                                        the product-news checkbox also confirms
--                                        that consent when it is redeemed (double
--                                        opt-in riding on the login mail).
--   login_tokens.signup_marketing_email — the address of a signup magic link
--                                        (marketing enabled, r=/signup/account),
--                                        held only until that link is redeemed:
--                                        redeem copies it into the users row and
--                                        clears it with the used token; expired
--                                        open links are cleared on the next magic
--                                        link request, and deleting an open token
--                                        removes it with the row.
--   marketing_consents                 — one record per user: status
--                                        pending|confirmed|withdrawn, source
--                                        signup|profile, text_version (code
--                                        constant), requested/confirmed/withdrawn
--                                        timestamps. The pending profile opt-in
--                                        keeps its single-use confirm token hash
--                                        here (hash only, never the plaintext).
--
-- No IP address is stored (data minimisation). A withdrawn row is kept as the
-- proof of objection; its marketing_email is cleared at the same time.
ALTER TABLE users ADD COLUMN existing_customer_notice_at DATETIME NULL;
ALTER TABLE users ADD COLUMN marketing_email VARCHAR(254) NULL;
ALTER TABLE login_tokens ADD COLUMN confirms_product_news TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE login_tokens ADD COLUMN signup_marketing_email VARCHAR(254) NULL;

CREATE TABLE IF NOT EXISTS marketing_consents (
    user_id            BIGINT UNSIGNED NOT NULL,
    status             VARCHAR(16)     NOT NULL,
    source             VARCHAR(16)     NOT NULL,
    text_version       VARCHAR(32)     NOT NULL,
    confirm_token_hash CHAR(64)        NULL,
    confirm_expires_at DATETIME        NULL,
    requested_at       DATETIME        NULL,
    confirmed_at       DATETIME        NULL,
    withdrawn_at       DATETIME        NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_marketing_consents_token_hash (confirm_token_hash),
    CONSTRAINT chk_marketing_consents_status CHECK (status IN ('pending', 'confirmed', 'withdrawn')),
    CONSTRAINT chk_marketing_consents_source CHECK (source IN ('signup', 'profile')),
    CONSTRAINT fk_marketing_consents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
