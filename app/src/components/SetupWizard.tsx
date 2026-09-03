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

import { Alert, Button, Steps, TextInput } from '@votepit/ui'
import { ArrowRight, Check, Copy, Link2 } from 'lucide-react'
import { type FormEvent, type ReactNode, useState } from 'react'
import { accountPath } from '../lib/accountContext'
import type { AdminBoardSummary, ApiError } from '../lib/api'
import { createAdminBoard } from '../lib/api'
import { useT } from '../lib/i18n/context'

type Step = 'welcome' | 'board' | 'ready'

interface SetupWizardProps {
  /** Boards already on the account — used only to derive the initial step. */
  boards: AdminBoardSummary[]
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

function StepShell({
  step,
  labels,
  ariaLabel,
  children,
}: {
  step: Step
  labels: string[]
  ariaLabel: string
  children: ReactNode
}) {
  const activeIndex = STEPS.indexOf(step)
  return (
    <div key={step} className="vp-card vp-sheet--ruled p-6 sm:p-8 animate-vp-rise">
      <Steps
        items={labels.map((label) => ({ label }))}
        current={activeIndex}
        ariaLabel={ariaLabel}
        className="mb-8"
      />
      {children}
    </div>
  )
}

export default function SetupWizard({ boards, onDone }: SetupWizardProps) {
  const t = useT('setupWizard')
  const tCommon = useT('common')
  const [step, setStep] = useState<Step>(boards.length > 0 ? 'ready' : 'welcome')
  const [createdBoard, setCreatedBoard] = useState<{ slug: string; name: string } | null>(
    boards.length > 0 ? { slug: boards[0].slug, name: boards[0].name } : null,
  )

  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')
  const [slugEdited, setSlugEdited] = useState(false)
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
      const result = await createAdminBoard({ name: name.trim(), slug: slug.trim() })
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

  const [copied, setCopied] = useState(false)
  const handleCopyLink = async () => {
    try {
      await navigator.clipboard.writeText(publicBoardUrl)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard API unavailable (permission/older browser) — the link is
      // still selectable as plain text, no further fallback needed.
    }
  }

  return (
    <div role="region" aria-label={t('regionAriaLabel')} className="py-4 sm:py-8">
      {step === 'welcome' && (
        <StepShell step="welcome" labels={stepLabels} ariaLabel={stepsAriaLabel}>
          <div className="mb-6">
            <BallotArt />
          </div>
          <h1 className="font-archivo font-bold text-vp-3xl md:text-vp-4xl tracking-[-0.025em] text-vp-ink text-balance mb-3">
            {t('welcomeTitle')}
          </h1>
          <p className="text-vp-md text-vp-text-secondary text-pretty mb-8 max-w-prose leading-7">
            {t('welcomeBody')}
          </p>
          <div className="flex flex-wrap items-center gap-3">
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
          </div>
        </StepShell>
      )}

      {step === 'board' && (
        <StepShell step="board" labels={stepLabels} ariaLabel={stepsAriaLabel}>
          <h1 className="font-archivo font-bold text-vp-2xl tracking-[-0.02em] text-vp-ink mb-1">
            {t('boardTitle')}
          </h1>
          <p className="text-vp-md text-vp-text-secondary text-pretty mb-6 max-w-prose">
            {t('boardBody')}
          </p>

          <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
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

            {generalError !== null && <Alert tone="error">{generalError}</Alert>}

            <div className="flex flex-wrap items-center justify-between gap-3 mt-2 pt-4 border-t border-vp-border-subtle">
              <Button type="button" variant="ghost" onClick={() => onDone(null)}>
                {t('setUpLater')}
              </Button>
              <Button
                type="submit"
                variant="primary"
                disabled={creating}
                loading={creating}
                aria-busy={creating}
              >
                {creating ? t('createSubmitting') : t('createSubmit')}
              </Button>
            </div>
          </form>
        </StepShell>
      )}

      {step === 'ready' && createdBoard !== null && (
        <StepShell step="ready" labels={stepLabels} ariaLabel={stepsAriaLabel}>
          <span
            aria-hidden="true"
            className="mb-5 inline-flex size-11 items-center justify-center rounded-vp-full bg-vp-vote-up-soft text-vp-vote-up-strong animate-vp-stamp"
          >
            <Check size={22} strokeWidth={2.5} />
          </span>
          <h1 className="font-archivo font-bold text-vp-2xl tracking-[-0.02em] text-vp-ink text-balance mb-1">
            {t('readyTitle', { name: createdBoard.name })}
          </h1>
          <p className="text-vp-md text-vp-text-secondary text-pretty mb-5 max-w-prose">
            {t('readyBody')}
          </p>

          <div className="mb-6">
            <div className="vp-eyebrow mb-1.5 inline-flex items-center gap-1.5">
              <Link2 size={12} aria-hidden="true" />
              {t('publicLinkLabel')}
            </div>
            <div className="flex items-center gap-2 pl-3 pr-1.5 py-1.5 bg-vp-surface-sunken border border-vp-border-subtle rounded-vp-md">
              <code className="flex-1 text-vp-sm font-mono-num text-vp-ink truncate">
                {publicBoardUrl}
              </code>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                icon={copied ? <Check size={14} /> : <Copy size={14} />}
                onClick={handleCopyLink}
              >
                {copied ? t('copied') : t('copy')}
              </Button>
            </div>
          </div>

          <Button
            type="button"
            variant="primary"
            iconEnd={<ArrowRight size={16} />}
            onClick={() => onDone(createdBoard)}
          >
            {t('goToDashboard')}
          </Button>
        </StepShell>
      )}
    </div>
  )
}
