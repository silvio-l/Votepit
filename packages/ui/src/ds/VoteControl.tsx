import { useEffect, useId, useRef, useState } from 'react'
import { cx } from '../lib/cx'
import { Icon } from './icons'

export type VoteDirection = 'up' | 'down'

export interface VoteControlProps {
  direction: VoteDirection
  /** Count to display. The caller computes it (see voteSummary.ts) and passes it in. */
  count: number
  /**
   * Committed state from the caller (aria-pressed). The component never flips this
   * itself: the caller decides the next state in `onVote` and passes the new value
   * back. A rejected `onVote` therefore leaves the previous state in place.
   */
  selected?: boolean
  /** Idea title; part of the accessible name, e.g. "Vote for: Offline mode for travel, 24 votes". */
  ideaTitle: string
  /** 'field' = large tinted For/Against field (idea card and detail, owner decision 2026-10-22). */
  size?: 'inline' | 'compact' | 'field'
  /** Counts stay visible; interaction is off and explained in a description. */
  readonly?: boolean
  /** External pending state (e.g. the caller is saving). Blocks activation like the internal one. */
  pending?: boolean
  /** Shows an error message. Used for errors the caller already knows about. */
  errorMessage?: string
  /**
   * Called once per activation. Activations are ignored while a call is pending.
   * A rejection shows the error message; it does not change `selected`.
   */
  onVote?: () => Promise<void> | void
  /** Catalogue only: forces the hover or focus look without real pointer or focus. */
  forceState?: 'hover' | 'focus'
  /** Stable data-testid on the button. */
  testId: string
  /** Visible and announced texts, localized by the app. */
  labels: VoteControlLabels
  /** BCP 47 locale for number formatting. */
  locale: string
}

export interface VoteControlLabels {
  up: { verb: string; label: string }
  down: { verb: string; label: string }
  votesSingular: string
  votesPlural: string
  error: string
  readonly: string
}

/**
 * For/against toggle. Arrow icon, visible label and count; the state is shown by
 * fill, ring and text, never by colour alone. Up and down share size and weight.
 * Hit area is at least 44 × 44 px.
 */
export function VoteControl({
  direction,
  count,
  selected = false,
  ideaTitle,
  size = 'inline',
  readonly = false,
  pending = false,
  errorMessage,
  onVote,
  forceState,
  testId,
  labels,
  locale,
}: VoteControlProps) {
  const text = labels
  const countFormat = new Intl.NumberFormat(locale)
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)
  // A ref blocks a second click in the same tick, before React re-renders with busy=true.
  const inFlight = useRef(false)
  const id = useId()
  const messageId = `${id}-message`
  const readonlyId = `${id}-readonly`
  // Micro-animation (G-12): a short tick on the icon and a count roll, only after a real
  // change of count or selection. The first render stays still. CSS turns it off under
  // prefers-reduced-motion.
  const [pulse, setPulse] = useState(0)
  const previous = useRef({ count, selected })
  useEffect(() => {
    if (previous.current.count === count && previous.current.selected === selected) return
    previous.current = { count, selected }
    setPulse((n) => n + 1)
  }, [count, selected])

  const { verb, label } = text[direction]
  const noun = count === 1 ? text.votesSingular : text.votesPlural
  const accessibleName = `${verb}: ${ideaTitle}, ${countFormat.format(count)} ${noun}`
  const isPending = busy || pending
  const message = errorMessage ?? failure
  const inactive = readonly || !onVote

  const activate = async () => {
    if (inactive || isPending || inFlight.current || !onVote) return
    inFlight.current = true
    setBusy(true)
    setFailure(null)
    try {
      await onVote()
    } catch {
      setFailure(text.error)
    } finally {
      inFlight.current = false
      setBusy(false)
    }
  }

  const describedBy = [readonly ? readonlyId : null, messageId].filter(Boolean).join(' ')

  return (
    <span className="ds-vote-control-wrap">
      <button
        type="button"
        data-testid={testId}
        className={cx(
          'ds-vote-control',
          'ds-focusable',
          `ds-vote-control--${direction}`,
          `ds-vote-control--${size}`,
          message && 'ds-vote-control--error',
          forceState === 'hover' && 'ds-is-hover',
          forceState === 'focus' && 'ds-is-focus',
        )}
        aria-pressed={selected}
        aria-label={accessibleName}
        aria-busy={isPending || undefined}
        aria-disabled={inactive || isPending ? 'true' : undefined}
        aria-describedby={describedBy}
        onClick={activate}
      >
        {isPending ? (
          <span className="ds-spinner ds-vote-control__icon" aria-hidden="true" />
        ) : (
          <span
            key={`tick-${pulse}`}
            className={cx('ds-vote-control__tick', pulse > 0 && 'ds-vote-control__tick--pulse')}
            aria-hidden="true"
          >
            <Icon
              name={direction === 'up' ? 'arrow-up' : 'arrow-down'}
              className="ds-vote-control__icon"
            />
          </span>
        )}
        <span className="ds-vote-control__label">{label}</span>
        <span
          key={`count-${count}`}
          className={cx('ds-vote-control__count', pulse > 0 && 'ds-vote-control__count--roll')}
        >
          {countFormat.format(count)}
        </span>
      </button>
      {readonly && (
        <span id={readonlyId} className="ds-visually-hidden">
          {text.readonly}
        </span>
      )}
      <span id={messageId} role="status" aria-live="polite" className="ds-vote-control__message">
        {message && (
          <>
            <Icon name="error" size="small" />
            <span>{message}</span>
          </>
        )}
      </span>
    </span>
  )
}
