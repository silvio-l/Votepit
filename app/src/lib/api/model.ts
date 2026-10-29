/**
 * Shared domain model types returned by several endpoints (ideas, boards,
 * users/bootstrap, comments).
 */

import type { BrandingPro } from '../branding'
import type { Entitlements } from '../entitlements'
import type { Features } from '../features'
import type { AccountRole } from './members'

// ── Types ────────────────────────────────────────────────────────────────────

/** Status values as returned by the PHP backend (underscore variant). */
export type IdeaStatus = 'open' | 'planned' | 'in_progress' | 'done' | 'declined' | 'archived'

/**
 * One status of a board's status registry (broaden-use-cases issue 03,
 * migrations/0073_create_board_statuses.sql). `category` is one of the six
 * canonical slugs and drives every behaviour (colour, roadmap column,
 * transitions); `key` is what an idea stores (`= category` for the six
 * system statuses, `s<base36>` for owner-defined ones). Labels are
 * owner-chosen PLAINTEXT overrides (null = default translation) — always
 * rendered as text, never as markup.
 */
export interface BoardStatusInfo {
  key: string
  category: IdeaStatus
  category_name: string
  is_system: boolean
  label_de: string | null
  label_en: string | null
  is_hidden: boolean
  allow_votes: boolean
  allow_comments: boolean
  /** Ideas currently in this status (null when the server did not count them). */
  idea_count: number | null
}

/** A board-scoped idea tag (migrations/0051_create_idea_tags.sql). */
export interface Tag {
  id: number
  name: string
  color: string
}

/**
 * One reasoned status change (P-01, migration 0091). Public plaintext only:
 * the acting user is never part of this shape. `responsible_role` is a fixed
 * label key, not a person.
 */
export interface StatusDecision {
  id: number
  status_from: string
  status_to: string
  reason: string | null
  next_step: string | null
  responsible_role: 'team' | 'moderation' | null
  created_at: string
}

export interface Idea {
  id: number
  board_id: number
  author_id: number
  title: string
  body: string
  /** P-01: the latest status decision carries a public reason (flag only, board lists). */
  has_decision_reason?: boolean
  /** Canonical category slug — unchanged contract, also for custom statuses. */
  status: IdeaStatus
  /** The exact status key (custom statuses: `s<base36>`), additive. */
  status_key?: string
  is_pinned: boolean
  score_cache: number
  /**
   * View counter (migrations/0049_add_ideas_view_count.sql) — an
   * X/Twitter-style "impressions" signal, deduped server-side without
   * cookies (IdeaViewTracker). Reflects state as of the read, i.e. lags one
   * view behind on a visitor's own first request to this page.
   */
  view_count: number
  created_at: string
  updated_at: string
  comment_count: number
  up_count: number
  down_count: number
  my_vote?: 'up' | 'down' | 'none'
  /** Tags assigned to this idea (BoardHomeAction/IdeaDetailAction, batch-attached). */
  tags: Tag[]
  /**
   * Pre-moderation (migration 0083): only present — as 'pending' — when the
   * author or a moderator views an idea still waiting for approval. Absent
   * on every published idea (payload unchanged).
   */
  moderation_state?: 'pending'
}

/** P-26 board display mode: follow the viewer's system scheme, or force light/dark. */
export type BoardThemeMode = 'system' | 'light' | 'dark'

