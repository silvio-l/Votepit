-- 0093_add_board_pro_branding — Pro branding (owner matrix Q13=a, gates Q19=a):
--   P-29 typography + shape presets, P-30 custom wording, P-31 header links and
--   the roadmap section toggle. All of it is gated by PlanPolicy features
--   (typography_presets, custom_wording, header_links_sections).
--
-- Values are only ever written after validation (BoardProBrandingValidator).
-- NULL / 0 = the Votepit default, so an unset board renders exactly as before.
-- A downgrade keeps the stored values; the public read path only applies them
-- while the account's plan includes the feature (kept, not applied).
--
-- Forward-only.
ALTER TABLE boards ADD COLUMN typography_preset VARCHAR(16) NULL;
ALTER TABLE boards ADD COLUMN shape_style VARCHAR(16) NULL;
ALTER TABLE boards ADD COLUMN density VARCHAR(16) NULL;
-- JSON object {"<locale>": {"<field>": "<plaintext>"}}; see BoardProBrandingValidator::wording().
ALTER TABLE boards ADD COLUMN wording_json TEXT NULL;
-- JSON list of at most 5 {"url": "https://…", "label": "…"}.
ALTER TABLE boards ADD COLUMN header_links_json TEXT NULL;
ALTER TABLE boards ADD COLUMN roadmap_hidden TINYINT(1) NOT NULL DEFAULT 0;
