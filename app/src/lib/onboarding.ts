/**
 * Onboarding helpers shared by the setup wizard (components/SetupWizard*.tsx)
 * and the first-steps checklist (components/ActivationChecklist.tsx).
 *
 * Pure logic on purpose: template presets, the accent palette, the contrast
 * rule, checklist completion and the deep links into the board settings.
 * Everything the user reads lives in the setupWizard / activationChecklist
 * dictionaries; this module only holds keys and values.
 */

import { contrastRatio, isHexColor, WHITE_HEX } from '@votepit/ui/ds'
import { accountPath } from './accountContext'
import { type Entitlements, type FeatureKey, featureEntitlement } from './entitlements'

// ── Templates ───────────────────────────────────────────────────────────────

export type OnboardingTemplate = 'product' | 'event' | 'club' | 'team'

export interface TemplatePreset {
  /** setupWizard key of the name placeholder. */
  placeholderKey: string
  /** setupWizard key of the address placeholder. */
  slugPlaceholderKey: string
  /** Voting without an account on by default (every field here exists today). */
  anonymousVoting: boolean
  /** Suggested accent colour, taken from the design-system palette (tokens.generated.ts). */
  accent: string
  /** setupWizard key of the suggested welcome text (Lite and up only). */
  introKey: string
  /** setupWizard key of the typical status words, shown as a hint only. */
  statusesKey: string
}

export const ONBOARDING_TEMPLATES: Record<OnboardingTemplate, TemplatePreset> = {
  product: {
    placeholderKey: 'namePlaceholderProduct',
    slugPlaceholderKey: 'slugPlaceholder',
    anonymousVoting: false,
    accent: '#205E9A',
    introKey: 'introProduct',
    statusesKey: 'statusesProduct',
  },
  event: {
    placeholderKey: 'namePlaceholderEvent',
    slugPlaceholderKey: 'slugPlaceholderEvent',
    anonymousVoting: true,
    accent: '#7B3FA0',
    introKey: 'introEvent',
    statusesKey: 'statusesEvent',
  },
  club: {
    placeholderKey: 'namePlaceholderClub',
    slugPlaceholderKey: 'slugPlaceholderClub',
    anonymousVoting: true,
    accent: '#087354',
    introKey: 'introClub',
    statusesKey: 'statusesClub',
  },
  team: {
    placeholderKey: 'namePlaceholderTeam',
    slugPlaceholderKey: 'slugPlaceholderTeam',
    anonymousVoting: false,
    accent: '#102F28',
    introKey: 'introTeam',
    statusesKey: 'statusesTeam',
  },
}

// ── Accent colours ──────────────────────────────────────────────────────────

/** Board accent without a saved colour: the platform brand green (tokens.generated.css `--vpds-p-color-brand-emerald`). */
/** Brand presets and the default accent are data a board owner picks from, not theme colours (fixed exception). */
export const DEFAULT_ACCENT = '#10946D'

/** Swatches from the design-system palette (tokens.generated.css), all reaching 4.5:1 with white text. */
export const ACCENT_SWATCHES = [
  { key: 'forest', hex: '#102F28', labelKey: 'lookSwatchForest' },
  { key: 'info', hex: '#205E9A', labelKey: 'lookSwatchInfo' },
  { key: 'positive', hex: '#087354', labelKey: 'lookSwatchPositive' },
  { key: 'plum', hex: '#7B3FA0', labelKey: 'lookSwatchPlum' },
  { key: 'brick', hex: '#B53D2C', labelKey: 'lookSwatchBrick' },
  { key: 'ochre', hex: '#805600', labelKey: 'lookSwatchOchre' },
] as const

/** WCAG AA threshold for normal text, the same bar the server-side brand checks use. */
const MIN_TEXT_CONTRAST = 4.5

/** Normalises a typed colour to upper-case #RRGGBB, or null when it is not one. */
export function normalizeAccent(value: string): string | null {
  const trimmed = value.trim()
  return isHexColor(trimmed) ? trimmed.toUpperCase() : null
}

/** True when white text on this accent falls below AA (shown as a warning, never a block). */
export function accentHasLowContrast(hex: string): boolean {
  return contrastRatio(hex, WHITE_HEX) < MIN_TEXT_CONTRAST
}

// ── Plan gates ──────────────────────────────────────────────────────────────

/** Whether the viewer's entitlements allow a feature. Unknown (null) counts as locked. */
function isFeatureAllowed(
  entitlements: Entitlements | null | undefined,
  feature: FeatureKey,
): boolean {
  return featureEntitlement(entitlements, feature)?.allowed === true
}

/**
 * A feature shown as locked. Only a billing installation (Cloud) has plan gates;
 * Community/self-host shows everything as open, whatever the payload says.
 */