export interface BoardData {
  id: number
  slug: string
  name: string
  intro: string
  /**
   * Whether the "Powered by Votepit" badge should render (branding
   * tiers). Already re-checks the account's CURRENT plan
   * server-side (BoardHomeAction) — true unless the account is on a plan
   * that allows hiding the badge AND has actually set `hide_badge`.
   */
  show_badge: boolean
  /**
   * Branding tokens, already re-checked against the account's CURRENT plan
   * server-side (BoardHomeAction) — null when unset OR when the account is
   * on a plan that doesn't allow the field (downgrade safeguard).
   * `primary_color` is ungated on every plan. Only --vp-* brand tokens;
   * semantic vote/status colors are never overridable here.
   */
  primary_color: string | null
  secondary_color: string | null
  logo_url: string | null
  /** Uploaded logo rendition id (wins over logo_url); null when none. Serve via boardAssetUrl(). */
  logo_public_id?: string | null
  /** Whether the current plan allows a logo at all (paid plans). */
  logo_allowed?: boolean
  /** Selected generated cover pattern (P-28c), plan-gated; null keeps the default cover. */
  cover_pattern?: string | null
  /** P-26 display mode (not plan-gated); null/absent = system. */
  theme_mode?: BoardThemeMode | null
  /**
   * Stored board imagery as opaque public ids per rendition, already gated
   * by the CURRENT plan server-side (BoardHomeAction). Serve via
   * boardAssetUrl(); absent keys mean "no image shown".
   */
  assets?: Partial<Record<'cover_wide' | 'cover_phone' | 'favicon' | 'og', string>>
  /**
   * Mean relative luminance per uploaded logo variant (board_assets.ink_luminance), plan-gated
   * like `assets`. The logo's entry drives the auto logo plate (see branding.ts).
   */
  asset_ink?: Partial<Record<'logo' | 'wordmark' | 'wordmark_light', number>>
  /** Back link to the customer site, plan-gated and re-validated server-side. */
  back_link?: { url: string; label: string } | null
  /** Plaintext footer note (<= 280 chars), plan-gated server-side. */
  footer_note?: string | null
  /** Pro branding the public payload applies (absent = Votepit defaults). */
  branding_pro?: BrandingPro | null
  /**
   * Per-board machine-translation opt-in (idea-comment-translation
   * follow-up) — defaults to false. Combine with `translation_available`
   * (bootstrap) and `is_authenticated` to decide whether to auto-translate
   * this board's ideas/comments — see BoardPage's `translationEnabled`.
   */
  translation_enabled: boolean
  /**
   * Per-board opt-in for voting without an account (ADR 0004) — defaults to
   * false. When true, an unauthenticated voter is not redirected to /login
   * (see useVote's `anonymousVotingEnabled` option).
   */
  anonymous_voting_enabled: boolean
  /** P-03: optional reason after a vote against is offered on this board. */
  vote_reasons_enabled?: boolean
  /**
   * Guest idea submission (ADR 0006): true when a visitor without an
   * account can submit an idea here. Absent on older payloads (= false).
   */
  anonymous_submission_available?: boolean
  /**
   * Legal board-operator identity (board-operator-compliance issue 03,
   * extended by migrations/0064_add_legal_operator_contact.sql for
   * international legal-notice completeness) — the account's
   * `legal_operator_name`/`legal_operator_address`/`legal_operator_email`/
   * `legal_operator_notice` (BoardHomeAction), not plan-gated (unlike
   * `show_badge` above). `null` when unset — tolerated for pre-gate legacy
   * boards, see BoardHomeAction's class doc. `legal_operator_notice` is
   * supplementary free text (jurisdiction-specific extras), never required.
   */
  legal_operator_name: string | null
  legal_operator_address: string | null
  legal_operator_email: string | null
  legal_operator_notice: string | null
  /** The board's status registry in display order (issue 03); absent on older payloads. */
  statuses?: BoardStatusInfo[]
  /**
   * Optional submission/voting windows (broaden-use-cases issue 04), state
   * computed by the SERVER clock — the SPA renders it and never gates on
   * the browser clock. Absent on older payloads (= everything open).
   */
  phases?: BoardPhasesInfo
}

/** Server-computed state of one optional time window (issue 04). */
export type PhaseState = 'open' | 'upcoming' | 'closed'

export interface PhaseWindow {
  state: PhaseState
  /** ISO 8601 UTC or null (no bound on that side). */
  opens_at: string | null
  closes_at: string | null
}

export interface BoardPhasesInfo {
  submissions: PhaseWindow
  voting: PhaseWindow
}

export interface PaginationMeta {
  page: number
  total_pages: number
}

/** Board-wide "this week" aggregates for the board's featured idea, plus the all-time idea total for milestone celebrations. */
export interface BoardStats {
  weekly_votes: number
  weekly_new_ideas: number
  avg_consensus: number
  total_ideas: number
}

