/**
 * AuthShell — the shared frame for every page that lives outside an account
 * scope and outside the app chrome: Login, Signup, Verify, InviteAccept,
 * password reset.
 *
 * Two panels on wide screens: an ink panel on the left that carries the
 * wordmark, the product's one-line promise and three drawn ballot lines
 * (the product's own iconography, not stock art); the task card on the
 * right. Below `lg` the ink panel folds away and only the card remains —
 * nothing competes with the single task on the sheet. Uses @votepit/ui's
 * shared VotepitLogo (edition lockup), matching LocalizedHeader.
 */

import { cx, LegalFooter, Spinner, VotepitLogo } from '@votepit/ui'
import type { ReactNode } from 'react'
import { getEdition } from '../lib/edition'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'
import { LanguageToggle } from './LanguageToggle'

interface AuthShellProps {
  /** Optional context link rendered above the sheet ("‹ Back to board"). */
  back?: ReactNode
  children: ReactNode
  /** Rendered below the sheet in muted text (secondary links). */
  footer?: ReactNode
}

/** Three ballot lines in the ink panel: for, blank, against. */
function BallotLines() {
  return (
    <svg
      viewBox="0 0 240 104"
      width="240"
      height="104"
      fill="none"
      aria-hidden="true"
      className="max-w-full h-auto"
    >
      <g strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <rect x="1" y="1" width="26" height="26" stroke="var(--color-vp-vote-up)" />
        <path d="M8 14.5l5 5 8.5-10" stroke="var(--color-vp-vote-up)" strokeWidth="2.25" />
        <path d="M42 14h150" stroke="var(--color-vp-rule-on-ink)" strokeWidth="2" />
        <path d="M42 14h96" stroke="var(--color-vp-on-ink-muted)" strokeWidth="2" />

        <rect x="1" y="39" width="26" height="26" stroke="var(--color-vp-rule-on-ink)" />
        <path d="M42 52h190" stroke="var(--color-vp-rule-on-ink)" strokeWidth="2" />
        <path d="M42 52h58" stroke="var(--color-vp-on-ink-muted)" strokeWidth="2" />

        <rect x="1" y="77" width="26" height="26" stroke="var(--color-vp-vote-down)" />
        <path d="M9 85l10 10M19 85l-10 10" stroke="var(--color-vp-vote-down)" strokeWidth="2.25" />
        <path d="M42 90h120" stroke="var(--color-vp-rule-on-ink)" strokeWidth="2" />
        <path d="M42 90h30" stroke="var(--color-vp-on-ink-muted)" strokeWidth="2" />
      </g>
    </svg>
  )
}

export function AuthShell({ back, children, footer }: AuthShellProps) {
  const { language } = useI18n()
  const t = useT('common')
  const edition = getEdition()
  const legalLinks = legalLinksFor(language)
  const points = [t('auth.point1'), t('auth.point2'), t('auth.point3')]

  return (
    <div className="min-h-screen vp-desk flex flex-col lg:flex-row">
      {/* Ink panel — wide screens only */}
      <aside
        aria-label={t('auth.asideAriaLabel')}
        className="hidden lg:flex vp-ink-panel relative w-[42%] max-w-[40rem] shrink-0 flex-col justify-between p-10 xl:p-14 overflow-hidden"
      >
        <div className="relative z-[1]">
          <VotepitLogo edition={edition} ariaLabel={t('header.logoAriaLabel')} onInk />
        </div>

        <div className="relative z-[1] flex flex-col gap-8 max-w-md">
          <div className="animate-vp-rise">
            <BallotLines />
          </div>
          <h2 className="font-archivo font-extrabold text-vp-3xl xl:text-vp-4xl tracking-[-0.03em] leading-[1.02] text-vp-on-ink text-balance">
            {t('auth.tagline')}
          </h2>
          <ul className="m-0 p-0 list-none flex flex-col gap-3 vp-stagger">
            {points.map((point) => (
              <li
                key={point}
                className="flex items-start gap-3 text-vp-base text-vp-on-ink-muted leading-6 animate-vp-fade-in"
              >
                <span
                  aria-hidden="true"
                  className="mt-1 flex size-4 shrink-0 items-center justify-center rounded-vp-xs border border-vp-vote-up text-vp-vote-up"
                >
                  <svg
                    aria-hidden="true"
                    viewBox="0 0 16 16"
                    width="10"
                    height="10"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  >
                    <path d="M3.5 8.5l3 3 6-7" />
                  </svg>
                </span>
                {point}
              </li>
            ))}
          </ul>
        </div>

        <div className="relative z-[1] flex flex-wrap items-center gap-x-4 gap-y-1 text-vp-xs text-vp-on-ink-muted">
          <span className="font-mono-num uppercase tracking-[0.08em]">Votepit</span>
          {legalLinks.map((link) => (
            <a
              key={link.href}
              href={link.href}
              className="no-underline hover:text-vp-on-ink hover:underline decoration-2 underline-offset-[0.2em]"
            >
              {link.label}
            </a>
          ))}
        </div>
      </aside>

      {/* Task column */}
      <div className="flex-1 min-w-0 flex flex-col">
        <div className="flex items-center justify-between gap-3 px-4 sm:px-8 h-vp-topbar">
          <div className="lg:hidden">
            <VotepitLogo edition={edition} ariaLabel={t('header.logoAriaLabel')} />
          </div>
          <div className="hidden lg:block" />
          <LanguageToggle />
        </div>

        <main className="flex-1 flex items-center justify-center px-4 py-8 sm:py-12">
          <div className="w-full max-w-[26rem]">
            {back && <div className="mb-3 text-vp-sm">{back}</div>}
            <div className="vp-sheet vp-sheet--ruled p-6 sm:p-8 animate-vp-rise">{children}</div>
            {footer && (
              <div className="mt-5 text-center text-vp-sm text-vp-text-muted">{footer}</div>
            )}
          </div>
        </main>

        <div className="lg:hidden px-4 sm:px-8">
          <LegalFooter legalLinks={legalLinks} className="justify-center" />
        </div>
      </div>
    </div>
  )
}

