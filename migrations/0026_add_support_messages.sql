-- 0026_add_support_messages — turns support_requests from a "one message +
-- one operator reply" contact form into a lightweight threaded ticket:
-- support_requests stays the ticket header (account/user, category,
-- subject, status), support_messages carries an ordered back-and-forth
-- between the account side and the operator side, both able to post
-- multiple messages to the same ticket over time (see SupportRequestAction
-- class doc for the full read/write surface).
--
-- author_type discriminates the two sides that can write to a ticket —
-- 'customer' (any member of the owning account, matching the existing
-- accountAdmin AuthZ on the ticket itself) or 'operator' (a platform
-- operator). author_user_id is always the real acting user (never NULL —
-- every message has a human author), FK'd to users like support_requests.
-- user_id already was.
--
-- Backfills every existing ticket's original message and, where present,
-- its single operator reply into this table before support_requests loses
-- those columns — preserves ticket history for any ticket already created
-- (staging and, if any exist, production) rather than silently dropping it.
CREATE TABLE IF NOT EXISTS support_messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id      BIGINT UNSIGNED NOT NULL,
    author_type     VARCHAR(16)     NOT NULL,
    author_user_id  BIGINT UNSIGNED NOT NULL,
    body            TEXT            NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_support_messages_request (request_id, created_at),
    CONSTRAINT fk_support_messages_request     FOREIGN KEY (request_id)     REFERENCES support_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_messages_author_user FOREIGN KEY (author_user_id) REFERENCES users(id)            ON DELETE CASCADE,
    CONSTRAINT chk_support_messages_author_type CHECK (author_type IN ('customer', 'operator'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO support_messages (request_id, author_type, author_user_id, body, created_at)
SELECT id, 'customer', user_id, message, created_at
FROM support_requests;

INSERT INTO support_messages (request_id, author_type, author_user_id, body, created_at)
SELECT id, 'operator', replied_by, operator_reply, replied_at
FROM support_requests
WHERE operator_reply IS NOT NULL AND replied_by IS NOT NULL;

ALTER TABLE support_requests
    DROP FOREIGN KEY fk_support_requests_replied_by,
    DROP COLUMN message,
    DROP COLUMN operator_reply,
    DROP COLUMN replied_by,
    DROP COLUMN replied_at;
