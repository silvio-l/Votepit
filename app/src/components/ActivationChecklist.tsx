/**
 * ActivationChecklist — contextual in-app onboarding, phase 2 of the
 * onboarding architecture (BoardsAdminPage.tsx, right after the Setup
 * Wizard). Learning-by-doing instead of a second slideshow: each item links
 * straight to the real UI action it describes, and completion is derived
 * from actual account data (idea/vote counts already returned by
 * GET /admin/boards, plus a lightweight GET /admin/members) — never a
 * separate "tour progress" flag that could drift from reality.
 *
 * Dismiss/reopen state alone is NOT business data — it's a per-browser UI
 * nicety (which is fine to lose on a different device/browser), so it lives
 * in localStorage rather than adding a second backend flag next to
 * accounts.onboarding_completed_at.
 */

import { Button, buttonClassName, cx } from '@votepit/ui'
import { ArrowRight } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { accountPath } from '../lib/accountContext'
import type { AdminBoardSummary } from '../lib/api'
import { listMembers } from '../lib/api'
import { useT } from '../lib/i18n/context'

const DISMISSED_KEY = 'vp_activation_checklist_dismissed'

interface ChecklistItem {
  id: string
  label: string
  done: boolean
  to: string
  cta: string
}

interface ActivationChecklistProps {
  boards: AdminBoardSummary[]
}

function isActivationChecklistDismissed(): boolean {
  try {
    return localStorage.getItem(DISMISSED_KEY) === '1'
  } catch {
    return false
  }
}

function setDismissed(value: boolean) {
  try {
    if (value) {
      localStorage.setItem(DISMISSED_KEY, '1')
    } else {
      localStorage.removeItem(DISMISSED_KEY)
    }
  } catch {
    // Storage unavailable (private mode/quota) — checklist just stays
    // visible every load, a harmless degradation.
  }
}

function CheckMark({ done, index }: { done: boolean; index: number }) {
  return (
    <span
      aria-hidden="true"
      className={cx(
        'inline-flex size-6 shrink-0 items-center justify-center rounded-full border-[1.5px] text-vp-2xs font-mono-num font-semibold transition-colors duration-200',
        done
          ? 'bg-vp-vote-up border-vp-vote-up text-white'
          : 'border-vp-border-strong bg-vp-surface text-vp-text-secondary',
      )}
    >
      {done ? (
        <svg
          aria-hidden="true"
          viewBox="0 0 16 16"
          width="12"
          height="12"
          fill="none"
          stroke="currentColor"
          strokeWidth="2.5"
          strokeLinecap="round"
          strokeLinejoin="round"
          className="animate-vp-stamp"
        >
          <path d="M3.5 8.5l3 3 6-7" />
        </svg>
      ) : (
        index + 1
      )}
    </span>
  )
}

export function ActivationChecklist({ boards }: ActivationChecklistProps) {
  const t = useT('activationChecklist')
  const [dismissed, setDismissedState] = useState(isActivationChecklistDismissed)
  const [memberCount, setMemberCount] = useState<number | null>(null)

  useEffect(() => {
    let cancelled = false
    listMembers()
      .then((data) => {
        if (!cancelled) setMemberCount(data.members.length)
      })
      .catch(() => {
        // Best-effort signal only — "invite your team" simply stays open.
      })
    return () => {
      cancelled = true
    }
  }, [])

  if (boards.length === 0) return null

  const firstBoard = boards[0]
  const totalIdeas = boards.reduce((sum, b) => sum + b.idea_count, 0)
  const totalVotes = boards.reduce((sum, b) => sum + b.vote_count, 0)

  // accountPath keeps the {account-slug} prefix in cloud mode (no-op on self-host).
  const items: ChecklistItem[] = [
    {
      id: 'board',
      label: t('boardLabel'),
      done: true,
      to: accountPath(`/${firstBoard.slug}`),
      cta: t('boardCta'),
    },
    {
      id: 'idea',
      label: t('ideaLabel'),
      done: totalIdeas > 0,
      to: accountPath(`/${firstBoard.slug}/submit`),
      cta: t('ideaCta'),
    },
    {
      id: 'vote',
      label: t('voteLabel'),
      done: totalVotes > 0,
      to: accountPath(`/${firstBoard.slug}`),
      cta: t('voteCta'),
    },
    {
      id: 'team',
      label: t('teamLabel'),
      done: (memberCount ?? 1) > 1,
      to: accountPath('/admin/members'),
      cta: t('teamCta'),
    },
  ]

  const allDone = items.every((item) => item.done)
  const doneCount = items.filter((item) => item.done).length
  const percent = Math.round((doneCount / items.length) * 100)

  if (dismissed) {
    return (
      <div className="mb-6">
        <Button
          type="button"
          variant="link"
          size="sm"
          onClick={() => {
            setDismissed(false)
            setDismissedState(false)
          }}
        >
          {t('showAgain')}
        </Button>
      </div>
    )
  }

  return (
    <section aria-label={t('heading')} className="vp-card vp-sheet--accent mb-6 animate-vp-fade-in">
      <div className="flex items-start justify-between gap-4 px-4 sm:px-5 pt-4 pb-3">
        <div className="min-w-0">
          <h2 className="text-vp-md font-semibold text-vp-ink leading-6">
            {allDone ? t('allDoneHeading') : t('heading')}
          </h2>
          {!allDone && <p className="text-vp-sm text-vp-text-secondary mt-0.5">{t('subtitle')}</p>}
        </div>
        <div className="flex items-center gap-3 shrink-0">
          <span className="font-mono-num text-vp-xs text-vp-text-muted" aria-hidden="true">
            {doneCount}/{items.length}
          </span>
          <Button
            type="button"
            variant="ghost"
            size="xs"
            onClick={() => {
              setDismissed(true)
              setDismissedState(true)
            }}
            aria-label={t('hideAriaLabel')}
          >
            {t('hide')}
          </Button>
        </div>
      </div>

      <div className="px-4 sm:px-5 pb-3">
        <div
          role="progressbar"
          aria-label={t('progressAriaLabel')}
          aria-valuemin={0}
          aria-valuemax={items.length}
          aria-valuenow={doneCount}
          aria-valuetext={t('progressLabel', { done: doneCount, total: items.length })}
          className="h-1.5 w-full rounded-vp-full bg-vp-surface-sunken overflow-hidden"
        >
          <div
            className="h-full rounded-vp-full bg-vp-vote-up transition-[width] duration-500 ease-vp-expo"
            style={{ width: `${percent}%` }}
          />
        </div>
      </div>

      <ul className="grid grid-cols-1 md:grid-cols-2 gap-px bg-vp-border-subtle border-t border-vp-border-subtle rounded-b-[inherit] overflow-hidden list-none m-0 p-0">
        {items.map((item, i) => (
          <li
            key={item.id}
            className={cx(
              'flex items-center justify-between gap-3 px-4 sm:px-5 py-3 min-h-12',
              item.done ? 'bg-vp-surface-frost' : 'bg-vp-surface',
            )}
          >
            <span className="flex items-center gap-3 text-vp-base min-w-0">
              <CheckMark done={item.done} index={i} />
              <span
                className={cx(
                  'truncate',
                  item.done ? 'text-vp-text-muted line-through' : 'text-vp-ink font-medium',
                )}
              >
                {item.label}
              </span>
            </span>
            {!item.done && (
              <Link to={item.to} className={buttonClassName('secondary', 'xs', 'shrink-0')}>
                {item.cta}
                <ArrowRight size={12} aria-hidden="true" />
              </Link>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}
