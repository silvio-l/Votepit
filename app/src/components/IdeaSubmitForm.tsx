/**
 * IdeaSubmitForm — the "submit a new idea" form, shared by the submit page
 * (desktop) and the new-idea sheet (phone tab bar).
 *
 * Owns the form state, the as-you-type duplicate hint, the submit paths
 * (member and guest, ADR 0006) and their error mapping. The host supplies
 * the board facts it already loaded and decides what happens after a create
 * (navigate or close) through `onCreated`.
 */

import {
  Badge,
  Button,
  ButtonLink,
  InlineNotice,
  Section,
  TextArea,
  TextField,
} from '@votepit/ui/ds'
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTurnstileChallenge } from '../hooks/useTurnstileChallenge'
import { accountPath, getAccountSlug } from '../lib/accountContext'
import type {
  ApiError,
  BoardPhasesInfo,
  DuplicateCandidate,
  Tag,
  TagSuggestionAvailability,
  User,
} from '../lib/api'
import { accountRoleFor, createIdea, searchDuplicates, suggestTags } from '../lib/api'
import { blockedReasonKey, isPhaseErrorKey, phaseReasonKey } from '../lib/boardPhases'
import type { BrandingPro } from '../lib/branding'
import { legalLinksFor } from '../lib/features'
import { wordingOverride } from '../lib/i18n/boardWording'
import { useI18n, useT } from '../lib/i18n/context'
import { BoardPhaseLine } from './BoardPhaseLine'
import { GuestIdeasPanel } from './GuestIdeasPanel'
import { MarkdownToolbar } from './MarkdownToolbar'
import { PlanUpgradeLink } from './PlanUpgradeLink'
import { RouterButtonLink } from './RouterButtonLink'
import { MAX_IDEA_TAGS, TagChipPicker } from './TagChipPicker'
import { TurnstileChallengeModal } from './TurnstileChallengeModal'
import { TurnstileWidget } from './TurnstileWidget'

/** Debounce delay (ms) before firing a duplicate-search request while typing. */
const DUPLICATE_SEARCH_DEBOUNCE_MS = 350

/** Below this trimmed length, don't bother searching (too little signal). */
const MIN_DUPLICATE_SEARCH_LENGTH = 3

/** P-35: AI tag suggestions wait for a pause in typing and never block the form. */
const MIN_SUGGEST_TITLE_LENGTH = 3
const SUGGEST_DEBOUNCE_MS = 700
const SUGGEST_TIMEOUT_MS = 8000

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('timeout')), ms)
    promise.then(
      (value) => {
        clearTimeout(timer)
        resolve(value)
      },
      (error: unknown) => {
        clearTimeout(timer)
        reject(error)
      },
    )
  })
}

/** Turnstile action of a guest submission — the server verifies it. */
const GUEST_TURNSTILE_ACTION = 'anon_idea_submit'

/** Server error keys of a guest submission with their own localized text. */
const GUEST_ERROR_KEYS: Record<string, string> = {
  url_not_allowed: 'guestErrorUrl',
  captcha_failed: 'guestErrorCaptcha',
  captcha_unavailable: 'guestErrorUnavailable',
  queue_full: 'guestErrorQueueFull',
  rate_limited: 'guestErrorRateLimited',
  moderation_blocked: 'guestErrorBlocked',
  rejected: 'guestErrorRejected',
}

/** Matches the operator's privacy-policy link in any of the six languages. */
const PRIVACY_LINK_PATTERN = /privac|datenschutz|confidentialit/i

// A response without a candidate list (unexpected payload) means "no hint",
// never a crash of the submit page.
function candidatesOf(res: { candidates?: DuplicateCandidate[] }): DuplicateCandidate[] {
  return Array.isArray(res.candidates) ? res.candidates : []
}

export interface IdeaSubmitFormProps {
  boardSlug: string
  /** Pro wording for the submit button and the thank-you note; null = Votepit texts. */
  brandingPro?: BrandingPro | null
  /** Where "back to the board" and the cancel link point (page variant). */
  boardHref: string
  phases: BoardPhasesInfo | undefined
  /** The board's existing tags for the optional picker (P-34). */
  tags?: Tag[]
  /** AI tag suggestion availability (P-35); absent = none. */
  tagSuggestion?: TagSuggestionAvailability
  /** Guest site key when submitting without an account; null for members. */
  guestSiteKey: string | null
  user: User | null
  /** Time-trap stamp from GET /ideas/new (server-signed). */
  formAt: string
  /** Called with the new idea's id after a successful create. */
  onCreated: (ideaId: number) => void
  /** Cancel handler for hosts that render the form in a sheet. Omit on the page. */
  onCancel?: () => void
}

