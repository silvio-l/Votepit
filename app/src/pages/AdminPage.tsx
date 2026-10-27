/**
 * AdminPage — /admin/boards/:boardSlug
 *
 * Board admin surface: Branding + Statuses + Moderation + SMTP (Issue 14).
 *
 * Auth gate:
 *   - Anon      → redirect to /login?r=…
 *   - Non-admin → "no access" message (no forms rendered)
 *   - Admin     → three sheets: Branding, Moderation, SMTP
 *
 * The SMTP sheet only exists where the installation offers per-board SMTP
 * (bootstrap `features.board_smtp`, see lib/features.ts) — a hosted
 * multi-tenant install sends through the operator's mailer only and has no
 * per-board SMTP routes at all.
 *
 * Branding: primary_color, secondary_color, logo_url, intro, hide_badge,
 *   visibility. Only --vp-* brand tokens; semantic vote/status tokens are
 *   intentionally NOT editable so Up/Down meaning stays readable everywhere.
 *   Every channel here is server-validated (hex, image URL, plaintext) —
 *   tenant isolation depends on that, so nothing else is exposed.
 *
 * Moderation: toggle (enabled/disabled) + custom blocklist (add / remove).
 *   Word-add reloads the list so IDs are accurate for subsequent removes.
 */

import {
  Badge,
  Breadcrumbs,
  Button,
  ButtonLink,
  Checkbox,
  ConfirmDialog,
  CopyField,
  EmptyState,
  ErrorState,
  IconButton,
  InlineNotice,
  LoadingState,
  PageHeader,
  Section,
  Select,
  TextArea,
  TextField,
} from '@votepit/ui/ds'
import {
  AlertTriangle,
  Hourglass,
  Image as ImageIcon,
  Link2,
  Mail,
  Palette,
  Settings,
  Settings2,
  ShieldBan,
  ShieldCheck,
  Type,
  Webhook,
} from 'lucide-react'
import type { AnchorHTMLAttributes, ReactNode } from 'react'
import { forwardRef, useCallback, useEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import {
  BoardImageFields,
  BoardLinkFooterFields,
  useBoardAssets,
} from '../components/BoardAssetsSettings'
import { BoardChatNotificationsSection } from '../components/BoardChatNotificationsSection'
import { BoardImportPanel } from '../components/BoardImportPanel'
import { BoardQrPanel } from '../components/BoardQrPanel'
import {
  BrandingBuilder,
  type BrandingEditorGroup,
  type BrandingGroupId,
} from '../components/BrandingBuilder'
import { BrandingPreview } from '../components/BrandingPreview'
import {
  ProLinkFields,
  ProPresetFields,
  ProWordingFields,
  useBrandingPro,
} from '../components/BrandingProSection'
import { ColorField } from '../components/ColorField'
import { CoverPatternPicker } from '../components/CoverPatternPicker'
import { LogoField } from '../components/LogoField'
import { PhaseSettings } from '../components/PhaseSettings'
import { PlanUpgradeLink } from '../components/PlanUpgradeLink'
import { ResultsExport } from '../components/ResultsExport'
import { StatusSettings } from '../components/StatusSettings'
import { WidgetSettings } from '../components/WidgetSettings'
import { accountPath, fullBoardUrl, getAccountSlug } from '../lib/accountContext'
import type {
  ApiError,
  BoardThemeMode,
  BoardVisibility,
  BoardWebhookDelivery,
  BrandingField,
  DuplicateConfirmation,
  ModerationBaseLists,
  ModerationWord,
  PremoderationMode,
  User,
} from '../lib/api'
import {
  accountRoleFor,
  acknowledgeAdminBoardOperator,
  bootstrap,
  deleteAdminBoard,
  deleteAdminBoardWebhook,
  getAdminBoardSmtp,
  getAdminBoardWebhook,
  getAdminBranding,
  getAdminModeration,
  getAdminOperatorAcknowledgment,
  logout,
  renameAdminBoard,
  resetAdminBoardSmtp,
  saveAdminBoardSmtp,
  saveAdminBoardWebhook,
  saveAdminBranding,
  saveAdminModeration,
  setAdminBoardWebhookActive,
  testAdminBoardSmtp,
} from '../lib/api'
import {
  type BoardAssetKind,
  type BoardLinkFooterInput,
  boardAssetUrl,
  saveBoardLinkFooter,
} from '../lib/api/boardAssets'
import { qrAllowedFor } from '../lib/boardQr'
import { topbarBarColor } from '../lib/brandPlate'
import { getEdition } from '../lib/edition'
import { type Entitlements, type FeatureKey, requiredPlanFor } from '../lib/entitlements'
import { getFeatures, getTranslationAvailable } from '../lib/features'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

/** Router adapter for DS ButtonLink (takes `href`, routes with react-router). */
const ButtonRouterLink = forwardRef<
  HTMLAnchorElement,
  AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }
>(({ href, ...props }, ref) => <Link ref={ref} to={href} {...props} />)

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready'; boardName: string }

type SmtpPreset = 'outlook' | 'gmail' | 'custom'
type SmtpEncryption = 'tls' | 'ssl' | ''

/** Saved branding values, compared with the form for the unsaved-changes bar. */
interface BrandingBaseline {
  primary: string
  secondary: string
  logo: string
  pattern: string | null
  intro: string
  hideBadge: boolean
  visibility: BoardVisibility
  themeMode: BoardThemeMode
}

/** Editor groups a `?group=` deep link may open (see the branding editor). */
const EDITOR_GROUP_IDS: BrandingGroupId[] = ['colors', 'images', 'texts', 'links', 'advanced']

