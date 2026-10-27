/**
 * ActivationChecklist — contextual in-app onboarding, shown on the board admin
 * home after the setup wizard. Learning-by-doing instead of a second
 * slideshow: each item links straight to the real UI action it describes.
 *
 * Completion is derived from account data wherever the data exists: idea and
 * vote counts and the members list come from the API, the appearance item
 * from the selected board's branding. Only "link copied" is a browser-side
 * signal (lib/onboarding.ts). The items follow the selected board; the
 * dropdown appears once the account has more than one board.
 *
 * Dismiss state is a per-browser UI nicety (no server field exists for it),
 * so it stays in localStorage. "Einrichtung fortsetzen" reopens the setup
 * wizard for the selected board, even after the wizard was set aside.
 */

import { Button, ButtonLink, cx, Section, Select } from '@votepit/ui/ds'
import { ArrowRight, Check } from 'lucide-react'
import { useEffect, useState } from 'react'
import { accountPath, fullBoardUrl } from '../lib/accountContext'
import type { AdminBoardSummary } from '../lib/api'
import { getAdminBranding, listMembers } from '../lib/api'
import { useT } from '../lib/i18n/context'
import {
  appearanceIsSet,
  type ChecklistItemId,
  checklistProgress,
  markShareCopied,
  readShareCopied,
  reopenSetupTarget,
} from '../lib/onboarding'
import { RouterButtonLink } from './RouterButtonLink'

const DISMISSED_KEY = 'vp_activation_checklist_dismissed'

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
        'inline-flex size-6 shrink-0 items-center justify-center rounded-vp-full border-[1.5px] text-vp-2xs font-mono-num font-semibold transition-colors duration-ds-normal',
        done
          ? 'bg-vp-vote-up border-vp-vote-up text-vp-on-ink'
          : 'border-vp-border-strong bg-vp-surface text-vp-text-secondary',
      )}
    >
      {done ? (
        <Check size={12} strokeWidth={2.5} aria-hidden="true" className="animate-vp-stamp" />
      ) : (
        index + 1
      )}
    </span>
  )
}

