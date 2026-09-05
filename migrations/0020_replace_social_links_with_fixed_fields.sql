-- 0020_replace_social_links_with_fixed_fields — security redesign of the
-- profile-avatar-social feature (sprint social-links-structured).
--
-- The free-form model shipped in 0019 (`user_social_links`: up to 5 rows of
-- an arbitrary label + an arbitrary https:// URL) is a phishing/XSS-adjacent
-- risk on a shared-origin app: any account owner could put an arbitrary
-- clickable https:// URL — including a convincing lookalike domain — behind
-- an arbitrary label, and every viewer sees it rendered as their trusted
-- link. Replaced with exactly 4 FIXED, NAMED identifier fields per user.
-- Each is validated against the target platform's real username/hostname
-- grammar (SocialLinkValidator) and the URL is always constructed
-- server-side from the validated identifier — a user can never supply a
-- scheme, a host, a path, or any other URL component directly.
--
-- Data safety: `user_social_links` shipped in 0019 and today exists only on
-- staging (disposable test data, see tools/deploy.sh) — confirmed via a
-- repo-wide grep for any other reader of this table/column before writing
-- this migration. A clean DROP is safe; there is no production data or
-- export/backup path depending on the old JSON-array-shaped list.
DROP TABLE IF EXISTS user_social_links;

-- website_domain: bare domain only, e.g. "example.com" — deliberately NOT a
-- full URL and NOT permitting a path segment (see SocialLinkValidator::
-- website() doc comment for the path-segment judgment call). 253 = the
-- historical max length of a fully-qualified DNS hostname (RFC 1035 label
-- limits compounded across labels).
--
-- x_handle: bare X/Twitter handle, no leading "@" (stripped before storage
-- if the user typed one). 15 = X's historical documented max username
-- length (see SocialLinkValidator::xHandle() doc comment).
--
-- youtube_handle: bare YouTube handle, no leading "@" (never accepted from
-- the user at all — see SocialLinkValidator::youtubeHandle() doc comment;
-- "@" is prefixed only when constructing the youtube.com URL). 30 covers
-- YouTube's looser (vs. X) character/length allowance.
--
-- github_username: bare GitHub username. 39 = GitHub's documented max
-- username length.
--
-- All NULL = "not set" (every field independently optional, 0-4 filled).
ALTER TABLE users ADD COLUMN website_domain  VARCHAR(253) NULL AFTER avatar_filename;
ALTER TABLE users ADD COLUMN x_handle        VARCHAR(15)  NULL AFTER website_domain;
ALTER TABLE users ADD COLUMN youtube_handle  VARCHAR(30)  NULL AFTER x_handle;
ALTER TABLE users ADD COLUMN github_username VARCHAR(39)  NULL AFTER youtube_handle;