/**
 * The board look every board-scoped page applies (BoardBrandingView on the
 * server, plan-gated there). Absent on older payloads = Votepit defaults.
 * Colours and logo only ever arrive as validated values; no CSS or markup.
 */
export interface BoardVisual {
  primary_color?: string | null
  secondary_color?: string | null
  logo_url?: string | null
  logo_public_id?: string | null
  logo_allowed?: boolean
  cover_pattern?: string | null
  theme_mode?: BoardThemeMode | null
  show_badge?: boolean
  back_link?: { url: string; label: string } | null
  footer_note?: string | null
  branding_pro?: BrandingPro | null
}

export interface BoardResponse {
  board: BoardData
  ideas: Idea[]
  stats: BoardStats
  active_status: string | null
  /** Exact status-key filter (`?status_key=`), wins over active_status. */
  active_status_key?: string | null
  active_sort: string
  /** Normalised search text the list was filtered by (`?q=`), null when not searching. */
  active_query?: string | null
  page: number
  total_pages: number
  /** Ideas matching the current filters across all pages (G-09). */
  total?: number
  /** Board tags with the number of listed ideas carrying each (G-09 filter menu). */
  tags?: BoardTagCount[]
  is_authenticated: boolean
}

export interface BoardTagCount {
  id: number
  name: string
  color: string
  count: number
}

export interface AccountMembership {
  account_slug: string
  role: AccountRole
  /** P-38: the account's plan and feature lock state, for this member only. */
  entitlements?: Entitlements
}

export interface User {
  id: number
  /** Random opaque handle, safe to display — `id` above is internal-only. */
  public_id: string | null
  /** Optional, globally unique display name the user chose (Account settings) — prefer this over public_id when set. */
  username: string | null
  is_admin: boolean
  is_operator: boolean
  /** Trusted-helper tier below is_operator — customer support (tickets, FAQ) only. */
  is_support: boolean
  /** Dedicated QA/E2E account, never a real customer — App.tsx skips Matomo entirely for it. */
  is_test_account: boolean
  /** Whether a password is already set — drives "set" vs "change" in ProfilePage. */
  has_password: boolean
  /** Whether TOTP 2FA is active — drives whether ProfilePage shows the setup wizard or the manage view. */
  totp_enabled: boolean
  /** Own avatar URL (profile-avatar-social), or null if none set — same value as ProfileData.avatar_url. */
  avatar_url: string | null
  /**
   * Own privacy setting (profile-visibility feature) — false (default) means
   * anonymous: other users see only a generic placeholder for this user's
   * idea/comment authorship and profile, never the avatar or social links.
   */
  profile_visible: boolean
  /** Account role per slug — use this (not is_admin) to gate account-owner/admin UI. */
  memberships: AccountMembership[]
  /**
   * Board-operator-compliance follow-up: total number of boards frozen for
   * non-compliance across every account this user owns (almost always 0 or
   * 1 owned account — "one owned account per signup"). Powers the header
   * bell's warning badge (LocalizedHeader.tsx) and AccountPage's banner.
   */
  compliance_frozen_board_count: number
  /** Slug of the first account with a compliance-frozen board, or null if none — bell link target. */
  compliance_frozen_account_slug: string | null
}

/**
 * Looks up the caller's role for the current account slug (null in
 * self-host mode — self-host is always exactly one account, so its single
 * membership applies regardless of slug), or null if not a member.
 */
export function accountRoleFor(user: User | null, accountSlug: string | null): AccountRole | null {
  if (!user) return null
  const memberships = user.memberships ?? []
  if (accountSlug === null) return memberships[0]?.role ?? null
  return memberships.find((m) => m.account_slug === accountSlug)?.role ?? null
}

