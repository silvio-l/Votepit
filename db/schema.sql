-- Votepit — Datenbank-Schema (idempotent)
--
-- Ziel: MySQL 8.0+ / MariaDB 10.2+ mit InnoDB-FULLTEXT.
-- Mehrfaches Ausführen erzeugt keine Fehler (CREATE TABLE IF NOT EXISTS).
-- FULLTEXT und UNIQUE sind Teil der CREATE TABLE → bei Neuanlage automatisch gesetzt.
--
-- Konventionen:
--   - utf8mb4 / utf8mb4_unicode_ci überall.
--   - BIGINT UNSIGNED PKs.
--   - Fremdschlüssel mit explizitem ON DELETE-Verhalten.
--   - Board-Scoping via board_id auf ideas; votes/comments erben via idea_id.
--   - Stimmen-Integrität: UNIQUE(idea_id, user_id) als DB-Backstop.
--
-- Auf alten Shared-Hosting-Versionen ohne InnoDB-FULLTEXT schlägt der
-- FULLTEXT-Index fehl → dann ohne FULLTEXT anlegen und PHP-Fallback
-- (Sprint 5) nutzen. Siehe prd.md "Duplikat-Erkennung".

SET NAMES utf8mb4;

-- boards: ein Board pro Projekt (z. B. /mobile-app, /website, /api …)
CREATE TABLE IF NOT EXISTS boards (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(64)     NOT NULL,
    name            VARCHAR(128)    NOT NULL,
    accent_color    VARCHAR(16)     NOT NULL DEFAULT '#3b82f6',
    -- Per-Board-Branding (Issue 08): überschreiben die Marken-Tokens (Issue 07)
    -- zur Laufzeit. NULLable + additiv → bestehende Spalten unberührt. Hex-Werte
    -- (#rrggbb / #rgb) werden VOR Speicherung + VOR CSS-Ausgabe streng validiert.
    primary_color   VARCHAR(7)      NULL,
    secondary_color VARCHAR(7)      NULL,
    logo_url        VARCHAR(512)    NULL,
    intro           TEXT            NULL,
    is_default      TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_boards_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users: Identität = verifizierte E-Mail (passwortlos). Keine Passwort-Hashes.
CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    email         VARCHAR(254)      NOT NULL,
    is_admin      TINYINT(1)        NOT NULL DEFAULT 0,
    is_blocked    TINYINT(1)        NOT NULL DEFAULT 0,
    token_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    verified_at   DATETIME          NULL,
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- login_tokens: Magic-Link-Tokens. NUR Hash (sha256 hex, 64 Zeichen), Einmal + befristet.
CREATE TABLE IF NOT EXISTS login_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    purpose     VARCHAR(32)     NOT NULL DEFAULT 'login',
    expires_at  DATETIME        NOT NULL,
    used_at     DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_tokens_hash (token_hash),
    KEY idx_login_tokens_user (user_id),
    CONSTRAINT fk_login_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ideas: Kerntabelle. FULLTEXT auf title für Duplikat-Recall (Sprint 5).
CREATE TABLE IF NOT EXISTS ideas (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_id         BIGINT UNSIGNED NOT NULL,
    author_id        BIGINT UNSIGNED NOT NULL,
    title            VARCHAR(200)    NOT NULL,
    title_normalized VARCHAR(200)    NOT NULL DEFAULT '',
    body             TEXT            NOT NULL,
    status           VARCHAR(16)     NOT NULL DEFAULT 'open',
    is_pinned        TINYINT(1)      NOT NULL DEFAULT 0,
    merged_into_id   BIGINT UNSIGNED NULL,
    score_cache      INT             NOT NULL DEFAULT 0,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ideas_board_status (board_id, status),
    KEY idx_ideas_board_score  (board_id, score_cache),
    KEY idx_ideas_board_created(board_id, created_at),
    FULLTEXT KEY ft_ideas_title (title),
    CONSTRAINT fk_ideas_board  FOREIGN KEY (board_id)       REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_ideas_author FOREIGN KEY (author_id)      REFERENCES users(id)  ON DELETE RESTRICT,
    CONSTRAINT fk_ideas_merged FOREIGN KEY (merged_into_id) REFERENCES ideas(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- votes: eine Stimme pro Idee pro Nutzer (UNIQUE = DB-Backstop).
CREATE TABLE IF NOT EXISTS votes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idea_id    BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    value      SMALLINT        NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_votes_idea_user (idea_id, user_id),
    CONSTRAINT fk_votes_idea FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_user FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT chk_votes_value CHECK (value IN (-1, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- comments: Diskussion zu einer Idee.
CREATE TABLE IF NOT EXISTS comments (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idea_id    BIGINT UNSIGNED NOT NULL,
    author_id  BIGINT UNSIGNED NOT NULL,
    body       TEXT            NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_comments_idea (idea_id, created_at),
    CONSTRAINT fk_comments_idea   FOREIGN KEY (idea_id)   REFERENCES ideas(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rate_limits: serverseitiges Throttling. bucket = "action:identity".
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket           VARCHAR(128) NOT NULL,
    window_seconds   INT          NOT NULL,
    count            INT          NOT NULL DEFAULT 0,
    window_started_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- score_cache-Pflege via DB-Trigger (ADR-3: Trigger bevorzugt vor app-seitiger
-- Pflege → Konsistenz). Inkrementell: ideas.score_cache = SUM(votes.value).
-- Einzel-Statement-Trigger (kein BEGIN/END) → kein DELIMITER nötig, applier-sicher;
-- idempotent via DROP TRIGGER IF EXISTS. votes.idea_id ist unveränderlich, daher
-- aktualisiert der UPDATE-Trigger nur den value-Delta derselben Idee.
DROP TRIGGER IF EXISTS trg_votes_after_insert;
CREATE TRIGGER trg_votes_after_insert AFTER INSERT ON votes
    FOR EACH ROW UPDATE ideas SET score_cache = score_cache + NEW.value WHERE id = NEW.idea_id;

DROP TRIGGER IF EXISTS trg_votes_after_update;
CREATE TRIGGER trg_votes_after_update AFTER UPDATE ON votes
    FOR EACH ROW UPDATE ideas SET score_cache = score_cache - OLD.value + NEW.value WHERE id = NEW.idea_id;

DROP TRIGGER IF EXISTS trg_votes_after_delete;
CREATE TRIGGER trg_votes_after_delete AFTER DELETE ON votes
    FOR EACH ROW UPDATE ideas SET score_cache = score_cache - OLD.value WHERE id = OLD.idea_id;
