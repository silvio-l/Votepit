import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

/** Arrow pointing up, centred on (0, 0). Same path data as BoardCover's motif (our own shapes). */
const ARROW_UP = 'M0 -23 L23 0 L13 0 L13 23 L-13 23 L-13 0 L-23 0 Z'

/**
 * Illustration for the not-found page: a dashed trail that ends at an empty
 * target ring, and a Votepit arrow that has drifted off the trail. Drawn from
 * our own SVG shapes only; colours come from DS tokens via CSS classes (no
 * inline style, CSP-safe). Decorative, so it is hidden from assistive tech.
 */
export function NotFoundArt({ className }: { className?: string }) {
  return (
    <svg
      className={cx('ds-not-found__art', className)}
      viewBox="0 0 240 150"
      aria-hidden="true"
      focusable="false"
    >
      <circle className="ds-not-found__art-disc" cx="120" cy="75" r="64" />
      <path
        className="ds-not-found__art-trail"
        pathLength={1}
        d="M40 116 C 70 116, 80 84, 112 88 S 166 60, 178 46"
      />
      <circle className="ds-not-found__art-start" cx="40" cy="116" r="5" />
      <circle className="ds-not-found__art-target" cx="186" cy="38" r="17" />
      <g transform="translate(92 74) rotate(-30) scale(0.95)">
        <g className="ds-not-found__art-arrow">
          <path d={ARROW_UP} />
        </g>
      </g>
    </svg>
  )
}

export interface NotFoundProps {
  /** Page H1 — states the outcome, never blames the visitor. */
  title: string
  /** Plain-language explanation and the way on. */
  description?: ReactNode
  /** Primary action first (accent), secondary controls after. Each one needs a testId. */
  actions?: ReactNode
  /** Brand header above the sheet (the app passes the edition-aware logo). */
  header?: ReactNode
  /** Turns the one-shot illustration motion on; the app sets false under prefers-reduced-motion. */
  motion?: boolean
  testId?: string
}

/**
 * Full-page "not found" layout, shared by every not-found case that has no
 * other page to show (unknown board, unknown roadmap, unknown route). Copy and
 * the destination are passed in, so the component reveals nothing about what
 * exists.
 */
export function NotFound({
  title,
  description,
  actions,
  header,
  motion = true,
  testId = 'not-found-page',
}: NotFoundProps) {
  return (
    <div className="ds-not-found" data-motion={motion ? 'on' : 'off'} data-testid={testId}>
      {header && <div className="ds-not-found__header">{header}</div>}
      <main className="ds-not-found__sheet">
        <NotFoundArt />
        <p className="ds-not-found__code" aria-hidden="true">
          404
        </p>
        <h1 className="ds-not-found__title" data-testid="not-found-title">
          {title}
        </h1>
        {description && (
          <p className="ds-not-found__text" data-testid="not-found-message">
            {description}
          </p>
        )}
        {actions && <div className="ds-not-found__actions">{actions}</div>}
      </main>
    </div>
  )
}