export interface BootstrapData {
  csrf_token: string
  user: User | null
  /**
   * 'cloud' means board-/admin-scoped paths carry a leading /{accountSlug}
   * segment (Config::routingMode, cloud path routing) — see
   * accountContext.ts / App.tsx ScopedLayout, which read this once on mount.
   */
  routing_mode: 'self-host' | 'cloud'
  /** Client-facing Sentry DSN for @sentry/react (main.tsx), or '' to disable. Not sensitive. */
  sentry_dsn_frontend: string
  /** This installation's own optional analytics (Config::matomoUrl/matomoSiteId), '' disables it. Not sensitive. */
  matomo_url: string
  matomo_site_id: string
  /**
   * Self-host-only product-improvement telemetry state. null unless the
   * operator configured a telemetry target (and always null in cloud mode).
   * `opted_in` is true only after an explicit accept (opt-in, default off);
   * matomo_url/matomo_site_id are already '' when not opted in, so
   * lib/analytics.ts never needs to re-check `opted_in` itself.
   */
  telemetry: {
    decided: boolean
    opted_in: boolean
    matomo_url: string
    matomo_site_id: string
  } | null
  /** Installation capabilities (per-board SMTP, footer legal links, extension flags) — see lib/features.ts. */
  features: Features
  /**
   * OAuth2 login providers an operator has actually configured (Config::
   * configuredOAuthProviders(), only 'google'/'github' ever appear). Empty
   * by default (self-host with no oauth_providers config) — LoginPage.tsx
   * renders a provider button only when its key is present here, so an
   * unconfigured provider is simply absent, never a broken button.
   */
  oauth_providers: string[]
  /**
   * Whether the translate-idea/translate-comments endpoints are usable
   * (Config::translationConfigured(), fail-secure false by default). The
   * SPA must check this BEFORE ever calling translateIdea()/
   * translateComments() — false means no provider is configured, so a
   * request would only ever 503.
   */
  translation_available: boolean
}

/** The fixed, small emoji set — mirrors CommentReactionRepository::ALLOWED_REACTIONS server-side. */
export const COMMENT_REACTIONS = ['👍', '👎', '❤️', '😄', '🎉'] as const
export type CommentReaction = (typeof COMMENT_REACTIONS)[number]

export interface CommentReactionsSummary {
  /** Per-emoji counts; an emoji with zero reactions is simply absent. */
  counts: Partial<Record<CommentReaction, number>>
  /** The viewer's own active reaction, or null (incl. always null when anon). */
  my_reaction: CommentReaction | null
}

export interface Comment {
  id: number
  idea_id: number
  author_id: number
  body: string
  created_at: string
  edited_at: string | null
  reactions: CommentReactionsSummary
}

export interface IdeaDetailResponse {
  board: {
    id: number
    slug: string
    name: string
    translation_enabled: boolean
    /** Per-board opt-in for voting without an account (ADR 0004) — default false. */
    anonymous_voting_enabled: boolean
    /** P-03: optional reason after a vote against (default off). */
    vote_reasons_enabled?: boolean
    /** A status change on this board needs a reason (default off; absent = false). */
    status_reason_required?: boolean
    /** Board-operator identity (board-operator-compliance issue 03) — see BoardData. */
    legal_operator_name: string | null
    legal_operator_address: string | null
    legal_operator_email: string | null
    legal_operator_notice: string | null
    /** The board's status registry (issue 03); absent on older payloads. */
    statuses?: BoardStatusInfo[]
    /** Server-computed submission/voting windows (issue 04); absent = open. */
    phases?: BoardPhasesInfo
    /** Pro branding the payload applies (absent = Votepit defaults). */
    branding_pro?: BrandingPro | null
  } & BoardVisual
  idea: Idea & {
    /** Status keys this idea may move to (moderators; issue 03), additive. */
    allowed_next?: string[]
    /** Set when this idea was merged into another one (issue 10); read-only then. */
    merged_into_id?: number | null
    /** The canonical idea this one was merged into, if it is visible. */
    merged_into?: { id: number; title: string } | null
    /** Duplicates merged into this idea — only filled for moderators. */
    merged_ideas?: { id: number; title: string }[]
    /** Latest status decision (P-01); null when none was recorded. */
    decision?: StatusDecision | null
    /** Newest-first status history (P-01), public columns only. */
    decision_history?: StatusDecision[]
  }
  comments: Comment[]
  is_authenticated: boolean
}
