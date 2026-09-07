/**
 * SetupWizard — first-run setup wizard shown on /admin/boards before any
 * normal usage, per the onboarding architecture: Welcome → essential setup
 * (first board) → Ready → real app.
 *
 * Adaptive/resumable by construction, with NO separate "current step" flag
 * to persist: the initial step is derived from data that's already there.
 *   - No boards yet            → 'welcome'
 *   - A board already exists   → 'ready' (covers resume-after-refresh once
 *     the board-creation step succeeded, and upgrading self-host installs
 *     that already have boards when this feature ships)
 * Finishing OR skipping both call onDone(), which POSTs
 * /admin/onboarding/complete (BoardsAdminPage.tsx) — the wizard itself never
 * renders again afterwards for this account.
 *
 * Deliberately does NOT duplicate the existing branding/moderation admin UI
 * (AdminPage.tsx) or the invite flow (MembersPage.tsx) as extra wizard
 * steps — those stay reachable from the in-app activation checklist
 * (ActivationChecklist.tsx) once the account has a board to point them at.
 */

import {
  Alert,
  Button,
  ConfirmDialog,
  CopyField,
  PageHeader,
  Section,
  Select,
  Steps,
  TextInput,
} from '@votepit/ui'
import { ArrowRight, Link2 } from 'lucide-react'
import { type FormEvent, type ReactNode, useState } from 'react'
import { accountPath } from '../lib/accountContext'
import type { AdminBoardSummary, ApiError, BoardVisibility } from '../lib/api'
import { createAdminBoard } from '../lib/api'
import { useT } from '../lib/i18n/context'
import { OutcomeMark } from './AuthShell'

type Step = 'welcome' | 'board' | 'ready'

interface SetupWizardProps {
  /** Boards already on the account — used only to derive the initial step. */
  boards: AdminBoardSummary[]
  /** Visibility values allowed on the account's current plan (PlanPolicy). */
  allowedVisibilities: BoardVisibility[]
  /** Safest plan-allowed visibility — pre-selected in the board step. */
  defaultVisibility: BoardVisibility
  /** Called once the wizard is finished or explicitly skipped. */
  onDone: (createdBoard: { slug: string; name: string } | null) => void
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

const STEPS: Step[] = ['welcome', 'board', 'ready']

/** Three ballot lines — the product's own iconography, drawn, not stock. */
function BallotArt() {
  return (
    <svg
      viewBox="0 0 120 56"
      width="120"
      height="56"
      fill="none"
      aria-hidden="true"
      className="text-vp-ink"
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

/**
 * One wizard step on the page's ruled sheet: progress rail on top, the
 * step's own content in the body, its actions in the sheet foot.
 */
function StepShell({
  step,
  labels,
  ariaLabel,
  footer,
  children,
}: {
  step: Step
  labels: string[]
  ariaLabel: string
  footer: ReactNode
  children: ReactNode
}) {
  const activeIndex = STEPS.indexOf(step)
  return (
    <Section
      key={step}
      emphasis="ruled"
      footer={footer}
      className="animate-vp-rise"
      bodyClassName="sm:p-8"
    >
      <Steps
        items={labels.map((label) => ({ label }))}
        current={activeIndex}
        ariaLabel={ariaLabel}
        className="mb-8"
      />
      {children}
    </Section>
  )
}

export default function SetupWizard({
  boards,
  allowedVisibilities,
  defaultVisibility,
  onDone,
}: SetupWizardProps) {
  const t = useT('setupWizard')
  const tCommon = useT('common')
  // Visibility option labels are shared with the board-branding form
  // (AdminPage.tsx) — reused via the 'adminPage' namespace.
  const tVisibility = useT('adminPage')
  const [step, setStep] = useState<Step>(boards.length > 0 ? 'ready' : 'welcome')
  const [createdBoard, setCreatedBoard] = useState<{ slug: string; name: string } | null>(
    boards.length > 0 ? { slug: boards[0].slug, name: boards[0].name } : null,
  )

  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')
  const [slugEdited, setSlugEdited] = useState(false)
  const [visibility, setVisibility] = useState<BoardVisibility>(defaultVisibility)
  const [pendingVisibility, setPendingVisibility] = useState<BoardVisibility | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  const stepLabels = [t('stepWelcome'), t('stepBoard'), t('stepReady')]
  const stepsAriaLabel = t('stepsAriaLabel')

  const handleNameChange = (value: string) => {
    setName(value)
    if (!slugEdited) setSlug(slugify(value))
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    if (creating) return
    setCreating(true)
    setFieldErrors({})
    setGeneralError(null)

    try {
      const result = await createAdminBoard({ name: name.trim(), slug: slug.trim(), visibility })
      setCreatedBoard({ slug: result.slug, name: result.name })
      setStep('ready')
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

  return (
    <div role="region" aria-label={t('regionAriaLabel')} className="py-4 sm:py-8">
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
                size="lg"
                iconEnd={<ArrowRight size={16} />}
                onClick={() => setStep('board')}
              >
                {t('getStarted')}
              </Button>
              <Button type="button" variant="ghost" onClick={() => onDone(null)}>
                {t('setUpLater')}
              </Button>
            </>
          }
        >
          <div className="mb-6">
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
            <div className="flex flex-1 flex-wrap items-center justify-between gap-3">
              <Button type="button" variant="ghost" onClick={() => onDone(null)}>
                {t('setUpLater')}
              </Button>
              <Button
                type="submit"
                form="wizard-board-form"
                variant="primary"
                disabled={creating}
                loading={creating}
                aria-busy={creating}
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
            className="flex flex-col gap-4"
          >
            <TextInput
              label={t('nameLabel')}
              name="name"
              id="wizard-board-name"
              value={name}
              onChange={handleNameChange}
              placeholder={t('namePlaceholder')}
              error={fieldErrors.name}
              required
              disabled={creating}
              autoFocus
            />
            <TextInput
              label={t('slugLabel')}
              name="slug"
              id="wizard-board-slug"
              value={slug}
              onChange={(v) => {
                setSlugEdited(true)
                setSlug(v)
              }}
              placeholder={t('slugPlaceholder')}
              mono
              prefix="/"
              hint={fieldErrors.slug === undefined ? t('slugHint') : undefined}
              error={fieldErrors.slug}
              required
              disabled={creating}
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

            <ConfirmDialog
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

            {generalError !== null && <Alert tone="error">{generalError}</Alert>}
          </form>
        </StepShell>
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
              iconEnd={<ArrowRight size={16} />}
              onClick={() => onDone(createdBoard)}
            >
              {t('goToDashboard')}
            </Button>
          }
        >
          {/* The one shared "done" mark, same as every other terminal screen. */}
          <div className="mb-5">
            <OutcomeMark tone="success" />
          </div>
          <PageHeader
            title={t('readyTitle', { name: createdBoard.name })}
            description={t('readyBody')}
          />

          <CopyField
            label={t('publicLinkLabel')}
            icon={<Link2 size={12} aria-hidden="true" />}
            value={publicBoardUrl}
            copyLabel={t('copy')}
            copiedLabel={t('copied')}
            mono
          />
        </StepShell>
      )}
    </div>
  )
}
