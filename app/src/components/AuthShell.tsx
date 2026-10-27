/**
 * AuthShell — the one frame for every page that lives outside an account scope
 * and outside the app chrome: login, signup, verify, invite accept, password
 * reset, OAuth consent, the setup wizard's terminal states and the digest
 * unsubscribe page.
 *
 * Same family as the landing (votepit.com, direction A): porcelain paper, the
 * wordmark with the edition label top left, the language switch top right, one
 * forest band on wide screens that carries only the landing's one-line promise,
 * a single white task card, one way back to the landing, and one quiet trust
 * line. Nothing else competes with the task on the sheet.
 */

import { LegalFooter, VotepitLogo } from '@votepit/ui'
import { cx, Spinner } from '@votepit/ui/ds'
import type { ReactNode } from 'react'
import { type Edition, getEdition } from '../lib/edition'
import { legalLinksFor } from '../lib/features'
import { useI18n, useT } from '../lib/i18n/context'
import { LanguagePicker } from './LanguagePicker'
import { ThemeChoice } from './ThemeChoice'

interface AuthShellProps {
  children: ReactNode
  /** Rendered below the card in muted text (one secondary link at most). */
  footer?: ReactNode
}

/**
 * Where the auth pages send people back to. Cloud: the marketing site, in the
 * visitor's language where the landing has that locale (English lives at the
 * root). Community/self-host: this instance's own start page.
 */
export function authHomeHref(edition: Edition, language: string): string {
  if (edition !== 'cloud') return '/'
  return language === 'en' ? 'https://votepit.com/' : `https://votepit.com/${language}/`
}

export function AuthShell({ children, footer }: AuthShellProps) {
  const { language } = useI18n()
  const t = useT('common')
  const edition = getEdition()
  const homeHref = authHomeHref(edition, language)
  const legalLinks = legalLinksFor(language)
  const trust = edition === 'cloud' ? t('auth.trustCloud') : t('auth.trustCommunity')

  return (
    <div className="min-h-screen vp-desk flex flex-col lg:flex-row">
      {/* Forest band — wide screens only, one line of promise and nothing else */}
      <aside
        aria-label={t('auth.asideAriaLabel')}
        className="hidden lg:flex vp-ink-panel w-vp-auth-panel max-w-vp-auth-panel shrink-0 flex-col justify-center p-10 xl:p-14"
      >
        <p className="font-archivo font-extrabold text-[clamp(2.4rem,1.1rem+3.6vw,4.1rem)] leading-[1.02] tracking-[-0.035em] text-balance max-w-[16ch] m-0">
          {t('auth.tagline')}
        </p>
      </aside>

      <div className="flex-1 min-w-0 flex flex-col">
        <header className="flex justify-center px-4 sm:px-8 h-vp-auth-header">
          <div className="flex w-full max-w-vp-auth-form items-center justify-between gap-3">
            <VotepitLogo
              href={homeHref}
              edition={edition}
              size="lg"
              ariaLabel={t('header.logoAriaLabel')}
            />
            <LanguagePicker />
          </div>
        </header>

        <main className="flex-1 flex flex-col items-center px-4 pt-6 pb-8 sm:pt-12">
          <div className="w-full max-w-vp-auth-form flex flex-col gap-4">
            <a
              href={homeHref}
              data-testid="auth-back-home"
              className="self-start inline-flex items-center gap-1.5 rounded-vp-xs text-vp-sm text-vp-text-secondary hover:text-vp-title hover:underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-vp-ink"
            >
              <span aria-hidden="true">←</span>
              {edition === 'cloud' ? t('auth.backHomeCloud') : t('auth.backHomeCommunity')}
            </a>
            <div className="vp-sheet p-6 sm:p-8 animate-vp-rise">{children}</div>
            {footer && (
              <div className="text-center text-vp-sm text-vp-text-secondary">{footer}</div>
            )}
            <p
              data-testid="auth-trust-line"
              className="m-0 text-center text-vp-xs text-vp-text-muted"
            >
              {trust}
            </p>
          </div>
        </main>

        <div className="px-4 sm:px-8 pb-6 flex flex-col items-center gap-2">
          <ThemeChoice />
          <LegalFooter legalLinks={legalLinks} className="justify-center" />
        </div>
      </div>
    </div>
  )
}

interface AuthOutcomeProps {
  tone: 'success' | 'error' | 'info' | 'pending'
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
          <Heading className="font-archivo font-bold text-vp-2xl tracking-vp-heading leading-vp-heading text-vp-title text-balance m-0">
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
        <Spinner className="text-vp-title" />
        <span aria-hidden="true" className="ds-skeleton__block h-1 w-16" />
      </span>
    )
  }
  const isSuccess = tone === 'success'
  const isInfo = tone === 'info'
  return (
    <span
      aria-hidden="true"
      className={cx(
        'flex items-center justify-center size-12 rounded-vp-full animate-vp-stamp',
        isSuccess && 'bg-vp-vote-up-soft text-vp-vote-up-strong',
        isInfo && 'bg-vp-info-soft text-vp-info-strong',
        !isSuccess && !isInfo && 'bg-vp-vote-down-soft text-vp-vote-down-strong',
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
        {isSuccess && <path d="M3.5 8.5l3 3 6-7" />}
        {isInfo && <path d="M8 7v4.5M8 4.6v.01" />}
        {!isSuccess && !isInfo && <path d="M4.5 4.5l7 7M11.5 4.5l-7 7" />}
      </svg>
    </span>
  )
}

/** Page heading + one-line intro used at the top of every auth form. */
export function AuthHeading({ title, intro }: { title: string; intro?: string }) {
  return (
    <div className="mb-6">
      <h1 className="font-archivo font-bold text-vp-2xl tracking-vp-heading leading-vp-heading text-vp-title text-balance m-0">
        {title}
      </h1>
      {intro && (
        <p className="mt-1.5 mb-0 text-vp-base text-vp-text-secondary leading-6">{intro}</p>
      )}
    </div>
  )
}
