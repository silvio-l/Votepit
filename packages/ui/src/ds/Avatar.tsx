import type { CSSProperties } from 'react'
import { cx } from '../lib/cx'
import type { AvatarTint } from './avatarTint'
import { initialsPaletteFor, isHexColor } from './color'

export { AVATAR_TINTS, type AvatarTint, avatarTintFor } from './avatarTint'

export interface AvatarProps {
  /** Same-origin avatar or logo URL; when absent the initials are shown. */
  src?: string | null
  /** Display name source for initials. Never an e-mail address. */
  name?: string | null
  size?: 'small' | 'default' | 'large'
  /** Initials tint. Default mint; idea authors use avatarTintFor(name) for variety. */
  tint?: AvatarTint
  /**
   * Validated board accent (six-digit hex). Initials then sit on this colour, with the
   * text colour chosen for at least 4.5:1 contrast (see initialsPaletteFor).
   */
  color?: string | null
  className?: string
}

/** Up to two initials from a display name ("Ada Lovelace" -> "AL"). Empty name -> "?". */
export function initialsFrom(name: string | null | undefined): string {
  const parts = (name ?? '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  const first = [...(parts[0] ?? '')][0] ?? ''
  const last = parts.length > 1 ? ([...(parts[parts.length - 1] ?? '')][0] ?? '') : ''
  return (first + last).toUpperCase()
}

/**
 * Round avatar: image when available, otherwise initials on a mint disc or, with a
 * validated `color`, on that accent. Decorative by default (aria-hidden, empty alt).
 */
export function Avatar({
  src,
  name,
  size = 'default',
  tint = 'mint',
  color,
  className,
}: AvatarProps) {
  const useAccent = isHexColor(color) && !src
  const palette = useAccent ? initialsPaletteFor(color.toUpperCase()) : null
  const style: CSSProperties | undefined = palette
    ? { background: palette.background, color: palette.foreground }
    : undefined
  const classes = cx(
    'ds-avatar',
    size === 'small' && 'ds-avatar--small',
    size === 'large' && 'ds-avatar--large',
    !useAccent && tint !== 'mint' && `ds-avatar--tint-${tint}`,
    className,
  )
  if (src) {
    return <img className={classes} src={src} alt="" />
  }
  return (
    <span className={classes} style={style} aria-hidden="true">
      {initialsFrom(name)}
    </span>
  )
}