export function ActivationChecklist({ boards }: ActivationChecklistProps) {
  const t = useT('activationChecklist')
  const [dismissed, setDismissedState] = useState(isActivationChecklistDismissed)
  const [selectedSlug, setSelectedSlug] = useState<string | null>(null)
  const [memberCount, setMemberCount] = useState<number | null>(null)
  // Appearance of one board, tagged with its slug so a switch never shows stale data.
  const [appearance, setAppearance] = useState<{ slug: string; set: boolean } | null>(null)
  // Bumped after a copy, so the share state is read again from storage.
  const [, setShareVersion] = useState(0)

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

  const board = boards.find((candidate) => candidate.slug === selectedSlug) ?? boards[0]
  const boardSlug = board?.slug ?? null

  useEffect(() => {
    if (boardSlug === null) return
    let cancelled = false
    getAdminBranding(boardSlug)
      .then((branding) => {
        if (!cancelled) setAppearance({ slug: boardSlug, set: appearanceIsSet(branding) })
      })
      .catch(() => {
        // Unknown appearance stays open; the link to the settings is still there.
      })
    return () => {
      cancelled = true
    }
  }, [boardSlug])

  if (board === undefined) return null

  const appearanceSet = appearance?.slug === board.slug && appearance.set
  const shareCopied = readShareCopied(board.slug)
  const states = checklistProgress({
    appearanceSet,
    shareCopied,
    memberCount,
    ideaCount: board.idea_count,
    voteCount: board.vote_count,
  })
  const doneCount = states.filter((item) => item.done).length
  const allDone = doneCount === states.length
  const percent = Math.round((doneCount / states.length) * 100)

  const copyLink = async () => {
    try {
      await navigator.clipboard.writeText(fullBoardUrl(board.slug))
    } catch {
      // Clipboard blocked: the item stays open and the field on the board stays usable.
      return
    }
    markShareCopied(board.slug)
    setShareVersion((version) => version + 1)
  }

  // accountPath keeps the {account-slug} prefix in cloud mode (no-op on self-host).
  const boardAdminPath = accountPath(`/admin/boards/${board.slug}`)
  const links: Record<ChecklistItemId, { label: string; cta: string; to: string | null }> = {
    board: { label: t('boardLabel'), cta: t('boardCta'), to: accountPath(`/${board.slug}`) },
    appearance: {
      label: t('appearanceLabel'),
      cta: t('appearanceCta'),
      to: `${boardAdminPath}/design?group=colors`,
    },
    share: { label: t('shareLabel'), cta: t('shareCta'), to: null },
    team: { label: t('teamLabel'), cta: t('teamCta'), to: accountPath('/admin/members') },
    idea: {
      label: t('ideaLabel'),
      cta: t('ideaCta'),
      to: accountPath(`/${board.slug}/submit`),
    },
    vote: { label: t('voteLabel'), cta: t('voteCta'), to: accountPath(`/${board.slug}`) },
  }

  if (dismissed) {
    return (
      <div className="mb-ds-lg">
        <Button
          type="button"
          variant="text"
          onClick={() => {
            setDismissed(false)
            setDismissedState(false)
          }}
          testId="activation-checklist-show"
        >
          {t('showAgain')}
        </Button>
      </div>
    )
  }

  return (
    <Section
      testId="activation-checklist"
      emphasis="ruled"
      flush
      title={allDone ? t('allDoneHeading') : t('heading')}
      description={allDone ? undefined : t('subtitle')}
      actions={
        <>
          <span className="font-mono-num text-vp-xs text-vp-text-muted" aria-hidden="true">
            {doneCount}/{states.length}
          </span>
          <ButtonLink
            href={reopenSetupTarget(board.slug)}
            linkAs={RouterButtonLink}
            variant="secondary"
            testId="activation-checklist-resume"
          >
            {t('resumeCta')}
            <ArrowRight size={12} aria-hidden="true" />
          </ButtonLink>
          <Button
            type="button"
            variant="text"
            onClick={() => {
              setDismissed(true)
              setDismissedState(true)
            }}
            aria-label={t('hideAriaLabel')}
            testId="activation-checklist-hide"
          >
            {t('hide')}
          </Button>
        </>
      }
      className="mb-ds-lg animate-vp-fade-in motion-reduce:animate-none"
    >
      {boards.length > 1 && (
        <div className="px-ds-md sm:px-ds-lg pt-ds-md max-w-xs">
          <Select
            label={t('boardSelectLabel')}
            id="activation-checklist-board"
            name="board"
            value={board.slug}
            onChange={(value) => setSelectedSlug(value)}
            testId="activation-checklist-board-select"
          >
            {boards.map((candidate) => (
              <option key={candidate.slug} value={candidate.slug}>
                {candidate.name}
              </option>
            ))}
          </Select>
        </div>
      )}

      <div className="px-ds-md sm:px-ds-lg pt-ds-sm pb-ds-sm">
        <div
          role="progressbar"
          aria-label={t('progressAriaLabel')}
          aria-valuemin={0}
          aria-valuemax={states.length}
          aria-valuenow={doneCount}
          aria-valuetext={t('progressLabel', { done: doneCount, total: states.length })}
          className="h-1.5 w-full rounded-vp-full bg-vp-surface-sunken overflow-hidden"
        >
          <div
            className="h-full rounded-vp-full bg-vp-vote-up transition-[width] duration-ds-emphasis ease-vp-expo motion-reduce:transition-none"
            style={{ width: `${percent}%` }}
          />
        </div>
      </div>

      <ul className="grid grid-cols-1 md:grid-cols-2 gap-px bg-vp-border-subtle border-t border-vp-border-subtle overflow-hidden list-none m-0 p-0">
        {states.map((item, i) => {
          const link = links[item.id]
          return (
            <li
              key={item.id}
              data-testid={`activation-checklist-item-${item.id}`}
              className={cx(
                'flex items-center justify-between gap-ds-sm px-ds-md sm:px-ds-lg py-ds-sm min-h-12',
                item.done ? 'bg-vp-surface-frost' : 'bg-vp-surface',
              )}
            >
              <span className="flex items-center gap-ds-sm text-vp-base min-w-0">
                <CheckMark done={item.done} index={i} />
                <span
                  className={cx(
                    'min-w-0',
                    item.done ? 'text-vp-text-muted line-through' : 'text-vp-ink font-medium',
                  )}
                >
                  {link.label}
                </span>
              </span>
              {!item.done && item.id === 'share' && (
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => void copyLink()}
                  className="shrink-0 min-h-11"
                  testId="activation-cta-share"
                >
                  {link.cta}
                </Button>
              )}
              {!item.done && item.id !== 'share' && link.to !== null && (
                <ButtonLink
                  href={link.to}
                  linkAs={RouterButtonLink}
                  variant="secondary"
                  className="shrink-0 min-h-11"
                  testId={`activation-cta-${item.id}`}
                >
                  {link.cta}
                  <ArrowRight size={12} aria-hidden="true" />
                </ButtonLink>
              )}
            </li>
          )
        })}
      </ul>
      {allDone && <span className="sr-only">{t('allDoneHeading')}</span>}
    </Section>
  )
}
