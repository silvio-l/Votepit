import { Button, IconButton } from './Button'
import { type ShareOutcome, shareLink } from './share'

export interface ShareButtonProps {
  /** Absolute link to share (the board or idea URL). */
  url: string
  title: string
  /** Visible text, or the accessible name when `iconOnly`. */
  label: string
  /** Phones: an icon button with an accessible name, 44 px. */
  iconOnly?: boolean
  /** Called with the outcome so the page can show its own toast. */
  onResult?: (outcome: ShareOutcome) => void
  /** Stable data-testid on the button. */
  testId: string
}

/** Shares a link via the Web Share API, falling back to copying it (see shareLink). */
export function ShareButton({
  url,
  title,
  label,
  iconOnly = false,
  onResult,
  testId,
}: ShareButtonProps) {
  const handleClick = async () => {
    const outcome = await shareLink(url, title)
    onResult?.(outcome)
  }
  if (iconOnly) {
    return (
      <IconButton
        icon="share"
        accessibleLabel={label}
        testId={testId}
        onClick={() => void handleClick()}
      />
    )
  }
  return (
    <Button variant="secondary" icon="share" testId={testId} onClick={() => void handleClick()}>
      {label}
    </Button>
  )
}