export function IdeaSubmitForm({
  boardSlug,
  brandingPro = null,
  boardHref,
  phases,
  tags = [],
  tagSuggestion,
  guestSiteKey,
  user,
  formAt,
  onCreated,
  onCancel,
}: IdeaSubmitFormProps) {
  const t = useT('submitPage')
  const tCommon = useT('common')
  const { language } = useI18n()
  const { withChallenge, modalProps } = useTurnstileChallenge()
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const bodyRef = useRef<HTMLTextAreaElement>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  // Guest mode (ADR 0006): inline Turnstile token, thanks state, device panel.
  const [guestToken, setGuestToken] = useState<string | null>(null)
  const [guestWidgetKey, setGuestWidgetKey] = useState(0)
  const [guestThanks, setGuestThanks] = useState<'pending' | 'published' | null>(null)
  const [guestListKey, setGuestListKey] = useState(0)
  const isGuest = guestSiteKey !== null
  // Optional existing board tags (P-34): picked by the submitter, never created here.
  // P-35: AI suggestions are preselected and marked; a manual pick or drop always
  // wins over a later suggestion, so the submitter keeps the last word.
  const [manualTagIds, setManualTagIds] = useState<number[]>([])
  const [excludedTagIds, setExcludedTagIds] = useState<number[]>([])
  const [suggestedTagIds, setSuggestedTagIds] = useState<number[]>([])
  const suggestionOffer = suggestedTagIds.filter((id) => !excludedTagIds.includes(id))
  const selectedTagIds = [
    ...manualTagIds,
    ...suggestionOffer.filter((id) => !manualTagIds.includes(id)),
  ].slice(0, MAX_IDEA_TAGS)
  const atTagLimit = selectedTagIds.length >= MAX_IDEA_TAGS
  const tagPayload = selectedTagIds.length > 0 ? { tag_ids: selectedTagIds } : {}

  function isAiSuggested(tagId: number): boolean {
    return selectedTagIds.includes(tagId) && !manualTagIds.includes(tagId)
  }

  function resetTagSelection() {
    setManualTagIds([])
    setExcludedTagIds([])
    setSuggestedTagIds([])
  }

  function toggleTag(tagId: number) {
    if (selectedTagIds.includes(tagId)) {
      setManualTagIds((current) => current.filter((id) => id !== tagId))
      if (suggestedTagIds.includes(tagId)) {
        setExcludedTagIds((current) => (current.includes(tagId) ? current : [...current, tagId]))
      }
      return
    }
    if (atTagLimit) return
    setExcludedTagIds((current) => current.filter((id) => id !== tagId))
    setManualTagIds((current) => (current.includes(tagId) ? current : [...current, tagId]))
  }

  // P-35: debounced suggestions while writing. Only when the server reports
  // them available (signed-in, plan and opt-in). Failures and timeouts leave the
  // form usable without suggestions; stale answers are dropped.
  const suggestionRequestRef = useRef(0)
  useEffect(() => {
    if (tagSuggestion !== 'available' || tags.length === 0) return
    const trimmedTitle = title.trim()
    if (trimmedTitle.length < MIN_SUGGEST_TITLE_LENGTH) {
      setSuggestedTagIds([])
      return
    }
    const timer = setTimeout(() => {
      const requestId = ++suggestionRequestRef.current
      withTimeout(
        suggestTags(boardSlug, { title: trimmedTitle, body: body.trim() }),
        SUGGEST_TIMEOUT_MS,
      )
        .then((result) => {
          if (requestId === suggestionRequestRef.current) setSuggestedTagIds(result.suggestions)
        })
        .catch(() => {
          if (requestId === suggestionRequestRef.current) setSuggestedTagIds([])
        })
    }, SUGGEST_DEBOUNCE_MS)
    return () => clearTimeout(timer)
  }, [title, body, tagSuggestion, boardSlug, tags.length])

  // As-you-type duplicate hint — surfacing only, never blocks submit.
  const [duplicates, setDuplicates] = useState<DuplicateCandidate[]>([])
  const [duplicatesDismissed, setDuplicatesDismissed] = useState(false)
  // Title whose candidates were already double-checked (confirm=1, issue 11):
  // asked once per title on blur, never per keystroke, and a late debounced
  // (unconfirmed) answer for the same title must not overwrite it.
  const confirmedTitleRef = useRef<string | null>(null)
  const currentTitleRef = useRef('')
  currentTitleRef.current = title.trim()

  useEffect(() => {
    if (!boardSlug) return

    const trimmed = title.trim()
    setDuplicatesDismissed(false)

    if (trimmed.length < MIN_DUPLICATE_SEARCH_LENGTH) {
      setDuplicates([])
      return
    }

    let cancelled = false
    const timer = setTimeout(() => {
      void searchDuplicates(boardSlug, trimmed)
        .then((res) => {
          if (!cancelled && confirmedTitleRef.current !== trimmed) setDuplicates(candidatesOf(res))
        })
        .catch(() => {
          // Non-critical hint — a failed lookup just means no hint, never an error.
          if (!cancelled && confirmedTitleRef.current !== trimmed) setDuplicates([])
        })
    }, DUPLICATE_SEARCH_DEBOUNCE_MS)

    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [title, boardSlug])

  // On leaving the title field, ask once for confirmed candidates (the server
  // only confirms where its extension + plan allow it; otherwise the answer is
  // the same deterministic list). Failures keep the current hint as is.
  function confirmDuplicatesOnBlur() {
    const trimmed = title.trim()
    if (!boardSlug || trimmed.length < MIN_DUPLICATE_SEARCH_LENGTH) return
    // Guests never use the (paid, AI-backed) confirmation path (ADR 0006).
    if (isGuest) return
    if (confirmedTitleRef.current === trimmed) return
    void searchDuplicates(boardSlug, trimmed, { confirm: true })
      .then((res) => {
        if (currentTitleRef.current !== trimmed) return
        confirmedTitleRef.current = trimmed
        setDuplicates(candidatesOf(res))
      })
      .catch(() => {})
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (submitting) return

    setSubmitting(true)
    setFieldErrors({})
    setGeneralError(null)

    if (isGuest) {
      await submitAsGuest(boardSlug)
      return
    }

    try {
      const result = await withChallenge((turnstileToken) =>
        createIdea(boardSlug, {
          title: title.trim(),
          body: body.trim(),
          website: '', // honeypot — must be empty
          _form_at: formAt,
          turnstile_token: turnstileToken,
          ...tagPayload,
        }),
      )
      onCreated(result.id)
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        // board-operator-compliance issue 10: a dedicated error key (not a
        // generic validation error), so the message is unambiguous rather
        // than whatever the server happened to phrase it as.
        const key = apiErr?.payload?.key
        setGeneralError(
          key === 'member_suspended'
            ? t('memberSuspendedError')
            : isPhaseErrorKey(key)
              ? tCommon(phaseReasonKey(key))
              : (apiErr?.payload?.message ?? tCommon('state.error')),
        )
      }
      setSubmitting(false)
    }
  }

  async function submitAsGuest(slug: string) {
    try {
      const result = await createIdea(slug, {
        title: title.trim(),
        body: body.trim(),
        website: '', // honeypot — must be empty
        _form_at: formAt,
        turnstile_token: guestToken ?? '',
        ...tagPayload,
      })
      setGuestThanks(result.moderation_state === 'published' ? 'published' : 'pending')
      setTitle('')
      setBody('')
      resetTagSelection()
      setGuestListKey((k) => k + 1)
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        const key = apiErr?.payload?.key
        const guestKey = key !== undefined ? GUEST_ERROR_KEYS[key] : undefined
        setGeneralError(
          guestKey !== undefined
            ? t(guestKey)
            : isPhaseErrorKey(key)
              ? tCommon(phaseReasonKey(key))
              : (apiErr?.payload?.message ?? tCommon('state.error')),
        )
      }
    } finally {
      // A Turnstile token is single-use: always render a fresh widget.
      setGuestWidgetKey((k) => k + 1)
      setSubmitting(false)
    }
  }

  // Deadlines (issue 04): server-computed state; owner/admin may still seed
  // ideas into a closed window (the server applies the same exemption).
  const submissionsReasonKey = blockedReasonKey(phases, 'submissions')
  const viewerRole = user !== null ? accountRoleFor(user, getAccountSlug()) : null
  const submissionsExempt = viewerRole === 'owner' || viewerRole === 'admin'
  // AI tag suggestions are a Pro feature: only people who manage the board
  // (owner, admin, moderator) get the upgrade hint. Voters and submitters see
  // nothing, so the form carries no upsell for end users.
  const canSeeTagUpgradeHint =
    viewerRole === 'owner' || viewerRole === 'admin' || viewerRole === 'moderator'
  const submissionsLocked = submissionsReasonKey !== null && !submissionsExempt

  // Guest form: short cookie/data hint + link to the privacy policy (ADR 0006,
  // § 25 Abs. 2 Nr. 2 TDDDG). Picked from the operator's legal links by name.
  const privacyLink = isGuest
    ? legalLinksFor(language).find((link) =>
        PRIVACY_LINK_PATTERN.test(`${link.label} ${link.href}`),
      )
    : undefined

  const isSubmitDisabled =
    submitting || submissionsLocked || title.trim().length === 0 || (isGuest && guestToken === null)

  return (
    <>
      {guestThanks !== null && (
        <InlineNotice
          tone="success"
          testId="submit-guest-thanks"
          title={
            guestThanks === 'pending'
              ? (wordingOverride(brandingPro?.wording, language, 'thank_you') ??
                t('guestThanksPendingTitle'))
              : (wordingOverride(brandingPro?.wording, language, 'thank_you') ??
                t('guestThanksPublishedTitle'))
          }
          className="mb-4"
        >
          <p className="text-pretty">
            {guestThanks === 'pending'
              ? t('guestThanksPendingBody')
              : t('guestThanksPublishedBody')}
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <Button
              testId="submit-guest-another"
              variant="secondary"
              onClick={() => setGuestThanks(null)}
            >
              {t('guestSubmitAnother')}
            </Button>
            <ButtonLink
              testId="submit-back-to-list"
              href={boardHref}
              linkAs={RouterButtonLink}
              variant="text"
            >
              {t('backToList')}
            </ButtonLink>
          </div>
        </InlineNotice>
      )}

      <Section
        testId="submit-form-section"
        emphasis="ruled"
        footer={
          <>
            <Button
              testId="submit-idea-submit"
              type="submit"
              form="submit-idea-form"
              variant="primary"
              disabled={isSubmitDisabled}
              loading={submitting}
            >
              {submitting
                ? t('submitting')
                : (wordingOverride(brandingPro?.wording, language, 'submit_button') ?? t('submit'))}
            </Button>
            {onCancel !== undefined ? (
              <Button testId="submit-cancel" variant="text" onClick={onCancel}>
                {tCommon('action.cancel')}
              </Button>
            ) : (
              <ButtonLink
                testId="submit-cancel"
                href={boardHref}
                linkAs={RouterButtonLink}
                variant="text"
              >
                {tCommon('action.cancel')}
              </ButtonLink>
            )}
          </>
        }
      >
        <BoardPhaseLine phases={phases} className="pt-1" />
        {isGuest && (
          <InlineNotice tone="info" testId="submit-guest-notice" className="mt-3">
            <span data-testid="guest-notice">
              {t('guestNotice')}
              {privacyLink !== undefined && (
                <>
                  {' '}
                  <a
                    data-testid="submit-privacy-link"
                    href={privacyLink.href}
                    target="_blank"
                    rel="noreferrer"
                    className="underline hover:no-underline"
                  >
                    {privacyLink.label}
                  </a>
                </>
              )}
            </span>
          </InlineNotice>
        )}
        {submissionsReasonKey !== null && (
          <InlineNotice
            tone={submissionsLocked ? 'warning' : 'info'}
            testId="submit-phase-notice"
            className="mt-3"
          >
            {submissionsLocked ? tCommon(submissionsReasonKey) : tCommon('phase.adminExempt')}
          </InlineNotice>
        )}
        <form
          id="submit-idea-form"
          onSubmit={handleSubmit}
          noValidate
          className="flex flex-col gap-5 py-1"
        >
          {/*
            Honeypot — display:none so bots see it but users never interact with it.
            The server rejects any submission where `website` is non-empty.
          */}
          <div aria-hidden="true" className="hidden">
            <label htmlFor="website-hp">{t('honeypotLabel')}</label>
            <input
              data-testid="submit-website-hp"
              id="website-hp"
              type="text"
              name="website"
              autoComplete="off"
              tabIndex={-1}
              readOnly
              value=""
            />
          </div>

          <TextField
            testId="submit-title"
            label={t('titleLabel')}
            name="title"
            id="submit-title"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder={t('titlePlaceholder')}
            error={fieldErrors.title}
            hint={
              fieldErrors.title !== undefined ? undefined : t('titleHint', { count: title.length })
            }
            required
            disabled={submitting}
            autoComplete="off"
            maxLength={200}
            onBlur={confirmDuplicatesOnBlur}
          />

          {duplicates.length > 0 && !duplicatesDismissed && (
            <InlineNotice tone="info" testId="submit-duplicates" title={t('duplicateHintQuestion')}>
              <p className="text-vp-text-secondary">{t('duplicateHintLead')}</p>
              <ul className="mt-2 flex flex-col gap-1.5">
                {duplicates.map((candidate) => (
                  <li key={candidate.id} className="flex flex-wrap items-baseline gap-x-2">
                    <Link
                      data-testid={`submit-duplicate-${candidate.id}`}
                      to={accountPath(`/${boardSlug}/idea/${candidate.id}`)}
                      target="_blank"
                      rel="noreferrer"
                      className="text-vp-base text-vp-ink underline hover:no-underline"
                    >
                      {candidate.title}
                    </Link>
                    {candidate.confirmed === true && (
                      <Badge tone="accent">{t('duplicateAiMatch')}</Badge>
                    )}
                    <span className="font-mono-num text-vp-xs text-vp-text-muted">
                      {t(
                        candidate.up_count - candidate.down_count === 1
                          ? 'votesCountSingular'
                          : 'votesCount',
                        { count: candidate.up_count - candidate.down_count },
                      )}
                    </span>
                  </li>
                ))}
              </ul>
              <div className="mt-3">
                <Button
                  testId="submit-duplicates-anyway"
                  variant="secondary"
                  onClick={() => setDuplicatesDismissed(true)}
                >
                  {t('submitAnywayButton')}
                </Button>
              </div>
            </InlineNotice>
          )}

          <TextArea
            testId="submit-body"
            ref={bodyRef}
            label={t('bodyLabel')}
            name="body"
            id="submit-body"
            labelEnd={
              // Guests may not post links (ADR 0006), so no formatting toolbar.
              isGuest ? undefined : (
                // Phones (< 768 px) get 44 px toolbar targets; desktop stays compact.
                <div className="max-md:[&_button]:min-h-11 max-md:[&_button]:min-w-11">
                  <MarkdownToolbar textareaRef={bodyRef} value={body} onChange={setBody} />
                </div>
              )
            }
            value={body}
            onChange={(e) => setBody(e.target.value)}
            placeholder={t('bodyPlaceholder')}
            error={fieldErrors.body}
            hint={
              fieldErrors.body !== undefined ? undefined : t('bodyHint', { count: body.length })
            }
            required
            disabled={submitting}
            rows={6}
            maxLength={500}
          />

          {tags.length > 0 && (
            <TagChipPicker
              testIdPrefix="submit"
              tags={tags}
              selectedIds={selectedTagIds}
              isSuggested={isAiSuggested}
              disabled={submitting}
              labels={{
                label: t('tagsLabel'),
                hint: t('tagsHint', { count: MAX_IDEA_TAGS }),
                limitReached: t('tagsLimitReached'),
                suggested: t('tagsSuggested'),
              }}
              onToggle={toggleTag}
            />
          )}

          {tags.length > 0 && tagSuggestion === 'upgrade_required' && canSeeTagUpgradeHint && (
            <p
              data-testid="submit-tag-suggestions-upgrade"
              className="text-vp-xs text-vp-text-muted"
            >
              {t('tagSuggestionsPlanHint')}{' '}
              <PlanUpgradeLink label={tCommon('planUpgradeLinkLabel')} />
            </p>
          )}

          {guestSiteKey !== null && (
            <TurnstileWidget
              siteKey={guestSiteKey}
              action={GUEST_TURNSTILE_ACTION}
              onToken={setGuestToken}
              resetKey={guestWidgetKey}
            />
          )}

          {generalError !== null && (
            <InlineNotice tone="error" testId="submit-general-error">
              {generalError}
            </InlineNotice>
          )}
        </form>
      </Section>
      {isGuest && <GuestIdeasPanel boardSlug={boardSlug} refreshKey={guestListKey} />}

      <TurnstileChallengeModal {...modalProps} />
    </>
  )
}
