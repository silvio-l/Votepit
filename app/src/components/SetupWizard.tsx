/**
 * SetupWizard — the setup wizard for the board admin. Steps: Welcome → first
 * board → "how your board looks" (optional look) → Ready with the guided
 * "next steps" tour into the board settings.
 *
 * Two modes share the same steps:
 *   - 'first-run' (default): shown while the account has not finished
 *     onboarding. Adaptive/resumable: with no boards it starts at 'welcome',
 *     with a board already present it resumes at 'ready'.
 *   - 'reopen': opened on purpose from the board admin ("setup help
 *     open", "resume setup"). Starts at the look step for the
 *     target board, or at 'welcome' when the account has no board yet. Nothing
 *     is persisted by reopening itself; it only leaves onboarding as done again.
 *
 * The template chosen on the board step pre-sets the accent colour and, on
 * paid plans, the welcome text. Both are saved right after the board exists,
 * so skipping the look step keeps them; every value stays editable.
 *
 * Finishing, skipping or opening a settings link calls onDone(), which
 * BoardsAdminPage turns into POST /admin/onboarding/complete (idempotent).
 * Board-operator acknowledgment (Cloud only) still gates the board step, since
 * it POSTs to the same endpoint as the board admin's create form.
 */

import {
  Button,
  Checkbox,
  ConfirmDialog,
  CopyField,
  InlineNotice,
  PageHeader,
  Select,
  TextField,
} from '@votepit/ui/ds'
import { Link2 } from 'lucide-react'
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react'
import { accountPath } from '../lib/accountContext'
import type { AdminBoardSummary, ApiError, BoardVisibility } from '../lib/api'
import { createAdminBoard, saveAdminBranding } from '../lib/api'
import { qrAllowedFor } from '../lib/boardQr'
import { getEdition } from '../lib/edition'
import { getFeatures } from '../lib/features'
import { useT } from '../lib/i18n/context'
import { featureLocked, ONBOARDING_TEMPLATES, type OnboardingTemplate } from '../lib/onboarding'
import { getViewerEntitlements } from '../lib/viewer'
import { OutcomeMark } from './AuthShell'
import { BoardQrPanel } from './BoardQrPanel'
import { SetupWizardLook } from './SetupWizardLook'
import { SetupWizardNextSteps } from './SetupWizardNextSteps'
import { StepShell, type WizardStep } from './SetupWizardStepShell'

type SetupWizardMode = 'first-run' | 'reopen'

interface SetupWizardProps {
  /** Boards already on the account — used to derive the initial step. */
  boards: AdminBoardSummary[]
  /** Visibility values allowed on the account's current plan (PlanPolicy). */
  allowedVisibilities: BoardVisibility[]
  /** Safest plan-allowed visibility — pre-selected in the board step. */
  defaultVisibility: BoardVisibility
  /** 'reopen' starts at the look step (or welcome without boards). Default 'first-run'. */
  mode?: SetupWizardMode
  /** Reopen only: the board to work on; the first board when absent. */
  targetBoardSlug?: string | null
  /**
   * Called once the wizard is finished, skipped, or a settings link is chosen.
   * `to` is a route to open instead of the board dashboard.
   */
  onDone: (createdBoard: { slug: string; name: string } | null, to?: string) => void
}

/** Values the template preset put on the new board (first run only). */
interface AppliedPreset {
  accent: string
  intro: string
  statuses: string
}

function slugify(input: string): string {
  return input
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64)
}

/** Three ballot lines — the product's own iconography, drawn, not stock. */
function BallotArt() {
  return (
    <svg
      viewBox="0 0 120 56"
      width="120"
      height="56"
      fill="none"
      aria-hidden="true"
      className="text-vp-title"
    >
      <g stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        <rect x="2" y="2" width="14" height="14" rx="3" className="text-vp-vote-up" />
        <path d="M5.5 9.5l2.6 2.6 5-5.6" className="text-vp-vote-up" />
        <path d="M24 9h64" opacity="0.5" />
        <rect x="2" y="21" width="14" height="14" rx="3" opacity="0.4" />
        <path d="M24 28h80" opacity="0.5" />
        <rect x="2" y="40" width="14" height="14" rx="3" className="text-vp-vote-down" />
        <path d="M6 44l6 6M12 44l-6 6" className="text-vp-vote-down" />
        <path d="M24 47h48" opacity="0.5" />
      </g>
    </svg>
  )
}