export default function AdminPage({ mode = 'settings' }: { mode?: 'settings' | 'design' } = {}) {
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()
  const t = useT('adminPage')
  const tCommon = useT('common')
  const tVoteReasons = useT('voteReasons')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  // ── General (title/slug rename + translation/anonymous-voting toggles) ────
  // Rename fields, the translation opt-in and the anonymous-voting opt-in
  // (ADR 0004) are all general board settings that live in the "General"
  // section — and, per live-tested UX feedback, share exactly ONE save
  // button/state for the whole section rather than one per sub-form.
  const [renameName, setRenameName] = useState('')
  const [renameSlug, setRenameSlug] = useState('')
  const [renameErrors, setRenameErrors] = useState<Record<string, string>>({})
  const [generalGeneralError, setGeneralGeneralError] = useState<string | null>(null)
  const [generalSaving, setGeneralSaving] = useState(false)
  const [generalSuccess, setGeneralSuccess] = useState(false)
  const [translationEnabled, setTranslationEnabled] = useState(false)
  const [anonymousVotingEnabled, setAnonymousVotingEnabled] = useState(false)
  // P-03: optional reason after a vote against (default off, operators see aggregates only).
  const [voteReasonsEnabled, setVoteReasonsEnabled] = useState(false)
  // A status change needs a reason on this board (default off; enforced server-side).
  const [statusReasonRequired, setStatusReasonRequired] = useState(false)

  // ── Branding state ─────────────────────────────────────────────────────────
  const [primaryColor, setPrimaryColor] = useState('')
  const [secondaryColor, setSecondaryColor] = useState('')
  const [logoUrl, setLogoUrl] = useState('')
  const [coverPattern, setCoverPattern] = useState<string | null>(null)
  const [themeMode, setThemeMode] = useState<BoardThemeMode>('system')
  const [intro, setIntro] = useState('')
  const [hideBadge, setHideBadge] = useState(false)
  const [visibility, setVisibility] = useState<BoardVisibility>('public')
  // The persisted visibility (not the unsaved <select> value) decides whether
  // the QR code is offered — a private board never gets one (issue 09).
  const [savedVisibility, setSavedVisibility] = useState<BoardVisibility>('private')
  const [allowedVisibilities, setAllowedVisibilities] = useState<BoardVisibility[]>(['public'])
  const [pendingVisibility, setPendingVisibility] = useState<BoardVisibility | null>(null)
  const [allowedBrandingFields, setAllowedBrandingFields] = useState<BrandingField[]>([])
  const [brandingEntitlements, setBrandingEntitlements] = useState<Entitlements | null>(null)
  // Optional AI confirmation of duplicate suggestions (issue 11) — null when
  // no extension provides it (Community): then nothing is shown.
  const [duplicateConfirmation, setDuplicateConfirmation] = useState<DuplicateConfirmation | null>(
    null,
  )
  const [brandingErrors, setBrandingErrors] = useState<Record<string, string>>({})
  const [brandingGeneralError, setBrandingGeneralError] = useState<string | null>(null)
  const [brandingSaving, setBrandingSaving] = useState(false)
  const [brandingSuccess, setBrandingSuccess] = useState(false)
  // Saved values the builder compares against (unsaved-changes bar, discard).
  const [brandingBaseline, setBrandingBaseline] = useState<BrandingBaseline | null>(null)
  // Design editor only: image/link and Pro state, saved by the editor's one Speichern.
  const isDesign = mode === 'design'
  // Picked, not yet uploaded image files (object URLs from the upload fields): the canvas shows them.
  const [pendingImages, setPendingImages] = useState<Partial<Record<BoardAssetKind, string>>>({})
  const setPendingImage = useCallback((kind: BoardAssetKind, url: string | null) => {
    setPendingImages((prev) => {
      if (prev[kind] === (url ?? undefined)) return prev
      const next = { ...prev }
      if (url === null) delete next[kind]
      else next[kind] = url
      return next
    })
  }, [])
  const onLogoPending = useCallback(
    (url: string | null) => setPendingImage('logo', url),
    [setPendingImage],
  )
  const onWordmarkPending = useCallback(
    (url: string | null) => setPendingImage('wordmark', url),
    [setPendingImage],
  )
  const onWordmarkLightPending = useCallback(
    (url: string | null) => setPendingImage('wordmark_light', url),
    [setPendingImage],
  )
  const assets = useBoardAssets(isDesign ? (boardSlug ?? '') : '')
  const pro = useBrandingPro(isDesign ? (boardSlug ?? '') : '')
  const [linkState, setLinkState] = useState<BoardLinkFooterInput>({
    back_link_url: '',
    back_link_label: '',
    footer_note: '',
  })
  const [linkBaseline, setLinkBaseline] = useState<BoardLinkFooterInput | null>(null)
  const [linkErrors, setLinkErrors] = useState<Partial<Record<keyof BoardLinkFooterInput, string>>>(
    {},
  )
  const [groupErrors, setGroupErrors] = useState<Partial<Record<BrandingGroupId, string>>>({})
  const [searchParams] = useSearchParams()
  const initialGroup = EDITOR_GROUP_IDS.find((id) => id === searchParams.get('group'))
  // Stored link values load once; later reloads (after an upload) must not overwrite unsaved edits.
  useEffect(() => {
    if (assets.data === null || linkBaseline !== null) return
    setLinkState(assets.data.link)
    setLinkBaseline(assets.data.link)
  }, [assets.data, linkBaseline])
  // Upgrade/downgrade/cancellation lifecycle: frozen-board notice.
  const [frozenAt, setFrozenAt] = useState<string | null>(null)
  const [complianceFrozenAt, setComplianceFrozenAt] = useState<string | null>(null)
  // Either freeze reason locks the settings (server answers 423 board_frozen).
  const boardFrozen = frozenAt !== null || complianceFrozenAt !== null

  // ── Board-operator acknowledgment state (Cloud-only, board-operator-
  // compliance ticket 05 follow-up — see BoardOperatorAcknowledgmentAction) ──
  const isCloud = getEdition() === 'cloud'
  const [operatorAcknowledged, setOperatorAcknowledged] = useState(false)
  const [operatorAcknowledgedAt, setOperatorAcknowledgedAt] = useState<string | null>(null)
  const [operatorAckSaving, setOperatorAckSaving] = useState(false)
  const [operatorAckError, setOperatorAckError] = useState<string | null>(null)

  // ── Moderation state ───────────────────────────────────────────────────────
  const [modEnabled, setModEnabled] = useState(true)
  const [words, setWords] = useState<ModerationWord[]>([])
  const [baseLists, setBaseLists] = useState<ModerationBaseLists | null>(null)
  const [newWord, setNewWord] = useState('')
  const [bulkMode, setBulkMode] = useState(false)
  const [bulkWords, setBulkWords] = useState('')
  const [modErrors, setModErrors] = useState<Record<string, string>>({})
  const [modGeneralError, setModGeneralError] = useState<string | null>(null)
  const [modSaving, setModSaving] = useState(false)
  const [modSuccess, setModSuccess] = useState<string | null>(null)
  // Pre-moderation (issue 05): approval mode + queue badge. 'anonymous'
  // (ADR 0006) = only ideas submitted without an account wait for approval.
  const [premoderationMode, setPremoderationMode] = useState<PremoderationMode>('off')
  const [pendingCount, setPendingCount] = useState(0)
  // Guest idea submission (ADR 0006): board switch + its preconditions.
  // false = server without the feature (older payload) → section hidden.
  const [guestSupported, setGuestSupported] = useState(false)
  const [guestEnabled, setGuestEnabled] = useState(false)
  const [guestTurnstile, setGuestTurnstile] = useState(false)
  const [guestPlanAllowed, setGuestPlanAllowed] = useState(false)
  const [guestInstanceAllowed, setGuestInstanceAllowed] = useState(false)
  const [guestPublicConfirmOpen, setGuestPublicConfirmOpen] = useState(false)

  // ── SMTP state ─────────────────────────────────────────────────────────────
  const [smtpPreset, setSmtpPreset] = useState<SmtpPreset>('custom')
  const [smtpHost, setSmtpHost] = useState('')
  const [smtpPort, setSmtpPort] = useState(587)
  const [smtpUser, setSmtpUser] = useState('')
  const [smtpEncryption, setSmtpEncryption] = useState<SmtpEncryption>('tls')
  const [smtpFromEmail, setSmtpFromEmail] = useState('')
  const [smtpFromName, setSmtpFromName] = useState('')
  const [smtpPassword, setSmtpPassword] = useState('')
  const [smtpPasswordSet, setSmtpPasswordSet] = useState(false)
  const [smtpVerifyPeer, setSmtpVerifyPeer] = useState(true)
  const [smtpUsesGlobalDefault, setSmtpUsesGlobalDefault] = useState(true)
  const [smtpErrors, setSmtpErrors] = useState<Record<string, string>>({})
  const [smtpGeneralError, setSmtpGeneralError] = useState<string | null>(null)
  const [smtpSaving, setSmtpSaving] = useState(false)
  const [smtpSuccess, setSmtpSuccess] = useState(false)
  const [smtpTestSending, setSmtpTestSending] = useState(false)
  const [smtpTestResult, setSmtpTestResult] = useState<{ ok: boolean; message: string } | null>(
    null,
  )
  const [smtpTestTo, setSmtpTestTo] = useState('')
  // Read once per mount — features are fixed for the lifetime of the SPA.
  const boardSmtpEnabled = getFeatures().board_smtp
  // Only offer the toggle when the installation actually has a translation
  // provider configured (Config::translationConfigured()) — a self-host
  // without Google credentials has nothing to opt into.
  const translationAvailable = getTranslationAvailable()

  // ── Webhook state (always available, both self-host and cloud) ────────────
  const [webhookConfigured, setWebhookConfigured] = useState(false)
  const [webhookUrl, setWebhookUrl] = useState('')
  const [webhookUrlInput, setWebhookUrlInput] = useState('')
  const [webhookActive, setWebhookActive] = useState(true)
  const [webhookDeliveries, setWebhookDeliveries] = useState<BoardWebhookDelivery[]>([])
  const [webhookRevealedSecret, setWebhookRevealedSecret] = useState<string | null>(null)
  const [webhookSaving, setWebhookSaving] = useState(false)
  const [webhookToggling, setWebhookToggling] = useState(false)
  const [webhookDeleting, setWebhookDeleting] = useState(false)
  const [webhookDeleteConfirmOpen, setWebhookDeleteConfirmOpen] = useState(false)
  const [webhookErrors, setWebhookErrors] = useState<Record<string, string>>({})
  const [webhookGeneralError, setWebhookGeneralError] = useState<string | null>(null)

  // ── Danger zone: board deletion ────────────────────────────────────────────
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [deleteSlugInput, setDeleteSlugInput] = useState('')
  // Address change (slug) waits for confirmation: the old address stops working.
  const [slugConfirmOpen, setSlugConfirmOpen] = useState(false)
  const [deletingBoard, setDeletingBoard] = useState(false)
  const [deleteBoardError, setDeleteBoardError] = useState<string | null>(null)

  // ── Initialise ─────────────────────────────────────────────────────────────

  useEffect(() => {
    if (!boardSlug) return
    const slug: string = boardSlug
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath(`/admin/boards/${slug}`))}`, {
            replace: true,
          })
          return
        }

        // No client is_admin pre-check (platform-admin flag, not the account
        // role) — relies on the server 403 (accountAdmin()) in the catch below.
        setIsAuthenticated(true)
        setUser(boot.user)

        const [branding, moderation, smtpSettings, webhookSettings, operatorAck] =
          await Promise.all([
            getAdminBranding(slug),
            getAdminModeration(slug),
            boardSmtpEnabled ? getAdminBoardSmtp(slug) : Promise.resolve(null),
            getAdminBoardWebhook(slug),
            isCloud ? getAdminOperatorAcknowledgment(slug) : Promise.resolve(null),
          ])

        if (cancelled) return

        setRenameName(branding.board_name)
        setRenameSlug(branding.board_slug)
        setPrimaryColor(branding.primary_color ?? '')
        setSecondaryColor(branding.secondary_color ?? '')
        setLogoUrl(branding.logo_url ?? '')
        setCoverPattern(branding.cover_pattern ?? null)
        setThemeMode(branding.theme_mode ?? 'system')
        setIntro(branding.intro ?? '')
        setHideBadge(branding.hide_badge)
        setTranslationEnabled(branding.translation_enabled)
        setAnonymousVotingEnabled(branding.anonymous_voting_enabled)
        setVoteReasonsEnabled(branding.vote_reasons_enabled ?? false)
        setStatusReasonRequired(branding.status_reason_required ?? false)
        setVisibility(branding.visibility)
        setSavedVisibility(branding.visibility)
        setBrandingBaseline({
          primary: branding.primary_color ?? '',
          secondary: branding.secondary_color ?? '',
          logo: branding.logo_url ?? '',
          pattern: branding.cover_pattern ?? null,
          intro: branding.intro ?? '',
          hideBadge: branding.hide_badge,
          visibility: branding.visibility,
          themeMode: branding.theme_mode ?? 'system',
        })
        setAllowedVisibilities(branding.allowed_visibilities)
        setAllowedBrandingFields(branding.allowed_branding_fields)
        setBrandingEntitlements(branding.entitlements ?? null)
        setDuplicateConfirmation(branding.duplicate_confirmation ?? null)
        setFrozenAt(branding.frozen_at ?? null)
        setComplianceFrozenAt(branding.compliance_frozen_at ?? null)
        setModEnabled(moderation.moderation_enabled)
        setWords(moderation.words)
        setBaseLists(moderation.base_lists)
        setPremoderationMode(moderation.premoderation)
        setGuestSupported(moderation.anonymous_submission !== undefined)
        setGuestEnabled(moderation.anonymous_submission?.enabled ?? false)
        setGuestTurnstile(moderation.anonymous_submission?.turnstile_configured ?? false)
        setGuestPlanAllowed(moderation.anonymous_submission?.plan_allowed ?? false)
        setGuestInstanceAllowed(moderation.anonymous_submission?.instance_allowed ?? true)
        setPendingCount(moderation.pending_count)
        if (smtpSettings !== null) {
          setSmtpHost(smtpSettings.host)
          setSmtpPort(smtpSettings.port)
          setSmtpUser(smtpSettings.user)
          setSmtpEncryption(smtpSettings.encryption)
          setSmtpFromEmail(smtpSettings.from_email)
          setSmtpFromName(smtpSettings.from_name)
          setSmtpPasswordSet(smtpSettings.password_set)
          setSmtpVerifyPeer(smtpSettings.verify_peer)
          setSmtpUsesGlobalDefault(smtpSettings.uses_global_default)
        }
        setWebhookConfigured(webhookSettings.configured)
        setWebhookUrl(webhookSettings.url ?? '')
        setWebhookUrlInput(webhookSettings.url ?? '')
        setWebhookActive(webhookSettings.active)
        setWebhookDeliveries(webhookSettings.deliveries)
        if (operatorAck !== null) {
          setOperatorAcknowledged(operatorAck.acknowledged)
          setOperatorAcknowledgedAt(operatorAck.acknowledged_at)
        }
        setPageState({ phase: 'ready', boardName: branding.board_name })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(accountPath(`/admin/boards/${slug}`))}`, {
            replace: true,
          })
          return
        }
        if (apiErr.name === 'ApiError' && apiErr.status === 403) {
          setIsAuthenticated(true)
          setPageState({ phase: 'access_denied' })
          return
        }
        const msg =
          (apiErr as ApiError)?.payload?.message ?? (err as Error)?.message ?? t('loadError')
        setPageState({ phase: 'error', message: msg })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [boardSlug, boardSmtpEnabled, isCloud, navigate, t])

  // ── Handlers ───────────────────────────────────────────────────────────────

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const handleGeneralSave = (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || generalSaving) return
    // A new address makes the old one stop working (no redirect): confirm first.
    if (renameSlug !== boardSlug) {
      setSlugConfirmOpen(true)
      return
    }
    void saveGeneral()
  }

  const saveGeneral = async () => {
    if (!boardSlug) return

    const renamePayload: { name?: string; slug?: string } = {}
    if (renameName !== boardName) renamePayload.name = renameName
    if (renameSlug !== boardSlug) renamePayload.slug = renameSlug

    setGeneralSaving(true)
    setRenameErrors({})
    setGeneralGeneralError(null)
    setGeneralSuccess(false)

    try {
      // Toggles first: a slug change below navigates away and re-fetches the
      // page from scratch, so anything not saved before that point is lost.
      await saveAdminBranding(boardSlug, {
        primary_color: primaryColor,
        secondary_color: secondaryColor,
        logo_url: logoUrl,
        intro,
        hide_badge: hideBadge,
        translation_enabled: translationEnabled,
        anonymous_voting_enabled: anonymousVotingEnabled,
        vote_reasons_enabled: voteReasonsEnabled,
        status_reason_required: statusReasonRequired,
      })

      if (Object.keys(renamePayload).length > 0) {
        const result = await renameAdminBoard(boardSlug, renamePayload)
        if (result.slug !== boardSlug) {
          // Slug changed → the board now lives at a new URL; re-navigating
          // re-triggers the load effect, which re-fetches everything fresh.
          navigate(accountPath(`/admin/boards/${result.slug}`), { replace: true })
          return
        }
        setPageState({ phase: 'ready', boardName: result.name })
      }

      setGeneralSuccess(true)
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setRenameErrors(fields)
      } else {
        setGeneralGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
      }
    } finally {
      setGeneralSaving(false)
    }
  }

  // The only write a frozen board accepts: switching visibility to private.
  const handleMakePrivate = async () => {
    if (!boardSlug || brandingSaving) return

    setBrandingSaving(true)
    setBrandingGeneralError(null)
    setBrandingSuccess(false)

    try {
      await saveAdminBranding(boardSlug, { visibility: 'private' })
      setVisibility('private')
      setSavedVisibility('private')
    } catch (err) {
      const apiErr = err as ApiError
      setBrandingGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
    } finally {
      setBrandingSaving(false)
    }
  }

  // Dirty state per editor group: the groups save as one unit, each with its own failure message.
  const linkDirty =
    linkBaseline !== null &&
    (linkState.back_link_url !== linkBaseline.back_link_url ||
      linkState.back_link_label !== linkBaseline.back_link_label ||
      linkState.footer_note !== linkBaseline.footer_note)
  const groupDirty: Record<BrandingGroupId, boolean> = {
    colors:
      brandingBaseline !== null &&
      (primaryColor !== brandingBaseline.primary ||
        secondaryColor !== brandingBaseline.secondary ||
        themeMode !== brandingBaseline.themeMode),
    images:
      brandingBaseline !== null &&
      (logoUrl !== brandingBaseline.logo || coverPattern !== brandingBaseline.pattern),
    texts:
      (brandingBaseline !== null && intro !== brandingBaseline.intro) ||
      (pageState.phase === 'ready' && renameName !== pageState.boardName) ||
      pro.dirty.wording,
    links: pro.dirty.links,
    advanced:
      (brandingBaseline !== null &&
        (hideBadge !== brandingBaseline.hideBadge || visibility !== brandingBaseline.visibility)) ||
      pro.dirty.presets ||
      linkDirty,
  }
  const anyGroupDirty = Object.values(groupDirty).some(Boolean)

  // One Speichern: colours/logo/texts/badge/visibility, then Pro groups, then the
  // back link and footer. Each request stands on its own, so a failure keeps only
  // its own changes unsaved and the other groups still save.
  const handleBrandingSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || brandingSaving) return

    setBrandingSaving(true)
    setBrandingErrors({})
    setBrandingGeneralError(null)
    setBrandingSuccess(false)
    setGroupErrors({})
    setLinkErrors({})
    setRenameErrors({})

    const failures: Partial<Record<BrandingGroupId, string>> = {}
    const failed = (groups: BrandingGroupId[], message: string) => {
      for (const group of groups) if (groupDirty[group]) failures[group] = message
    }

    // 1. Colours, logo, intro, badge and visibility in one request.
    try {
      // Q19: a plan-locked field is not sent, so its stored value is kept.
      const canSend = (field: BrandingField): boolean => allowedBrandingFields.includes(field)
      await saveAdminBranding(boardSlug, {
        primary_color: primaryColor,
        ...(canSend('secondary_color') ? { secondary_color: secondaryColor } : {}),
        ...(canSend('logo_url') ? { logo_url: logoUrl } : {}),
        ...(canSend('cover_pattern') ? { cover_pattern: coverPattern ?? '' } : {}),
        theme_mode: themeMode,
        ...(canSend('intro') ? { intro } : {}),
        ...(canSend('hide_badge') ? { hide_badge: hideBadge } : {}),
        ...(allowedVisibilities.includes(visibility) || visibility !== savedVisibility
          ? { visibility }
          : {}),
      })
      setSavedVisibility(visibility)
      setBrandingBaseline({
        primary: primaryColor,
        secondary: secondaryColor,
        logo: logoUrl,
        pattern: coverPattern,
        intro,
        hideBadge,
        visibility,
        themeMode,
      })
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setBrandingErrors(fields)
      }
      failed(
        ['colors', 'images', 'texts', 'advanced'],
        apiErr?.payload?.message ?? t('saveFailedGeneric'),
      )
    }

    // 1b. Board name: the Allgemein tab's endpoint and validation (422 fields.name).
    if (boardSlug && boardName !== undefined && renameName !== boardName) {
      try {
        const result = await renameAdminBoard(boardSlug, { name: renameName })
        setPageState({ phase: 'ready', boardName: result.name })
      } catch (err) {
        const apiErr = err as ApiError
        const fields = apiErr?.payload?.fields ?? {}
        if (fields.name !== undefined) setRenameErrors({ name: fields.name })
        failed(['texts'], apiErr?.payload?.message ?? t('saveFailedGeneric'))
      }
    }

    // 2. Pro groups: wording, header links and presets.
    if (pro.data !== null) {
      const res = await pro.save()
      if (!res.ok) {
        if (pro.dirty.wording) failures.texts = res.message
        if (pro.dirty.links) failures.links = res.message
        if (pro.dirty.presets) failures.advanced = res.message
      }
    }

    // 3. Back link and footer note (Lite gate of the assets endpoint).
    if (linkBaseline !== null && !linkLocked) {
      try {
        await saveBoardLinkFooter(boardSlug, linkState)
        setLinkBaseline(linkState)
      } catch (err) {
        const apiErr = err as ApiError
        if (apiErr?.payload?.key === 'validation_error') {
          setLinkErrors(
            (apiErr.payload as { fields?: Partial<Record<keyof BoardLinkFooterInput, string>> })
              .fields ?? {},
          )
        }
        if (groupDirty.advanced) failures.advanced = t('saveFailedGeneric')
      }
    }

    setGroupErrors(failures)
    if (Object.keys(failures).length > 0) setBrandingGeneralError(t('builderPartialSaveError'))
    else setBrandingSuccess(true)
    setBrandingSaving(false)
  }

  const discardBrandingChanges = () => {
    pro.reset()
    if (linkBaseline !== null) setLinkState(linkBaseline)
    setLinkErrors({})
    setGroupErrors({})
    setBrandingErrors({})
    setBrandingGeneralError(null)
    setBrandingSuccess(false)
    if (boardName !== undefined) setRenameName(boardName)
    setRenameErrors({})
    if (brandingBaseline === null) return
    setPrimaryColor(brandingBaseline.primary)
    setSecondaryColor(brandingBaseline.secondary)
    setLogoUrl(brandingBaseline.logo)
    setCoverPattern(brandingBaseline.pattern)
    setIntro(brandingBaseline.intro)
    setHideBadge(brandingBaseline.hideBadge)
    setVisibility(brandingBaseline.visibility)
    setThemeMode(brandingBaseline.themeMode)
  }

  const handleOperatorAcknowledge = async () => {
    if (!boardSlug || operatorAckSaving || operatorAcknowledged) return

    setOperatorAckSaving(true)
    setOperatorAckError(null)

    try {
      const status = await acknowledgeAdminBoardOperator(boardSlug)
      setOperatorAcknowledged(status.acknowledged)
      setOperatorAcknowledgedAt(status.acknowledged_at)
    } catch (err) {
      const apiErr = err as ApiError
      setOperatorAckError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
    } finally {
      setOperatorAckSaving(false)
    }
  }

  /** Content filter switch: saves on click; reverts the switch if saving fails. */
  const handleModerationToggle = async (next: boolean) => {
    if (!boardSlug || modSaving) return

    const previous = modEnabled
    setModEnabled(next)
    setModSaving(true)
    setModSuccess(null)
    setModGeneralError(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'toggle',
        moderation_enabled: next ? '1' : '0',
      })
      setModSuccess(t('moderationSaved'))
    } catch (err) {
      setModEnabled(previous)
      const apiErr = err as ApiError
      setModGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
    } finally {
      setModSaving(false)
    }
  }

  /** Saves a premoderation mode on change; reverts the switch if saving fails. */
  const savePremoderationMode = async (next: PremoderationMode, confirmGuestPublic = false) => {
    if (!boardSlug || modSaving) return

    const previous = premoderationMode
    setPremoderationMode(next)
    setModSaving(true)
    setModSuccess(null)
    setModGeneralError(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'premoderation',
        premoderation: next,
        ...(confirmGuestPublic ? { confirm_guest_public: true } : {}),
      })
      setModSuccess(t('premoderationSaved'))
    } catch (err) {
      setPremoderationMode(previous)
      const apiErr = err as ApiError
      setModGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
    } finally {
      setModSaving(false)
    }
  }

  // "Approve all": switching it off keeps guest ideas under review while
  // guests can submit (ADR 0006 §8) — going fully public is its own,
  // explicitly confirmed step (the guest review switch below).
  const handlePremoderationChange = (next: boolean) => {
    void savePremoderationMode(next ? 'all' : guestEnabled ? 'anonymous' : 'off')
  }

  const handleGuestReviewChange = (next: boolean) => {
    if (next) {
      void savePremoderationMode('anonymous')
      return
    }
    setGuestPublicConfirmOpen(true)
  }

  /** Guest switch (ADR 0006): turning it on raises `off` to `anonymous` server-side. */
  const handleGuestSubmissionChange = async (next: boolean) => {
    if (!boardSlug || modSaving) return

    setModSaving(true)
    setModSuccess(null)
    setModGeneralError(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'anonymous_submission',
        anonymous_submission_enabled: next ? '1' : '0',
      })
      setGuestEnabled(next)
      if (next && premoderationMode === 'off') setPremoderationMode('anonymous')
      setModSuccess(t('guestSubmissionSaved'))
    } catch (err) {
      const apiErr = err as ApiError
      setModGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
    } finally {
      setModSaving(false)
    }
  }

  const handleAddWord = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || modSaving || newWord.trim() === '') return

    setModSaving(true)
    setModErrors({})
    setModSuccess(null)
    setModGeneralError(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'add',
        new_word: newWord.trim(),
      })
      // Reload to get accurate IDs for subsequent removes.
      const mod = await getAdminModeration(boardSlug)
      setWords(mod.words)
      setNewWord('')
      setModSuccess(t('wordAdded'))
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setModErrors(fields)
      } else {
        setModGeneralError(apiErr?.payload?.message ?? t('addWordFailed'))
      }
    } finally {
      setModSaving(false)
    }
  }

  const handleBulkAddWords = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || modSaving || bulkWords.trim() === '') return

    setModSaving(true)
    setModErrors({})
    setModSuccess(null)
    setModGeneralError(null)

    try {
      const result = await saveAdminModeration(boardSlug, {
        action: 'bulk_add',
        words_blob: bulkWords,
      })
      const mod = await getAdminModeration(boardSlug)
      setWords(mod.words)
      setBulkWords('')
      if ('added' in result) {
        setModSuccess(t('bulkWordsAdded', { added: result.added, skipped: result.skipped }))
      }
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setModErrors(fields)
      } else {
        setModGeneralError(apiErr?.payload?.message ?? t('addWordFailed'))
      }
    } finally {
      setModSaving(false)
    }
  }

  const handleRemoveWord = async (wordId: number) => {
    if (!boardSlug || modSaving) return

    setModSaving(true)
    setModSuccess(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'remove',
        word_id: wordId,
      })
      setWords((prev) => prev.filter((w) => w.id !== wordId))
      setModSuccess(t('wordRemoved'))
    } catch {
      // Silent fail — word may already be gone; list stays as-is.
    } finally {
      setModSaving(false)
    }
  }

  // Preset choice pre-fills host/port/encryption; "custom" leaves fields untouched.
  const handlePresetChange = (preset: SmtpPreset) => {
    setSmtpPreset(preset)
    if (preset === 'outlook') {
      setSmtpHost('smtp-mail.outlook.com')
      setSmtpPort(587)
      setSmtpEncryption('tls')
    } else if (preset === 'gmail') {
      setSmtpHost('smtp.gmail.com')
      setSmtpPort(587)
      setSmtpEncryption('tls')
    }
  }

  const handleSmtpSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || smtpSaving) return

    setSmtpSaving(true)
    setSmtpErrors({})
    setSmtpGeneralError(null)
    setSmtpSuccess(false)
    setSmtpTestResult(null)

    try {
      await saveAdminBoardSmtp(boardSlug, {
        host: smtpHost,
        port: smtpPort,
        user: smtpUser,
        encryption: smtpEncryption,
        from_email: smtpFromEmail,
        from_name: smtpFromName,
        password: smtpPassword,
        verify_peer: smtpVerifyPeer,
      })
      setSmtpSuccess(true)
      setSmtpUsesGlobalDefault(false)
      setSmtpPassword('') // clear the password field after saving
      if (smtpPassword !== '') setSmtpPasswordSet(true)
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setSmtpErrors(fields)
      } else {
        setSmtpGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
      }
    } finally {
      setSmtpSaving(false)
    }
  }

  const handleSmtpReset = async () => {
    if (!boardSlug || smtpSaving) return
    setSmtpSaving(true)
    setSmtpErrors({})
    setSmtpGeneralError(null)
    setSmtpSuccess(false)
    setSmtpTestResult(null)
    try {
      await resetAdminBoardSmtp(boardSlug)
      setSmtpUsesGlobalDefault(true)
      setSmtpHost('')
      setSmtpPort(587)
      setSmtpUser('')
      setSmtpEncryption('tls')
      setSmtpFromEmail('')
      setSmtpFromName('')
      setSmtpPasswordSet(false)
      setSmtpPassword('')
      setSmtpVerifyPeer(true)
      setSmtpSuccess(true)
    } catch (err) {
      const apiErr = err as ApiError
      setSmtpGeneralError(apiErr?.payload?.message ?? t('smtpResetFailed'))
    } finally {
      setSmtpSaving(false)
    }
  }

  const handleSmtpTest = async () => {
    if (!boardSlug || smtpTestSending) return
    if (!smtpTestTo.trim()) {
      setSmtpTestResult({ ok: false, message: t('smtpTestRecipientRequired') })
      return
    }
    setSmtpTestSending(true)
    setSmtpTestResult(null)

    try {
      const result = await testAdminBoardSmtp(boardSlug, {
        to: smtpTestTo.trim(),
        host: smtpHost,
        port: smtpPort,
        user: smtpUser,
        encryption: smtpEncryption,
        from_email: smtpFromEmail,
        from_name: smtpFromName,
        password: smtpPassword || undefined,
      })
      setSmtpTestResult({
        ok: true,
        message: t('smtpTestSentTo', { recipient: result.recipient }),
      })
    } catch (err) {
      const apiErr = err as ApiError
      setSmtpTestResult({
        ok: false,
        message: apiErr?.payload?.message ?? t('smtpTestFailed'),
      })
    } finally {
      setSmtpTestSending(false)
    }
  }

  const handleWebhookSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || webhookSaving) return

    setWebhookSaving(true)
    setWebhookErrors({})
    setWebhookGeneralError(null)

    try {
      const result = await saveAdminBoardWebhook(boardSlug, webhookUrlInput.trim())
      setWebhookConfigured(true)
      setWebhookUrl(webhookUrlInput.trim())
      setWebhookActive(true)
      setWebhookRevealedSecret(result.secret)
      // Reload deliveries — a URL change starts a fresh log for the new target.
      const fresh = await getAdminBoardWebhook(boardSlug)
      setWebhookDeliveries(fresh.deliveries)
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setWebhookErrors(fields)
      } else {
        setWebhookGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
      }
    } finally {
      setWebhookSaving(false)
    }
  }

  const handleWebhookToggleActive = async () => {
    if (!boardSlug || webhookToggling) return
    setWebhookToggling(true)
    setWebhookGeneralError(null)

    try {
      const result = await setAdminBoardWebhookActive(boardSlug, !webhookActive)
      setWebhookActive(result.active)
    } catch (err) {
      const apiErr = err as ApiError
      setWebhookGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
    } finally {
      setWebhookToggling(false)
    }
  }

  const handleWebhookDelete = async () => {
    if (!boardSlug || webhookDeleting) return
    setWebhookDeleting(true)

    try {
      await deleteAdminBoardWebhook(boardSlug)
      setWebhookConfigured(false)
      setWebhookUrl('')
      setWebhookUrlInput('')
      setWebhookActive(true)
      setWebhookDeliveries([])
      setWebhookRevealedSecret(null)
      setWebhookDeleteConfirmOpen(false)
    } catch (err) {
      const apiErr = err as ApiError
      setWebhookGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
    } finally {
      setWebhookDeleting(false)
    }
  }

  const handleDeleteBoard = async () => {
    if (!boardSlug || deletingBoard) return
    setDeletingBoard(true)
    setDeleteBoardError(null)

    try {
      await deleteAdminBoard(boardSlug, deleteSlugInput)
      navigate(accountPath('/admin/boards'), { replace: true })
    } catch (err) {
      const apiErr = err as ApiError
      setDeleteBoardError(apiErr?.payload?.message ?? t('deleteBoardFailed'))
      setDeletingBoard(false)
    }
  }

  // ── Frame ──────────────────────────────────────────────────────────────────

  const boardName = pageState.phase === 'ready' ? pageState.boardName : undefined
  const isOwner = accountRoleFor(user, getAccountSlug()) === 'owner'
  // Deep links from the setup wizard and the next-step cards carry #<anchor>.
  // Scroll once the page has rendered; the hook sits above the early returns.
  const location = useLocation()
  const hashAnchor = location.hash.slice(1)
  useEffect(() => {
    if (pageState.phase !== 'ready' || hashAnchor === '') return
    document.getElementById(hashAnchor)?.scrollIntoView({ block: 'start' })
  }, [pageState.phase, hashAnchor])

  const frame = (children: ReactNode) =>
    mode === 'design' ? (
      <div className="min-h-dvh bg-vp-bg text-vp-ink">{children}</div>
    ) : (
      <AdminShell
        user={user}
        isAuthenticated={isAuthenticated}
        authPending={pageState.phase === 'loading'}
        onLogout={handleLogout}
        onLogin={() => navigate(`/login?r=${encodeURIComponent(accountPath(`/${boardSlug}`))}`)}
        scope={boardName}
      >
        {children}
      </AdminShell>
    )

  if (pageState.phase === 'loading') {
    return frame(<LoadingState label={t('loading')} rows={6} />)
  }

  if (pageState.phase === 'access_denied') {
    return frame(
      <ErrorState
        kind="denied"
        title={t('accessDeniedTitle')}
        description={t('accessDeniedBody')}
        action={
          <Button testId="admin-access-logout" onClick={handleLogout}>
            {tCommon('header.logout')}
          </Button>
        }
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  // Branding tiers: staged field-level gating — mirrors how the
  // visibility <select> already disables options per plan.
  const canSetSecondaryColor = allowedBrandingFields.includes('secondary_color')
  const canSetLogo = allowedBrandingFields.includes('logo_url')
  const canSetIntro = allowedBrandingFields.includes('intro')
  const canHideBadge = allowedBrandingFields.includes('hide_badge')
  const visibilityLocked = allowedVisibilities.length <= 1
  // P-38: the tier a locked field needs (from the branding GET's entitlements).
  const requiredFor = (feature: FeatureKey) => requiredPlanFor(brandingEntitlements, feature)
  // Locked field: a kept value says it is inactive until the tier changes; an
  // empty one just names the tier.
  const lockedHint = (feature: FeatureKey, hasValue: boolean): string => {
    const tier = requiredFor(feature)
    if (!hasValue || tier === null) return t('planUpgradeHint')
    return t('brandingKeptHint', { plan: tier === 'pro' ? 'Pro' : 'Lite' })
  }

  // Field groups of the design editor (route: admin/boards/:boardSlug/design).
  // One Speichern writes every changed group; a failed group keeps its changes.
  const proTier = (feature: FeatureKey) => requiredPlanFor(pro.data?.entitlements, feature)
  const wordingOn = pro.data !== null && proTier('custom_wording') === null
  const linksOn = pro.data !== null && proTier('header_links_sections') === null
  const coverLocked = assets.data !== null && !assets.data.allowed.cover_upload
  const linkLocked = assets.data !== null && !assets.data.allowed.back_link_footer
  const coverId = assets.data?.variants?.cover_wide?.id
  const storedWords = pro.form?.wording[pro.locale]
  const voteWords =
    wordingOn && storedWords?.vote_for && storedWords.vote_against
      ? { up: storedWords.vote_for, down: storedWords.vote_against }
      : null
  const brandingGroups: BrandingEditorGroup[] = [
    {
      id: 'colors',
      icon: <Palette size={16} />,
      label: t('builderGroupColors'),
      badge: canSetSecondaryColor ? null : requiredFor('secondary_color'),
      dirty: groupDirty.colors,
      error: groupErrors.colors ?? null,
      content: (
        <div className="grid grid-cols-1 gap-ds-md">
          <Select
            testId="admin-theme-mode"
            label={t('themeModeLabel')}
            id="admin-theme-mode"
            name="theme_mode"
            value={themeMode}
            onChange={(v) => setThemeMode(v as BoardThemeMode)}
            disabled={brandingSaving}
            error={brandingErrors.theme_mode}
            hint={brandingErrors.theme_mode !== undefined ? undefined : t('themeModeHint')}
            className="max-w-ds-field"
          >
            <option value="system">{t('themeModeSystem')}</option>
            <option value="light">{t('themeModeLight')}</option>
            <option value="dark">{t('themeModeDark')}</option>
          </Select>
          <ColorField
            testId="admin-primary-color"
            swatchRole="accent"
            label={t('primaryColorLabel')}
            name="primary_color"
            id="admin-primary-color"
            value={primaryColor}
            onChange={setPrimaryColor}
            placeholder="#1fa890"
            error={brandingErrors.primary_color}
            hint={brandingErrors.primary_color !== undefined ? undefined : t('primaryColorHint')}
            disabled={brandingSaving}
            pickerLabel={t('colorPickerLabel')}
            autoFixLabel={t('contrastAutoFixAction')}
            autoFixNotice={t('contrastAutoFixNotice')}
          />
          <ColorField
            testId="admin-secondary-color"
            swatchRole="fill"
            label={t('secondaryColorLabel')}
            name="secondary_color"
            id="admin-secondary-color"
            value={secondaryColor}
            onChange={setSecondaryColor}
            placeholder="#15161a"
            error={brandingErrors.secondary_color}
            hint={
              brandingErrors.secondary_color !== undefined
                ? undefined
                : canSetSecondaryColor
                  ? t('secondaryColorHint')
                  : lockedHint('secondary_color', secondaryColor !== '')
            }
            labelEnd={
              canSetSecondaryColor ? undefined : (
                <PlanUpgradeLink
                  label={t('planUpgradeLinkLabel')}
                  feature="secondary_color"
                  requiredPlan={requiredFor('secondary_color')}
                />
              )
            }
            disabled={brandingSaving || !canSetSecondaryColor}
            pickerLabel={t('colorPickerLabel')}
            autoFixLabel={t('contrastAutoFixAction')}
            autoFixNotice={t('contrastAutoFixNotice')}
          />
        </div>
      ),
    },
    {
      id: 'images',
      icon: <ImageIcon size={16} />,
      label: t('builderGroupLogo'),
      badge: canSetLogo ? (coverLocked ? 'lite' : null) : requiredFor('logo_url'),
      dirty: groupDirty.images,
      error: groupErrors.images ?? null,
      content: (
        <div className="flex flex-col gap-ds-lg">
          <LogoField
            onPendingChange={onLogoPending}
            testId="admin-logo"
            boardSlug={boardSlug ?? ''}
            label={t('logoUrlLabel')}
            assets={assets}
            legacyUrl={logoUrl}
            onLegacyClear={() => setLogoUrl('')}
            allowed={canSetLogo}
            requiredPlan={requiredFor('logo_url')}
            error={brandingErrors.logo_url}
            disabled={brandingSaving}
            maxBytes={assets.data?.max_upload_bytes_by_kind?.logo ?? 2 * 1024 * 1024}
          />
          <LogoField
            kind="wordmark"
            onPendingChange={onWordmarkPending}
            testId="admin-wordmark"
            boardSlug={boardSlug ?? ''}
            label={t('wordmarkLabel')}
            assets={assets}
            legacyUrl=""
            onLegacyClear={() => undefined}
            allowed={canSetLogo}
            requiredPlan={requiredFor('logo_url')}
            disabled={brandingSaving}
            maxBytes={assets.data?.max_upload_bytes_by_kind?.wordmark ?? 2 * 1024 * 1024}
            barColor={topbarBarColor(canSetSecondaryColor ? secondaryColor : null)}
          />
          <LogoField
            kind="wordmark_light"
            onPendingChange={onWordmarkLightPending}
            testId="admin-wordmark-light"
            boardSlug={boardSlug ?? ''}
            label={t('wordmarkLightLabel')}
            assets={assets}
            legacyUrl=""
            onLegacyClear={() => undefined}
            allowed={canSetLogo}
            requiredPlan={requiredFor('logo_url')}
            disabled={brandingSaving}
            maxBytes={assets.data?.max_upload_bytes_by_kind?.wordmark_light ?? 2 * 1024 * 1024}
          />
          <CoverPatternPicker
            testId="admin-cover-pattern"
            value={coverPattern}
            onChange={setCoverPattern}
            allowed={allowedBrandingFields.includes('cover_pattern')}
            requiredPlan={requiredFor('cover_pattern')}
            disabled={brandingSaving}
          />
          <BoardImageFields
            boardSlug={boardSlug ?? ''}
            assets={assets}
            disabled={brandingSaving}
            onPendingChange={setPendingImage}
          />
        </div>
      ),
    },
    {
      id: 'texts',
      icon: <Type size={16} />,
      label: t('builderGroupTexts'),
      badge: canSetIntro ? proTier('custom_wording') : requiredFor('intro'),
      dirty: groupDirty.texts,
      error: groupErrors.texts ?? null,
      content: (
        <div className="flex flex-col gap-ds-lg">
          {/* Same state and API as the Allgemein tab (renameName, PUT /admin/boards/{slug}). */}
          <TextField
            className="max-w-ds-field"
            testId="admin-editor-board-name"
            label={t('boardNameLabel')}
            name="name"
            id="admin-editor-board-name"
            value={renameName}
            onChange={(e) => setRenameName(e.target.value)}
            error={renameErrors.name}
            disabled={brandingSaving}
            autoComplete="off"
          />
          <TextArea
            className="max-w-ds-field-long"
            testId="admin-intro"
            label={t('introLabel')}
            name="intro"
            id="admin-intro"
            value={intro}
            onChange={(e) => setIntro(e.target.value)}
            placeholder={t('introPlaceholder')}
            error={brandingErrors.intro}
            hint={
              brandingErrors.intro !== undefined
                ? undefined
                : canSetIntro
                  ? undefined
                  : lockedHint('intro', intro !== '')
            }
            labelEnd={
              canSetIntro ? undefined : (
                <PlanUpgradeLink
                  label={t('planUpgradeLinkLabel')}
                  feature="intro"
                  requiredPlan={requiredFor('intro')}
                />
              )
            }
            disabled={brandingSaving || !canSetIntro}
            rows={3}
          />
          <ProWordingFields pro={pro} disabled={brandingSaving} />
        </div>
      ),
    },
    {
      id: 'links',
      icon: <Link2 size={16} />,
      label: t('builderGroupLinks'),
      badge: proTier('header_links_sections'),
      dirty: groupDirty.links,
      error: groupErrors.links ?? null,
      content: <ProLinkFields pro={pro} disabled={brandingSaving} />,
    },
    {
      id: 'advanced',
      icon: <Settings2 size={16} />,
      label: t('builderGroupAdvanced'),
      badge: canHideBadge ? proTier('typography_presets') : requiredFor('hide_badge'),
      dirty: groupDirty.advanced,
      error: groupErrors.advanced ?? null,
      content: (
        <div className="flex flex-col gap-ds-lg">
          <Checkbox
            testId="admin-hide-badge"
            label={t('hideBadgeLabel')}
            id="admin-hide-badge"
            name="hide_badge"
            checked={hideBadge}
            onChange={setHideBadge}
            disabled={brandingSaving || !canHideBadge}
            hint={
              brandingErrors.hide_badge ??
              (canHideBadge ? t('hideBadgeHint') : lockedHint('hide_badge', hideBadge))
            }
            labelEnd={
              canHideBadge ? undefined : (
                <PlanUpgradeLink
                  label={t('planUpgradeLinkLabel')}
                  feature="hide_badge"
                  requiredPlan={requiredFor('hide_badge')}
                />
              )
            }
          />

          <Select
            testId="admin-visibility"
            label={t('visibilityLabel')}
            id="admin-visibility"
            name="visibility"
            value={visibility}
            onChange={(v) => {
              const next = v as BoardVisibility
              if (next === 'public' && visibility !== 'public') {
                setPendingVisibility(next)
                return
              }
              setVisibility(next)
            }}
            disabled={brandingSaving || visibilityLocked}
            error={brandingErrors.visibility}
            hint={
              brandingErrors.visibility !== undefined
                ? undefined
                : visibilityLocked
                  ? t('visibilityUpgradeHint')
                  : undefined
            }
            labelEnd={
              visibilityLocked ? <PlanUpgradeLink label={t('planUpgradeLinkLabel')} /> : undefined
            }
            className="max-w-ds-field"
          >
            <option value="public" disabled={!allowedVisibilities.includes('public')}>
              {t('visibilityPublic')}
            </option>
            <option value="unlisted" disabled={!allowedVisibilities.includes('unlisted')}>
              {t('visibilityUnlisted')}
            </option>
            <option value="private" disabled={!allowedVisibilities.includes('private')}>
              {t('visibilityPrivate')}
            </option>
          </Select>

          <ProPresetFields pro={pro} disabled={brandingSaving} />

          <BoardLinkFooterFields
            value={linkState}
            onChange={setLinkState}
            errors={linkErrors}
            locked={linkLocked}
            // Editable only once the stored values are in, so an early edit cannot be overwritten.
            disabled={brandingSaving || linkBaseline === null}
          />
        </div>
      ),
    },
  ]

  if (mode === 'design') {
    return frame(
      <BrandingBuilder
        boardName={renameName}
        boardUrl={boardSlug ? fullBoardUrl(boardSlug) : undefined}
        previewLanguage={pro.data !== null ? pro.locale : undefined}
        values={{
          primary: primaryColor,
          secondary: secondaryColor,
          logo:
            pendingImages.logo ??
            (assets.data?.variants?.logo
              ? boardAssetUrl(boardSlug ?? '', assets.data.variants.logo.id)
              : logoUrl),
          wordmark:
            pendingImages.wordmark ??
            (assets.data?.variants?.wordmark
              ? boardAssetUrl(boardSlug ?? '', assets.data.variants.wordmark.id)
              : null),
          wordmarkInk: assets.data?.variants?.wordmark?.ink ?? null,
          wordmarkLight:
            pendingImages.wordmark_light ??
            (assets.data?.variants?.wordmark_light
              ? boardAssetUrl(boardSlug ?? '', assets.data.variants.wordmark_light.id)
              : null),
          wordmarkLightInk: assets.data?.variants?.wordmark_light?.ink ?? null,
          intro,
          hideBadge,
          coverSrc:
            pendingImages.cover ??
            (coverId !== undefined && boardSlug ? boardAssetUrl(boardSlug, coverId) : null),
          faviconSrc:
            pendingImages.favicon ??
            (assets.data?.variants?.favicon && boardSlug
              ? boardAssetUrl(boardSlug, assets.data.variants.favicon.id)
              : null),
          ogSrc:
            pendingImages.og ??
            (assets.data?.variants?.og && boardSlug
              ? boardAssetUrl(boardSlug, assets.data.variants.og.id)
              : null),
          typography: pro.form?.typography_preset,
          shape: pro.form?.shape_style,
          density: pro.form?.density,
          coverPattern,
          themeMode,
          wording: pro.form?.wording[pro.locale] ?? null,
          backLink: { label: linkState.back_link_label, url: linkState.back_link_url },
          footerNote: linkState.footer_note,
          roadmapHidden: pro.form?.roadmap_hidden ?? false,
          headerLinkLabels:
            linksOn && pro.form !== null
              ? pro.form.header_links.map((row) => row.label.trim()).filter((label) => label !== '')
              : [],
          voteWords,
        }}
        plan={{
          secondaryAllowed: canSetSecondaryColor,
          logoAllowed: canSetLogo,
          introAllowed: canSetIntro,
          badgeHideAllowed: canHideBadge,
          secondaryRequiredPlan: requiredFor('secondary_color'),
          logoRequiredPlan: requiredFor('logo_url'),
          gates: {
            presets: {
              allowed: pro.data !== null && proTier('typography_presets') === null,
              requiredPlan: requiredFor('typography_presets'),
            },
            wording: { allowed: wordingOn, requiredPlan: requiredFor('custom_wording') },
            pattern: {
              allowed: allowedBrandingFields.includes('cover_pattern'),
              requiredPlan: requiredFor('cover_pattern'),
            },
            cover: { allowed: !coverLocked, requiredPlan: requiredFor('cover_upload') },
            links: { allowed: !linkLocked, requiredPlan: requiredFor('back_link_footer') },
            roadmap: { allowed: linksOn, requiredPlan: requiredFor('header_links_sections') },
            images: {
              allowed: assets.data?.allowed.favicon_og === true,
              requiredPlan: requiredFor('favicon_og'),
            },
          },
        }}
        dirty={anyGroupDirty}
        saving={brandingSaving}
        frozen={boardFrozen}
        onSave={() =>
          (
            document.getElementById('admin-branding-form') as HTMLFormElement | null
          )?.requestSubmit()
        }
        onDiscard={discardBrandingChanges}
        onBack={() => navigate(accountPath(`/admin/boards/${boardSlug ?? ''}`))}
        onSubmit={handleBrandingSave}
        groups={brandingGroups}
        initialGroup={initialGroup}
      >
        <ConfirmDialog
          testId="admin-visibility-confirm"
          open={pendingVisibility !== null}
          title={t('visibilityConfirmTitle')}
          description={t('visibilityConfirmBody')}
          confirmLabel={t('visibilityConfirmAction')}
          cancelLabel={tCommon('action.cancel')}
          onConfirm={() => {
            if (pendingVisibility !== null) setVisibility(pendingVisibility)
            setPendingVisibility(null)
          }}
          onCancel={() => setPendingVisibility(null)}
        />

        {brandingGeneralError !== null && (
          <InlineNotice tone="error" testId="admin-branding-error">
            {brandingGeneralError}
          </InlineNotice>
        )}
        {brandingSuccess && (
          <InlineNotice tone="success" testId="admin-branding-success">
            {t('brandingSaved')}
          </InlineNotice>
        )}
        {boardFrozen && (
          <div className="flex flex-col items-start gap-ds-sm">
            <InlineNotice tone="warning" role="alert" testId="admin-frozen-notice">
              {t('frozenNotice')}
            </InlineNotice>
            {visibility !== 'private' && (
              <Button
                variant="secondary"
                onClick={handleMakePrivate}
                disabled={brandingSaving}
                loading={brandingSaving}
                testId="admin-frozen-make-private"
              >
                {t('frozenMakePrivateAction')}
              </Button>
            )}
          </div>
        )}
      </BrandingBuilder>,
    )
  }

  return frame(
    <>
      <PageHeader
        eyebrow={tCommon('header.scopeAdmin')}
        title={t('title', { boardName: pageState.boardName })}
        description={t('subtitle')}
        back={
          <Breadcrumbs
            testId="admin-breadcrumbs"
            ariaLabel={tCommon('breadcrumb.ariaLabel')}
            items={[
              { label: tCommon('header.boardsAdmin'), href: accountPath('/admin/boards') },
              { label: boardName },
            ]}
          />
        }
        actions={
          <ButtonLink
            href={`${accountPath(`/${boardSlug ?? ''}`)}?view=voter`}
            linkAs={ButtonRouterLink}
            testId="admin-view-board"
            variant="secondary"
            icon="external"
          >
            {t('viewBoard')}
          </ButtonLink>
        }
      >
        {boardFrozen && (
          <div className="flex flex-col items-start gap-ds-sm">
            <InlineNotice tone="warning" role="alert" testId="admin-frozen-notice">
              {t('frozenNotice')}
            </InlineNotice>
            {visibility !== 'private' && (
              <Button
                variant="secondary"
                onClick={handleMakePrivate}
                disabled={brandingSaving}
                loading={brandingSaving}
                testId="admin-frozen-make-private"
              >
                {t('frozenMakePrivateAction')}
              </Button>
            )}
          </div>
        )}
        {boardSlug !== undefined && qrAllowedFor(savedVisibility) ? (
          <BoardQrPanel
            url={fullBoardUrl(boardSlug)}
            fileName={boardSlug}
            posterHref={accountPath(`/admin/boards/${boardSlug}/poster`)}
            renderField={(qrToggle) => (
              <CopyField
                label={t('boardLinkLabel')}
                value={fullBoardUrl(boardSlug)}
                copyLabel={tCommon('action.copy')}
                copiedLabel={tCommon('action.copied')}
                testId="admin-board-link-copy"
                mono
                actions={qrToggle}
                className="max-w-ds-field-long"
              />
            )}
          />
        ) : (
          <CopyField
            label={t('boardLinkLabel')}
            value={fullBoardUrl(boardSlug ?? '')}
            copyLabel={tCommon('action.copy')}
            copiedLabel={tCommon('action.copied')}
            testId="admin-board-link-copy"
            mono
            className="max-w-ds-field-long"
          />
        )}
      </PageHeader>

      <fieldset disabled={boardFrozen} className="m-0 flex min-w-0 flex-col gap-ds-lg border-0 p-0">
        {/* ── General (title/slug rename + translation/anonymous-voting
          toggles) — ONE save button for the whole section (live-tested UX
          feedback: previously a second, separate "Speichern" button sat
          next to the toggles, confusing which button saved what). ────── */}
        <Section
          testId="admin-general-section"
          title={t('generalHeading')}
          icon={<Settings size={16} />}
          description={t('generalSubtitle')}
          emphasis="ruled"
          footer={
            <Button
              testId="admin-general-save"
              type="submit"
              form="admin-general-form"
              variant="primary"
              disabled={generalSaving}
              loading={generalSaving}
            >
              {generalSaving ? t('savingEllipsis') : t('generalSubmit')}
            </Button>
          }
        >
          <form id="admin-general-form" onSubmit={handleGeneralSave} noValidate>
            <div className="flex flex-col gap-ds-md">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-ds-md">
                <TextField
                  className="max-w-ds-field"
                  testId="admin-rename-name"
                  label={t('boardNameLabel')}
                  name="name"
                  id="admin-rename-name"
                  value={renameName}
                  onChange={(e) => setRenameName(e.target.value)}
                  error={renameErrors.name}
                  disabled={generalSaving}
                  autoComplete="off"
                />

                <TextField
                  className="max-w-ds-field"
                  testId="admin-rename-slug"
                  label={t('boardSlugLabel')}
                  name="slug"
                  id="admin-rename-slug"
                  value={renameSlug}
                  onChange={(e) => setRenameSlug(e.target.value)}
                  mono
                  error={renameErrors.slug}
                  hint={renameErrors.slug !== undefined ? undefined : t('boardSlugHint')}
                  hintPlacement="below"
                  disabled={generalSaving}
                  autoComplete="off"
                />
              </div>
            </div>
          </form>

          <div className="flex flex-col gap-ds-md mt-ds-md pt-ds-md border-t border-vp-border-subtle">
            {translationAvailable && (
              <Checkbox
                testId="admin-translation-enabled"
                id="admin-translation-enabled"
                label={t('translationEnabledLabel')}
                checked={translationEnabled}
                onChange={setTranslationEnabled}
                disabled={generalSaving}
                hint={t('translationEnabledHint')}
              />
            )}
            <Checkbox
              testId="admin-anonymous-voting-enabled"
              id="admin-anonymous-voting-enabled"
              label={t('anonymousVotingEnabledLabel')}
              checked={anonymousVotingEnabled}
              onChange={setAnonymousVotingEnabled}
              disabled={generalSaving}
              hint={t('anonymousVotingEnabledHint')}
            />
            {getFeatures().vote_reasons !== false && (
              <Checkbox
                testId="admin-vote-reasons-enabled"
                id="admin-vote-reasons-enabled"
                label={tVoteReasons('enabledLabel')}
                checked={voteReasonsEnabled}
                onChange={setVoteReasonsEnabled}
                disabled={generalSaving}
                hint={tVoteReasons('enabledHint')}
              />
            )}
            <Checkbox
              testId="admin-status-reason-required"
              id="admin-status-reason-required"
              label={t('statusReasonRequiredLabel')}
              checked={statusReasonRequired}
              onChange={setStatusReasonRequired}
              disabled={generalSaving}
              hint={t('statusReasonRequiredHint')}
            />
            {duplicateConfirmation !== null && (
              <div data-testid="admin-ai-duplicates" className="flex flex-col gap-ds-2xs">
                <span className="text-vp-sm font-medium text-vp-ink">{t('aiDuplicatesLabel')}</span>
                <span className="text-vp-xs text-vp-text-muted">
                  {duplicateConfirmation === 'available' ? (
                    t('aiDuplicatesActive')
                  ) : duplicateConfirmation === 'disabled' ? (
                    t('aiDuplicatesDisabled')
                  ) : (
                    <>
                      {t('aiDuplicatesUpgrade')}{' '}
                      <PlanUpgradeLink label={t('planUpgradeLinkLabel')} />
                    </>
                  )}
                </span>
              </div>
            )}
          </div>

          {(generalGeneralError !== null || generalSuccess) && (
            <div className="mt-ds-md flex flex-col gap-ds-sm">
              {generalGeneralError !== null && (
                <InlineNotice tone="error" testId="admin-general-error">
                  {generalGeneralError}
                </InlineNotice>
              )}
              {generalSuccess && (
                <InlineNotice tone="success" testId="admin-general-success">
                  {t('boardSettingsSaved')}
                </InlineNotice>
              )}
            </div>
          )}
        </Section>

        <ConfirmDialog
          testId="admin-slug-confirm"
          open={slugConfirmOpen}
          title={t('slugConfirmTitle')}
          description={t('slugConfirmBody', { oldSlug: boardSlug ?? '', newSlug: renameSlug })}
          confirmLabel={t('slugConfirmAction')}
          cancelLabel={tCommon('action.cancel')}
          onConfirm={() => {
            setSlugConfirmOpen(false)
            void saveGeneral()
          }}
          onCancel={() => setSlugConfirmOpen(false)}
        />

        {/* ── Branding: compact card, the editor is a dedicated route ───── */}
        <Section
          testId="admin-branding-section"
          title={t('brandingHeading')}
          icon={<Palette size={16} />}
          description={t('brandingSubtitle')}
          emphasis="ruled"
        >
          <div className="flex flex-col gap-ds-md">
            <BrandingPreview
              compact
              boardName={boardName ?? ''}
              primaryColor={primaryColor}
              secondaryColor={secondaryColor}
              logoUrl={logoUrl}
              secondaryAllowed={canSetSecondaryColor}
              introText={intro}
              introAllowed={canSetIntro}
              hideBadge={hideBadge}
              badgeHideAllowed={canHideBadge}
            />
            <ButtonLink
              href={accountPath(`/admin/boards/${boardSlug ?? ''}/design`)}
              linkAs={ButtonRouterLink}
              testId="admin-branding-open-editor"
              variant="primary"
              className="self-start"
            >
              {t('builderOpenEditor')}
            </ButtonLink>
          </div>
        </Section>

        {/* ── Board-operator acknowledgment (Cloud-only) ──────────────────── */}
        {isCloud && (
          <Section testId="admin-operator-ack-section" title={t('operatorAckHeading')}>
            <div className="flex flex-col gap-ds-md">
              {operatorAcknowledged ? (
                <InlineNotice tone="success" testId="admin-operator-ack-done">
                  {operatorAcknowledgedAt !== null
                    ? t('operatorAckDoneAt', {
                        date: formatDateTime(operatorAcknowledgedAt, language),
                      })
                    : t('operatorAckDone')}
                </InlineNotice>
              ) : (
                <>
                  <p className="text-vp-sm text-vp-text-muted">{t('operatorAckMissingBody')}</p>
                  <Button
                    testId="admin-operator-ack-action"
                    type="button"
                    onClick={() => void handleOperatorAcknowledge()}
                    disabled={operatorAckSaving}
                    loading={operatorAckSaving}
                  >
                    {operatorAckSaving ? t('operatorAckSaving') : t('operatorAckAction')}
                  </Button>
                  {operatorAckError !== null && (
                    <InlineNotice tone="error" testId="admin-operator-ack-error">
                      {operatorAckError}
                    </InlineNotice>
                  )}
                </>
              )}
            </div>
          </Section>
        )}

        {/* ── Statuses (issue 03) ───────────────────────────────────────── */}
        {boardSlug && <StatusSettings boardSlug={boardSlug} />}

        {/* ── Deadlines (issue 04) ──────────────────────────────────────── */}
        {boardSlug && <PhaseSettings boardSlug={boardSlug} />}

        {/* ── Results export (issue 07) ─────────────────────────────────── */}
        {boardSlug && <ResultsExport boardSlug={boardSlug} />}

        {/* ── Moderation ────────────────────────────────────────────────── */}
        <Section
          testId="admin-moderation-section"
          id="board-moderation"
          title={t('moderationHeading')}
          icon={<ShieldBan size={16} />}
          description={t('moderationSubtitle')}
          flush
        >
          <div className="ds-section__inset flex flex-wrap items-center justify-between gap-ds-sm border-b border-vp-border-subtle">
            {/* Saves on click, like the other moderation switches below (no Speichern). */}
            <Checkbox
              testId="admin-mod-toggle"
              id="admin-mod-toggle"
              label={t('moderationToggleLabel')}
              checked={modEnabled}
              onChange={(next) => void handleModerationToggle(next)}
              disabled={modSaving}
            />
          </div>

          {/* Pre-moderation (issue 05): approve new ideas before they appear. */}
          <div className="ds-section__inset flex flex-wrap items-center justify-between gap-ds-sm border-b border-vp-border-subtle">
            <div className="flex flex-col gap-ds-2xs">
              <Checkbox
                testId="admin-premoderation-toggle"
                id="admin-premoderation-toggle"
                label={t('premoderationToggleLabel')}
                checked={premoderationMode === 'all'}
                onChange={handlePremoderationChange}
                disabled={modSaving}
                hint={t('premoderationHint')}
              />
              {boardSlug && (premoderationMode !== 'off' || pendingCount > 0) && (
                <Link
                  to={accountPath(`/admin/boards/${boardSlug}/queue`)}
                  className="inline-flex items-center gap-ds-xs text-vp-sm underline underline-offset-2 hover:text-vp-ink"
                >
                  <Hourglass size={14} aria-hidden="true" />
                  {t('premoderationQueueLink')}
                  <Badge tone={pendingCount > 0 ? 'warning' : 'neutral'}>
                    {t('premoderationPendingBadge', { count: pendingCount })}
                  </Badge>
                </Link>
              )}
            </div>
          </div>

          {/* Ideas without an account (ADR 0006): off by default; needs
            Turnstile on the installation and a plan that includes it. Hidden
            while the installation's kill switch is off — unless a board still
            has it on, so the owner can switch it off. */}
          {guestSupported && (guestInstanceAllowed || guestEnabled) && (
            <div className="ds-section__inset flex flex-col gap-ds-xs border-b border-vp-border-subtle">
              <Checkbox
                testId="admin-anon-submission-toggle"
                id="admin-anon-submission-toggle"
                label={t('guestSubmissionToggleLabel')}
                checked={guestEnabled}
                onChange={(next) => void handleGuestSubmissionChange(next)}
                // Switching off always works (kill switch), even after a downgrade.
                disabled={
                  modSaving ||
                  (!guestEnabled && (!guestInstanceAllowed || !guestTurnstile || !guestPlanAllowed))
                }
                hint={t('guestSubmissionHint')}
              />
              {!guestInstanceAllowed && (
                <p className="text-vp-xs text-vp-text-muted text-pretty">
                  {t('guestSubmissionInstanceOff')}
                </p>
              )}
              {guestInstanceAllowed && !guestTurnstile && (
                <p className="text-vp-xs text-vp-text-muted text-pretty">
                  {t('guestSubmissionNeedsTurnstile')}
                </p>
              )}
              {guestInstanceAllowed && !guestPlanAllowed && (
                <p className="text-vp-xs text-vp-text-muted text-pretty">
                  {t('guestSubmissionPlanHint')}{' '}
                  <PlanUpgradeLink label={t('planUpgradeLinkLabel')} />
                </p>
              )}
              {guestEnabled && (
                <>
                  <Checkbox
                    testId="admin-premoderation-guest-toggle"
                    id="admin-premoderation-guest-toggle"
                    label={t('guestReviewToggleLabel')}
                    checked={premoderationMode !== 'off'}
                    onChange={handleGuestReviewChange}
                    // "Approve all" already includes guest ideas.
                    disabled={modSaving || premoderationMode === 'all'}
                  />
                  {premoderationMode === 'off' && (
                    <InlineNotice tone="warning" testId="admin-guest-review-warning">
                      {t('guestReviewOffWarning')}
                    </InlineNotice>
                  )}
                </>
              )}
              <ConfirmDialog
                testId="admin-guest-public-confirm"
                open={guestPublicConfirmOpen}
                tone="danger"
                title={t('guestPublicConfirmTitle')}
                description={t('guestPublicConfirmBody')}
                confirmLabel={t('guestPublicConfirmAction')}
                cancelLabel={tCommon('action.cancel')}
                onConfirm={() => {
                  setGuestPublicConfirmOpen(false)
                  void savePremoderationMode('off', true)
                }}
                onCancel={() => setGuestPublicConfirmOpen(false)}
              />
            </div>
          )}

          <div className="ds-section__inset">
            <InlineNotice tone="info" testId="admin-base-lists">
              <span className="inline-flex items-center gap-ds-xs">
                <ShieldCheck size={14} aria-hidden="true" className="shrink-0" />
                {baseLists
                  ? t('baseListsInfo', {
                      de: baseLists.de,
                      en: baseLists.en,
                      total: baseLists.de + baseLists.en,
                    })
                  : t('baseListsInfoLoading')}
              </span>
            </InlineNotice>
          </div>

          <div className="ds-section__inset">
            <h3 className="vp-eyebrow mb-ds-xs">
              {t('blocklistHeading', { count: words.length })}
            </h3>
            {words.length > 0 ? (
              <ul
                className="flex flex-wrap gap-ds-xs list-none m-0 p-0"
                aria-label={t('blocklistAriaLabel')}
              >
                {words.map((w) => (
                  <li key={w.id} className="inline-flex items-center gap-ds-2xs">
                    <Badge tone="neutral" size="md">
                      <span className="font-mono-num">{w.word}</span>
                    </Badge>
                    <IconButton
                      testId={`admin-word-remove-${w.id}`}
                      icon="close"
                      accessibleLabel={t('removeWordAriaLabel', { word: w.word })}
                      onClick={() => void handleRemoveWord(w.id)}
                      disabled={modSaving}
                    />
                  </li>
                ))}
              </ul>
            ) : (
              <EmptyState
                kind="no-ideas"
                variant="plain"
                testId="admin-blocklist-empty"
                title={t('emptyBlocklist')}
              />
            )}
          </div>

          <div className="ds-section__inset">
            <Button
              testId="admin-bulk-toggle"
              type="button"
              variant="text"
              onClick={() => {
                setBulkMode((prev) => !prev)
                setModErrors({})
              }}
            >
              {bulkMode ? t('switchToSingleWord') : t('switchToBulkAdd')}
            </Button>
          </div>

          {bulkMode ? (
            <form
              onSubmit={handleBulkAddWords}
              noValidate
              className="ds-section__inset flex flex-col gap-ds-sm"
              aria-label={t('bulkAddFormAriaLabel')}
            >
              <TextArea
                className="max-w-ds-field-long"
                testId="admin-bulk-words"
                label={t('bulkAddLabel')}
                name="words_blob"
                id="admin-bulk-words"
                value={bulkWords}
                onChange={(e) => setBulkWords(e.target.value)}
                placeholder={t('bulkAddPlaceholder')}
                hint={t('bulkAddHint')}
                error={modErrors.words_blob}
                disabled={modSaving}
                rows={5}
              />
              <div>
                <Button
                  testId="admin-bulk-submit"
                  type="submit"
                  variant="secondary"
                  disabled={modSaving || bulkWords.trim() === ''}
                  loading={modSaving}
                >
                  {t('bulkAddButton')}
                </Button>
              </div>
            </form>
          ) : (
            <form
              onSubmit={handleAddWord}
              noValidate
              className="ds-section__inset flex flex-col sm:flex-row sm:items-end gap-ds-sm"
              aria-label={t('addWordFormAriaLabel')}
            >
              <div className="flex-1 sm:max-w-ds-field">
                <TextField
                  className="max-w-ds-field"
                  testId="admin-new-word"
                  label={t('newWordLabel')}
                  name="new_word"
                  id="admin-new-word"
                  value={newWord}
                  onChange={(e) => setNewWord(e.target.value)}
                  placeholder={t('newWordPlaceholder')}
                  error={modErrors.new_word}
                  disabled={modSaving}
                  autoComplete="off"
                />
              </div>
              <div>
                <Button
                  testId="admin-add-word"
                  type="submit"
                  variant="secondary"
                  disabled={modSaving || newWord.trim() === ''}
                  loading={modSaving}
                >
                  {t('addButton')}
                </Button>
              </div>
            </form>
          )}

          {(modGeneralError !== null || modSuccess !== null) && (
            <div className="ds-section__inset flex flex-col gap-ds-sm">
              {modGeneralError !== null && (
                <InlineNotice tone="error" testId="admin-mod-error">
                  {modGeneralError}
                </InlineNotice>
              )}
              {modSuccess !== null && (
                <InlineNotice tone="success" testId="admin-mod-success">
                  {modSuccess}
                </InlineNotice>
              )}
            </div>
          )}
        </Section>

        {/* ── SMTP ──────────────────────────────────────────────────────── */}
        {boardSmtpEnabled && (
          <Section
            testId="admin-smtp-section"
            title={t('smtpHeading')}
            icon={<Mail size={16} />}
            description={
              <>
                {t('smtpIntroPrefix')} <strong>{t('smtpIntroStrong')}</strong>{' '}
                {t('smtpIntroSuffix')}
              </>
            }
            footer={
              <div className="w-full flex flex-col gap-ds-md">
                <div className="sm:max-w-ds-field">
                  <TextField
                    className="max-w-ds-field"
                    testId="admin-smtp-test-to"
                    label={t('smtpTestToLabel')}
                    name="smtp_test_to"
                    id="admin-smtp-test-to"
                    type="email"
                    value={smtpTestTo}
                    onChange={(e) => setSmtpTestTo(e.target.value)}
                    placeholder="name@example.com"
                    hint={t('smtpTestToHint')}
                    disabled={smtpTestSending}
                    autoComplete="off"
                  />
                </div>

                {smtpTestResult !== null && (
                  <InlineNotice
                    tone={smtpTestResult.ok ? 'success' : 'error'}
                    role="status"
                    testId="admin-smtp-test-result"
                  >
                    {smtpTestResult.message}
                  </InlineNotice>
                )}

                <div className="flex flex-wrap gap-ds-sm">
                  <Button
                    testId="admin-smtp-save"
                    type="submit"
                    form="admin-smtp-form"
                    variant="primary"
                    disabled={smtpSaving || smtpTestSending}
                    loading={smtpSaving}
                  >
                    {smtpSaving ? t('savingEllipsis') : t('smtpSubmit')}
                  </Button>
                  <Button
                    testId="admin-smtp-test"
                    type="button"
                    variant="secondary"
                    onClick={() => void handleSmtpTest()}
                    disabled={smtpSaving || smtpTestSending}
                    loading={smtpTestSending}
                  >
                    {smtpTestSending ? t('smtpTestSending') : t('smtpTestSubmit')}
                  </Button>
                </div>
              </div>
            }
          >
            <div className="mb-ds-md">
              {smtpUsesGlobalDefault ? (
                <InlineNotice tone="info" role="none" testId="admin-smtp-global-default">
                  {t('smtpUsesGlobalPrefix')} <strong>{t('smtpUsesGlobalStrong')}</strong>
                  {t('smtpUsesGlobalSuffix')}
                </InlineNotice>
              ) : (
                <InlineNotice
                  tone="success"
                  role="none"
                  testId="admin-smtp-custom"
                  action={
                    <Button
                      testId="admin-smtp-reset"
                      type="button"
                      variant="secondary"
                      onClick={() => void handleSmtpReset()}
                      disabled={smtpSaving}
                    >
                      {t('smtpResetToDefault')}
                    </Button>
                  }
                >
                  {t('smtpCustomActive')}
                </InlineNotice>
              )}
            </div>

            <form id="admin-smtp-form" onSubmit={handleSmtpSave} noValidate>
              <div className="flex flex-col gap-ds-md">
                <Select
                  testId="admin-smtp-preset"
                  label={t('smtpPresetLabel')}
                  id="smtp-preset"
                  value={smtpPreset}
                  onChange={(v) => handlePresetChange(v as SmtpPreset)}
                  className="max-w-ds-field"
                >
                  <option value="custom">{t('smtpPresetCustom')}</option>
                  <option value="outlook">{t('smtpPresetOutlook')}</option>
                  <option value="gmail">{t('smtpPresetGmail')}</option>
                </Select>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-ds-md">
                  <div className="sm:col-span-2">
                    <TextField
                      className="max-w-ds-field"
                      testId="admin-smtp-host"
                      label={t('smtpHostLabel')}
                      name="smtp_host"
                      id="smtp-host"
                      value={smtpHost}
                      onChange={(e) => setSmtpHost(e.target.value)}
                      placeholder="smtp.example.com"
                      mono
                      error={smtpErrors.host}
                      disabled={smtpSaving}
                      autoComplete="off"
                    />
                  </div>
                  <TextField
                    className="max-w-ds-field"
                    testId="admin-smtp-port"
                    label={t('smtpPortLabel')}
                    name="smtp_port"
                    id="smtp-port"
                    value={String(smtpPort)}
                    onChange={(e) => setSmtpPort(Number(e.target.value))}
                    placeholder="587"
                    mono
                    inputMode="numeric"
                    error={smtpErrors.port}
                    disabled={smtpSaving}
                    autoComplete="off"
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-ds-md">
                  <TextField
                    className="max-w-ds-field"
                    testId="admin-smtp-user"
                    label={t('smtpUserLabel')}
                    name="smtp_user"
                    id="smtp-user"
                    value={smtpUser}
                    onChange={(e) => setSmtpUser(e.target.value)}
                    placeholder="user@example.com"
                    error={smtpErrors.user}
                    disabled={smtpSaving}
                    autoComplete="username"
                  />
                  <Select
                    className="max-w-ds-field"
                    testId="admin-smtp-encryption"
                    label={t('smtpEncryptionLabel')}
                    id="smtp-encryption"
                    value={smtpEncryption}
                    onChange={(v) => setSmtpEncryption(v as SmtpEncryption)}
                    disabled={smtpSaving}
                  >
                    <option value="tls">{t('smtpEncryptionTls')}</option>
                    <option value="ssl">{t('smtpEncryptionSsl')}</option>
                    <option value="">{t('smtpEncryptionNone')}</option>
                  </Select>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-ds-md">
                  <TextField
                    className="max-w-ds-field"
                    testId="admin-smtp-from-email"
                    label={t('smtpFromEmailLabel')}
                    name="smtp_from_email"
                    id="smtp-from-email"
                    value={smtpFromEmail}
                    onChange={(e) => setSmtpFromEmail(e.target.value)}
                    placeholder="noreply@example.com"
                    error={smtpErrors.from_email}
                    hint={smtpErrors.from_email !== undefined ? undefined : t('smtpFromEmailHint')}
                    disabled={smtpSaving}
                    autoComplete="email"
                  />
                  <TextField
                    className="max-w-ds-field"
                    testId="admin-smtp-from-name"
                    label={t('smtpFromNameLabel')}
                    name="smtp_from_name"
                    id="smtp-from-name"
                    value={smtpFromName}
                    onChange={(e) => setSmtpFromName(e.target.value)}
                    placeholder="Votepit"
                    error={smtpErrors.from_name}
                    disabled={smtpSaving}
                    autoComplete="off"
                  />
                </div>

                <TextField
                  className="max-w-ds-field"
                  testId="admin-smtp-password"
                  label={smtpPasswordSet ? t('smtpPasswordLabelSet') : t('smtpPasswordLabel')}
                  name="smtp_password"
                  id="smtp-password"
                  type="password"
                  value={smtpPassword}
                  onChange={(e) => setSmtpPassword(e.target.value)}
                  placeholder={
                    smtpPasswordSet ? t('smtpPasswordPlaceholderSet') : t('smtpPasswordPlaceholder')
                  }
                  error={smtpErrors.password}
                  hint={smtpErrors.password !== undefined ? undefined : t('smtpPasswordHint')}
                  disabled={smtpSaving}
                  autoComplete="new-password"
                />

                {/* Rarely needed and unsafe: collapsed unless the option is already on. */}
                <details
                  data-testid="admin-smtp-advanced"
                  open={!smtpVerifyPeer}
                  className="flex flex-col gap-ds-sm border-t border-vp-border-subtle pt-ds-md"
                >
                  <summary
                    data-testid="admin-smtp-advanced-toggle"
                    className="cursor-pointer text-vp-sm font-semibold text-vp-ink max-sm:min-h-11 max-sm:flex max-sm:items-center"
                  >
                    {t('builderGroupAdvanced')}
                  </summary>
                  <Checkbox
                    testId="admin-smtp-verify-peer"
                    id="smtp-verify-peer"
                    name="smtp_verify_peer"
                    label={t('smtpVerifyPeerLabel')}
                    hint={t('smtpVerifyPeerHint')}
                    checked={!smtpVerifyPeer}
                    onChange={(checked) => setSmtpVerifyPeer(!checked)}
                    disabled={smtpSaving}
                  />
                </details>

                {smtpGeneralError !== null && (
                  <InlineNotice tone="error" testId="admin-smtp-error">
                    {smtpGeneralError}
                  </InlineNotice>
                )}
                {smtpSuccess && (
                  <InlineNotice tone="success" testId="admin-smtp-success">
                    {t('smtpSaved')}
                  </InlineNotice>
                )}
              </div>
            </form>
          </Section>
        )}

        <Section
          testId="admin-webhook-section"
          title={t('webhookHeading')}
          icon={<Webhook size={16} />}
          description={t('webhookIntro')}
        >
          <form id="admin-webhook-form" onSubmit={handleWebhookSave}>
            <div className="flex flex-col gap-ds-md">
              <TextField
                className="max-w-ds-field-long"
                testId="admin-webhook-url"
                label={t('webhookUrlLabel')}
                name="webhook_url"
                id="webhook-url"
                type="url"
                value={webhookUrlInput}
                onChange={(e) => setWebhookUrlInput(e.target.value)}
                placeholder="https://example.com/hooks/votepit"
                mono
                error={webhookErrors.url}
                hint={webhookErrors.url !== undefined ? undefined : t('webhookUrlHint')}
                disabled={webhookSaving}
                autoComplete="off"
              />

              {webhookGeneralError !== null && (
                <InlineNotice tone="error" testId="admin-webhook-error">
                  {webhookGeneralError}
                </InlineNotice>
              )}

              {webhookRevealedSecret !== null && (
                <InlineNotice
                  tone="warning"
                  role="alert"
                  title={t('webhookSecretRevealedTitle')}
                  testId="admin-webhook-secret"
                >
                  <CopyField
                    label={t('webhookSecretFieldLabel')}
                    value={webhookRevealedSecret}
                    copyLabel={tCommon('action.copy')}
                    copiedLabel={tCommon('action.copied')}
                    testId="admin-webhook-secret-copy"
                    mono
                    className="mt-ds-xs"
                  />
                </InlineNotice>
              )}

              <div className="flex flex-wrap items-center gap-ds-sm">
                <Button
                  testId="admin-webhook-save"
                  type="submit"
                  variant="primary"
                  disabled={webhookSaving || webhookUrlInput.trim() === ''}
                  loading={webhookSaving}
                >
                  {webhookSaving
                    ? t('savingEllipsis')
                    : webhookConfigured
                      ? t('webhookRotateSubmit')
                      : t('webhookSaveSubmit')}
                </Button>

                {webhookConfigured && (
                  <>
                    <Button
                      testId="admin-webhook-toggle"
                      type="button"
                      variant="secondary"
                      onClick={() => void handleWebhookToggleActive()}
                      disabled={webhookToggling}
                      loading={webhookToggling}
                    >
                      {webhookActive ? t('webhookPause') : t('webhookResume')}
                    </Button>
                    <Button
                      testId="admin-webhook-remove"
                      type="button"
                      variant="secondary"
                      onClick={() => setWebhookDeleteConfirmOpen(true)}
                      disabled={webhookDeleting}
                    >
                      {t('webhookRemove')}
                    </Button>
                  </>
                )}

                {webhookConfigured && (
                  <Badge testId="admin-webhook-status" tone={webhookActive ? 'success' : 'neutral'}>
                    {webhookActive ? t('webhookStatusActive') : t('webhookStatusPaused')}
                  </Badge>
                )}
              </div>

              {webhookConfigured && webhookUrl !== '' && (
                <p className="text-vp-sm text-vp-text-muted break-all">
                  {t('webhookCurrentUrlLabel')} <span className="font-mono">{webhookUrl}</span>
                </p>
              )}
            </div>
          </form>

          {webhookConfigured && (
            <div className="mt-ds-md pt-ds-md border-t border-vp-border">
              <p className="text-vp-sm font-medium mb-ds-sm">{t('webhookDeliveriesHeading')}</p>
              {webhookDeliveries.length === 0 ? (
                <p className="text-vp-sm text-vp-text-muted">{t('webhookNoDeliveries')}</p>
              ) : (
                <ul className="flex flex-col gap-ds-xs">
                  {webhookDeliveries.map((d) => (
                    <li
                      key={`${d.attempted_at}-${d.event}-${d.status_code}`}
                      className="flex items-center gap-ds-sm text-vp-sm"
                    >
                      <Badge tone={d.success ? 'success' : 'danger'}>
                        {d.success ? t('webhookDeliverySuccess') : t('webhookDeliveryFailed')}
                      </Badge>
                      <span className="font-mono text-vp-text-muted">{d.event}</span>
                      {d.status_code !== null && (
                        <span className="font-mono text-vp-text-muted">{d.status_code}</span>
                      )}
                      <span className="text-vp-text-muted ml-auto">
                        {formatDateTime(d.attempted_at, language)}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </Section>

        {boardSlug && <BoardImportPanel boardSlug={boardSlug} />}

        <ConfirmDialog
          testId="admin-webhook-remove-confirm"
          open={webhookDeleteConfirmOpen}
          title={t('webhookRemoveConfirmTitle')}
          description={t('webhookRemoveConfirmBody')}
          confirmLabel={t('webhookRemove')}
          cancelLabel={tCommon('action.cancel')}
          onConfirm={() => void handleWebhookDelete()}
          onCancel={() => setWebhookDeleteConfirmOpen(false)}
          busy={webhookDeleting}
          tone="danger"
        />

        {boardSlug && <BoardChatNotificationsSection boardSlug={boardSlug} />}

        {/* ── Embeddable widget (ADR 0005) — self-contained section ─────────── */}
        {boardSlug && <WidgetSettings boardSlug={boardSlug} />}

        {/* ── Danger zone: permanent board deletion (owner-only) ──────────── */}
        {isOwner && boardSlug && (
          <Section
            testId="admin-danger-section"
            title={t('deleteBoardHeading')}
            icon={<AlertTriangle size={16} />}
            description={t('deleteBoardBody')}
            emphasis="danger"
          >
            <div className="flex flex-col gap-ds-md">
              {deleteBoardError !== null && (
                <InlineNotice tone="error" testId="admin-danger-error">
                  {deleteBoardError}
                </InlineNotice>
              )}
              {!deleteConfirmOpen ? (
                <div>
                  <Button
                    testId="admin-delete-board-open"
                    type="button"
                    variant="destructive"
                    onClick={() => setDeleteConfirmOpen(true)}
                  >
                    {t('deleteBoardButton')}
                  </Button>
                </div>
              ) : (
                <div className="flex flex-col gap-ds-md max-w-ds-field">
                  <TextField
                    className="max-w-ds-field"
                    testId="admin-delete-board-slug"
                    label={t('deleteBoardConfirmLabel', { slug: boardSlug })}
                    placeholder={t('deleteBoardConfirmPlaceholder')}
                    value={deleteSlugInput}
                    onChange={(e) => setDeleteSlugInput(e.target.value)}
                    mono
                    autoComplete="off"
                  />
                  <div className="flex flex-wrap gap-ds-sm">
                    <Button
                      testId="admin-delete-board-confirm"
                      type="button"
                      variant="destructive"
                      onClick={() => void handleDeleteBoard()}
                      disabled={deletingBoard || deleteSlugInput !== boardSlug}
                      loading={deletingBoard}
                    >
                      {deletingBoard ? t('deletingBoard') : t('deleteBoardConfirmSubmit')}
                    </Button>
                    <Button
                      testId="admin-delete-board-cancel"
                      type="button"
                      variant="secondary"
                      onClick={() => {
                        setDeleteConfirmOpen(false)
                        setDeleteSlugInput('')
                        setDeleteBoardError(null)
                      }}
                      disabled={deletingBoard}
                    >
                      {t('deleteBoardConfirmCancel')}
                    </Button>
                  </div>
                </div>
              )}
            </div>
          </Section>
        )}
      </fieldset>
    </>,
  )
}
