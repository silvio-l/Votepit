/**
 * BoardBrandFooter — the board's own footer extras, shown the same way on every
 * board page: the footer note, the back link to the customer's site, the legal
 * operator line and the "Powered by Votepit" badge (unless the plan hides it).
 * The values are plan-gated server-side (BoardBrandingView / BoardHomeAction);
 * a missing value renders nothing, so a Free board shows only the Votepit defaults.
 */

import { useT } from '../lib/i18n/context'
import { BoardOperatorFooter } from './BoardOperatorFooter'
import { ThemeChoice } from './ThemeChoice'

interface BoardBrandFooterProps {
  slug: string
  legalOperatorName: string | null | undefined
  visual: {
    show_badge?: boolean
    back_link?: { url: string; label: string } | null
    footer_note?: string | null
  }
}

export function BoardBrandFooter({ slug, legalOperatorName, visual }: BoardBrandFooterProps) {
  const t = useT('boardPage')
  const showBadge = visual.show_badge === true

  return (
    <>
      {visual.footer_note ? (
        <p
          className="mt-10 text-center text-vp-xs text-vp-text-muted"
          data-testid="board-footer-note"
        >
          {visual.footer_note}
        </p>
      ) : null}
      {visual.back_link ? (
        <p className="mt-2 text-center text-vp-sm" data-testid="board-back-link-wrap">
          <a
            href={visual.back_link.url}
            rel="noopener nofollow ugc"
            className="underline underline-offset-2"
            data-testid="board-back-link"
          >
            {visual.back_link.label}
          </a>
        </p>
      ) : null}
      {(legalOperatorName || showBadge) && (
        <div className="mt-10 space-y-1">
          <BoardOperatorFooter boardSlug={slug} legalOperatorName={legalOperatorName ?? null} />
          {showBadge && (
            <p className="text-center text-vp-xs">
              <a
                href="https://votepit.com"
                target="_blank"
                rel="noopener noreferrer"
                className="text-vp-text-muted font-medium underline decoration-2 underline-offset-[0.2em] decoration-vp-underline transition-colors hover:text-vp-title hover:decoration-current"
              >
                {t('poweredBy')}
              </a>
            </p>
          )}
        </div>
      )}
      {/* Logged-out visitors reach the colour scheme here (the account menu needs a session). */}
      <div className="mt-8 flex justify-center">
        <ThemeChoice />
      </div>
    </>
  )
}