/** The board to reopen the wizard on: the requested one, else the first. */
function pickReopenBoard(
  boards: AdminBoardSummary[],
  targetSlug: string | null,
): AdminBoardSummary | null {
  if (targetSlug !== null) {
    const match = boards.find((board) => board.slug === targetSlug)
    if (match !== undefined) return match
  }
  return boards[0] ?? null
}

export default function SetupWizard({
  boards,
  allowedVisibilities,
  defaultVisibility,
  mode = 'first-run',
  targetBoardSlug = null,
  onDone,
}: SetupWizardProps) {
  const t = useT('setupWizard')
  const tCommon = useT('common')
  // Visibility option labels are shared with the board-branding form
  // (AdminPage.tsx) — reused via the 'adminPage' namespace.
  const tVisibility = useT('adminPage')
  const billing = getFeatures().billing === true
  const reopenBoard = mode === 'reopen' ? pickReopenBoard(boards, targetBoardSlug) : null

  const [step, setStep] = useState<WizardStep>(() => {
    if (reopenBoard !== null) return 'look'
    return boards.length > 0 ? 'ready' : 'welcome'
  })
  const [createdBoard, setCreatedBoard] = useState<{ slug: string; name: string } | null>(() => {
    if (reopenBoard !== null) return { slug: reopenBoard.slug, name: reopenBoard.name }
    return boards.length > 0 ? { slug: boards[0].slug, name: boards[0].name } : null
  })
  const [preset, setPreset] = useState<AppliedPreset | null>(null)

  const [template, setTemplate] = useState<OnboardingTemplate>('product')
  const [anonymousVotingEnabled, setAnonymousVotingEnabled] = useState(
    ONBOARDING_TEMPLATES.product.anonymousVoting,
  )
  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')
  const [slugEdited, setSlugEdited] = useState(false)
  const [visibility, setVisibility] = useState<BoardVisibility>(defaultVisibility)
  const [pendingVisibility, setPendingVisibility] = useState<BoardVisibility | null>(null)
  const [operatorAcknowledged, setOperatorAcknowledged] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  // Visibility of the board created in THIS wizard run (the server accepted
  // exactly what was sent). A pre-existing board's visibility is unknown
  // here, so it gets no QR code (fail-secure, issue 09).
  const [createdVisibility, setCreatedVisibility] = useState<BoardVisibility | null>(null)

  // Focus moves to the new step's region on every step change, so a screen
  // reader announces it. The board step keeps focus on its name field instead.
  const regionRef = useRef<HTMLDivElement>(null)
  const stepChanged = useRef(false)
  useEffect(() => {
    if (!stepChanged.current) {
      stepChanged.current = true
      return
    }
    if (step !== 'board') regionRef.current?.focus()
  }, [step])

  const isCloud = getEdition() === 'cloud'

  const stepLabels = [t('stepWelcome'), t('stepBoard'), t('stepLook'), t('stepReady')]
  const stepsAriaLabel = t('stepsAriaLabel')

  const handleNameChange = (value: string) => {
    setName(value)
    if (!slugEdited) setSlug(slugify(value))
  }

  const handleTemplateChange = (value: string) => {
    const next = value as OnboardingTemplate
    setTemplate(next)
    setAnonymousVotingEnabled(ONBOARDING_TEMPLATES[next].anonymousVoting)
  }

  /**
   * Saves the template's accent (every plan) and welcome text (paid plans) to
   * the new board. A failure is not fatal: the look step saves the same values.
   */
  const applyTemplatePreset = async (boardSlug: string): Promise<AppliedPreset> => {
    const current = ONBOARDING_TEMPLATES[template]
    const introText = t(current.introKey)
    const introLocked = featureLocked(getViewerEntitlements(), 'intro', billing)
    try {
      await saveAdminBranding(boardSlug, {
        primary_color: current.accent,
        ...(introLocked ? {} : { intro: introText }),
      })
    } catch {
      // Keep going: the look step shows these values and saves them again.
    }
    return { accent: current.accent, intro: introText, statuses: t(current.statusesKey) }
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    if (creating) return
    setCreating(true)
    setFieldErrors({})
    setGeneralError(null)

    try {
      const result = await createAdminBoard({
        name: name.trim(),
        slug: slug.trim(),
        visibility,
        operator_acknowledged: operatorAcknowledged,
        anonymous_voting_enabled: anonymousVotingEnabled,
      })
      setCreatedBoard({ slug: result.slug, name: result.name })
      setCreatedVisibility(visibility)
      setPreset(await applyTemplatePreset(result.slug))
      setStep('look')
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        setGeneralError(apiErr?.payload?.message ?? tCommon('state.error'))
      }
    } finally {
      setCreating(false)
    }
  }

  // The public board URL keeps the account prefix in cloud mode (accountPath
  // is a no-op on self-host) — the link an admin shares must be the real one.
  const publicBoardUrl =
    createdBoard !== null ? `${window.location.origin}${accountPath(`/${createdBoard.slug}`)}` : ''
  const addressPreview = `${window.location.host}${accountPath(`/${slug.trim() || '…'}`)}`

  return (
    <div
      ref={regionRef}
      tabIndex={-1}
      role="region"
      aria-label={t('regionAriaLabel')}
      data-testid="setup-wizard-root"
      className="py-ds-md outline-none sm:py-ds-xl"
    >
      {step === 'welcome' && (
        <StepShell
          step="welcome"
          labels={stepLabels}
          ariaLabel={stepsAriaLabel}
          footer={
            <>
              <Button
                type="button"
                variant="primary"
                onClick={() => setStep('board')}
                testId="setup-wizard-get-started"
              >
                {t('getStarted')}
              </Button>
              <Button
                type="button"
                variant="text"
                onClick={() => onDone(null)}
                testId="setup-wizard-welcome-later"
              >
                {t('setUpLater')}
              </Button>
            </>
          }
        >
          <div className="mb-ds-lg">
            <BallotArt />
          </div>
          <PageHeader size="display" title={t('welcomeTitle')} description={t('welcomeBody')} />
        </StepShell>
      )}

      {step === 'board' && (
        <StepShell
          step="board"
          labels={stepLabels}
          ariaLabel={stepsAriaLabel}
          footer={
            <div className="flex flex-1 flex-wrap items-center justify-between gap-ds-sm">
              <Button
                type="button"
                variant="text"
                onClick={() => onDone(null)}
                testId="setup-wizard-board-later"
              >
                {t('setUpLater')}
              </Button>
              <Button
                type="submit"
                form="wizard-board-form"
                variant="primary"
                disabled={creating || (isCloud && !operatorAcknowledged)}
                loading={creating}
                testId="setup-wizard-create-board"
              >
                {creating ? t('createSubmitting') : t('createSubmit')}
              </Button>
            </div>
          }
        >
          <PageHeader title={t('boardTitle')} description={t('boardBody')} />

          <form
            id="wizard-board-form"
            onSubmit={handleSubmit}
            noValidate
            className="flex flex-col gap-ds-md"
            data-testid="setup-wizard-board-form"
          >
            <Select
              label={t('templateLabel')}
              id="wizard-board-template"
              name="template"
              value={template}
              onChange={handleTemplateChange}
              hint={t('templateHint')}
              disabled={creating}
              testId="setup-wizard-template"
            >
              <option value="product">{t('templateProduct')}</option>
              <option value="event">{t('templateEvent')}</option>
              <option value="club">{t('templateClub')}</option>
              <option value="team">{t('templateTeam')}</option>
            </Select>
            <TextField
              label={t('nameLabel')}
              name="name"
              id="wizard-board-name"
              value={name}
              onChange={(e) => handleNameChange(e.target.value)}
              placeholder={t(ONBOARDING_TEMPLATES[template].placeholderKey)}
              error={fieldErrors.name}
              required
              disabled={creating}
              autoFocus
              testId="setup-wizard-name"
            />
            <TextField
              label={t('slugLabel')}
              name="slug"
              id="wizard-board-slug"
              value={slug}
              onChange={(e) => {
                setSlugEdited(true)
                setSlug(e.target.value)
              }}
              placeholder={t(ONBOARDING_TEMPLATES[template].slugPlaceholderKey)}
              mono
              prefix="/"
              hint={
                fieldErrors.slug === undefined
                  ? t('slugPreview', { url: addressPreview })
                  : undefined
              }
              error={fieldErrors.slug}
              required
              disabled={creating}
              testId="setup-wizard-slug"
            />

            <Select
              label={tVisibility('visibilityLabel')}
              id="wizard-board-visibility"
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
              disabled={creating}
              error={fieldErrors.visibility}
              testId="setup-wizard-visibility"
              hint={
                fieldErrors.visibility === undefined && allowedVisibilities.length <= 1
                  ? tVisibility('visibilityUpgradeHint')
                  : undefined
              }
            >
              <option value="public" disabled={!allowedVisibilities.includes('public')}>
                {tVisibility('visibilityPublic')}
              </option>
              <option value="unlisted" disabled={!allowedVisibilities.includes('unlisted')}>
                {tVisibility('visibilityUnlisted')}
              </option>
              <option value="private" disabled={!allowedVisibilities.includes('private')}>
                {tVisibility('visibilityPrivate')}
              </option>
            </Select>

            <Checkbox
              id="wizard-board-anonymous-voting"
              name="anonymous_voting_enabled"
              label={t('anonymousVotingLabel')}
              hint={t('anonymousVotingHint')}
              checked={anonymousVotingEnabled}
              onChange={setAnonymousVotingEnabled}
              disabled={creating}
              testId="setup-wizard-anonymous-voting"
            />

            <ConfirmDialog
              testId="setup-wizard-visibility-dialog"
              open={pendingVisibility !== null}
              title={tVisibility('visibilityConfirmTitle')}
              description={tVisibility('visibilityConfirmBody')}
              confirmLabel={tVisibility('visibilityConfirmAction')}
              cancelLabel={tCommon('action.cancel')}
              onConfirm={() => {
                if (pendingVisibility !== null) setVisibility(pendingVisibility)
                setPendingVisibility(null)
              }}
              onCancel={() => setPendingVisibility(null)}
            />

            {isCloud && (
              <Checkbox
                id="wizard-board-operator-acknowledged"
                name="operator_acknowledged"
                label={t('operatorAckLabel')}
                checked={operatorAcknowledged}
                onChange={setOperatorAcknowledged}
                disabled={creating}
                testId="setup-wizard-operator-ack"
              />
            )}

            {generalError !== null && <InlineNotice tone="error">{generalError}</InlineNotice>}
          </form>
        </StepShell>
      )}

      {step === 'look' && createdBoard !== null && (
        <SetupWizardLook
          boardSlug={createdBoard.slug}
          boardName={createdBoard.name}
          presetAccent={preset?.accent ?? null}
          presetIntro={preset?.intro ?? null}
          presetStatuses={preset?.statuses ?? null}
          stepLabels={stepLabels}
          stepsAriaLabel={stepsAriaLabel}
          onSaved={() => setStep('ready')}
          onSkip={() => setStep('ready')}
          onOpenSettings={(target) => onDone(createdBoard, target)}
        />
      )}

      {step === 'ready' && createdBoard !== null && (
        <StepShell
          step="ready"
          labels={stepLabels}
          ariaLabel={stepsAriaLabel}
          footer={
            <Button
              type="button"
              variant="primary"
              onClick={() => onDone(createdBoard)}
              testId="setup-wizard-go-to-dashboard"
            >
              {t('goToDashboard')}
            </Button>
          }
        >
          {/* The one shared "done" mark, same as every other terminal screen. */}
          <div className="mb-ds-lg">
            <OutcomeMark tone="success" />
          </div>
          <PageHeader
            title={t('readyTitle', { name: createdBoard.name })}
            description={t('readyBody')}
          />

          {(() => {
            const linkField = (qrToggle?: ReactNode) => (
              <CopyField
                testId="setup-wizard-public-link-copy"
                label={t('publicLinkLabel')}
                icon={<Link2 size={12} aria-hidden="true" />}
                value={publicBoardUrl}
                copyLabel={t('copy')}
                copiedLabel={t('copied')}
                mono
                actions={qrToggle}
              />
            )
            return createdVisibility !== null && qrAllowedFor(createdVisibility) ? (
              <BoardQrPanel
                url={publicBoardUrl}
                fileName={createdBoard.slug}
                renderField={linkField}
              />
            ) : (
              linkField()
            )
          })()}

          <SetupWizardNextSteps
            boardSlug={createdBoard.slug}
            onOpen={(target) => onDone(createdBoard, target)}
          />
        </StepShell>
      )}
    </div>
  )
}