export function featureLocked(
  entitlements: Entitlements | null | undefined,
  feature: FeatureKey,
  billing: boolean,
): boolean {
  return billing && !isFeatureAllowed(entitlements, feature)
}

// ── Checklist ───────────────────────────────────────────────────────────────

export type ChecklistItemId = 'board' | 'appearance' | 'share' | 'team' | 'idea' | 'vote'

export interface ChecklistSignals {
  /** Board branding has a saved accent colour or logo. */
  appearanceSet: boolean
  /** The public link was copied from the checklist (client-side signal). */
  shareCopied: boolean
  /** Members on the account; null while unknown. */
  memberCount: number | null
  ideaCount: number
  voteCount: number
}

export interface ChecklistItemState {
  id: ChecklistItemId
  done: boolean
}

/** Completion per item, derived from real account data wherever the data exists. */
export function checklistProgress(signals: ChecklistSignals): ChecklistItemState[] {
  return [
    { id: 'board', done: true },
    { id: 'appearance', done: signals.appearanceSet },
    { id: 'share', done: signals.shareCopied },
    { id: 'team', done: (signals.memberCount ?? 1) > 1 },
    { id: 'idea', done: signals.ideaCount > 0 },
    { id: 'vote', done: signals.voteCount > 0 },
  ]
}

/** A board counts as styled once it has its own accent colour or logo. */
export function appearanceIsSet(
  branding: {
    primary_color: string | null
    logo_url: string | null
  } | null,
): boolean {
  return branding !== null && (branding.primary_color !== null || branding.logo_url !== null)
}

const SHARE_COPIED_KEY_PREFIX = 'vp_board_link_copied:'

/** Per-browser memory that a board's link was copied (a UI nicety, not account data). */
export function readShareCopied(boardSlug: string): boolean {
  try {
    return localStorage.getItem(SHARE_COPIED_KEY_PREFIX + boardSlug) === '1'
  } catch {
    return false
  }
}

export function markShareCopied(boardSlug: string): void {
  try {
    localStorage.setItem(SHARE_COPIED_KEY_PREFIX + boardSlug, '1')
  } catch {
    // Storage unavailable — the item simply stays open, a harmless degradation.
  }
}

// ── Next steps (deep links into the board settings) ─────────────────────────

export type NextStepId = 'appearance' | 'images' | 'branding' | 'members' | 'share' | 'moderation'

/** Accordion groups of the branding editor (AdminPage design route). */
export type EditorGroupId = 'colors' | 'images' | 'texts' | 'links' | 'advanced'

export interface NextStep {
  id: NextStepId
  /** Element id on the board settings page (AdminPage); null opens the page top. */
  anchor: string | null
  /** Editor group the card opens (`/design?group=`); null when the card is not an editor step. */
  editorGroup: EditorGroupId | null
  /** Route below the account prefix when the step is not on the board settings page. */
  path: string | null
  /** Feature whose lock state the card shows; null when the card is free. */
  gate: FeatureKey | null
}

export const NEXT_STEPS: readonly NextStep[] = [
  { id: 'appearance', anchor: null, editorGroup: 'colors', path: null, gate: null },
  { id: 'images', anchor: null, editorGroup: 'images', path: null, gate: 'cover_upload' },
  { id: 'branding', anchor: null, editorGroup: 'texts', path: null, gate: 'custom_wording' },
  { id: 'members', anchor: null, editorGroup: null, path: '/admin/members', gate: null },
  { id: 'share', anchor: null, editorGroup: null, path: null, gate: null },
  { id: 'moderation', anchor: 'board-moderation', editorGroup: null, path: null, gate: null },
]

/** The app route a next-step card opens (account prefix included in cloud mode). */
export function nextStepTarget(boardSlug: string, step: NextStep): string {
  if (step.path !== null) return accountPath(step.path)
  const base = accountPath(`/admin/boards/${boardSlug}`)
  if (step.editorGroup !== null) return `${base}/design?group=${step.editorGroup}`
  return step.anchor === null ? base : `${base}#${step.anchor}`
}

/** Editor route of one board, opened at the given group (the wizard's cover link and the checklist). */
export function boardSettingsTarget(boardSlug: string, group: EditorGroupId): string {
  return `${accountPath(`/admin/boards/${boardSlug}`)}/design?group=${group}`
}

/** Route that reopens the setup wizard, optionally for one board. */
export function reopenSetupTarget(boardSlug?: string): string {
  const query = boardSlug === undefined ? '' : `&board=${encodeURIComponent(boardSlug)}`
  return accountPath(`/admin/boards?setup=1${query}`)
}