interface AuthOutcomeProps {
  tone: 'success' | 'error' | 'pending'
  title?: string
  children?: ReactNode
  action?: ReactNode
  /** ARIA role for the message body. */
  role?: 'alert' | 'status'
  headingLevel?: 'h1' | 'h2'
}

/**
 * The terminal state of an auth flow: link sent, invite accepted, link
 * invalid. One stamped glyph, a heading, one line of copy, one way forward.
 */
export function AuthOutcome({
  tone,
  title,
  children,
  action,
  role = tone === 'error' ? 'alert' : 'status',
  headingLevel: Heading = 'h2',
}: AuthOutcomeProps) {
  return (
    <div className="flex flex-col gap-4">
      <OutcomeMark tone={tone} />
      <div className="flex flex-col gap-2">
        {title && (
          <Heading className="font-archivo font-bold text-vp-2xl tracking-[-0.025em] leading-[1.15] text-vp-ink text-balance">
            {title}
          </Heading>
        )}
        {children && (
          <div role={role} className="text-vp-base text-vp-text-secondary leading-6">
            {children}
          </div>
        )}
      </div>
      {action && <div className="mt-1">{action}</div>}
    </div>
  )
}

/**
 * The stamped outcome glyph — a check on the FOR tint, a cross on the AGAINST
 * tint, or a spinner while pending. Shared by every terminal screen (auth
 * outcomes, signup success, setup wizard done, 2FA enabled) so the app has
 * exactly one "done" mark.
 */
export function OutcomeMark({ tone }: { tone: AuthOutcomeProps['tone'] }) {
  if (tone === 'pending') {
    return (
      <span className="flex items-center gap-3 text-vp-text-muted">
        <Spinner className="text-vp-ink" />
        <span aria-hidden="true" className="block h-1 w-16 vp-skeleton" />
      </span>
    )
  }
  const isSuccess = tone === 'success'
  return (
    <span
      aria-hidden="true"
      className={cx(
        'flex items-center justify-center size-12 rounded-vp-full animate-vp-stamp',
        isSuccess
          ? 'bg-vp-vote-up-soft text-vp-vote-up-strong'
          : 'bg-vp-vote-down-soft text-vp-vote-down-strong',
      )}
    >
      <svg
        aria-hidden="true"
        viewBox="0 0 16 16"
        width="22"
        height="22"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        {isSuccess ? <path d="M3.5 8.5l3 3 6-7" /> : <path d="M4.5 4.5l7 7M11.5 4.5l-7 7" />}
      </svg>
    </span>
  )
}

/** Page heading + one-line intro used at the top of every auth form. */
export function AuthHeading({ title, intro }: { title: string; intro?: string }) {
  return (
    <div className="mb-6">
      <h1 className="font-archivo font-bold text-vp-2xl tracking-[-0.025em] leading-[1.15] text-vp-ink text-balance">
        {title}
      </h1>
      {intro && <p className="mt-1.5 text-vp-base text-vp-text-secondary leading-6">{intro}</p>}
    </div>
  )
}
