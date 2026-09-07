import { faGlobe } from '@fortawesome/free-solid-svg-icons'
import { faGithub, faXTwitter, faYoutube } from '@fortawesome/free-brands-svg-icons'
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome'

export type SocialPlatform = 'website' | 'x' | 'youtube' | 'github'

interface SocialIconProps {
  platform: SocialPlatform
  size?: number
  className?: string
}

const ICONS = {
  website: faGlobe,
  x: faXTwitter,
  youtube: faYoutube,
  github: faGithub,
} as const

/**
 * Real brand marks for the 4 fixed social-link identifiers
 * (profile-avatar-social) — Font Awesome, single-icon imports only (no
 * `fontawesome-free` full bundle) so each glyph tree-shakes independently.
 * Rendered in `currentColor`, one colour, no brand fills — these sit inside
 * form fields, not on a marketing surface, so they stay quiet rather than
 * advertising each brand.
 */
export function SocialIcon({ platform, size = 16, className }: SocialIconProps) {
  return (
    <FontAwesomeIcon
      icon={ICONS[platform]}
      style={{ width: size, height: size }}
      className={className}
      aria-hidden="true"
    />
  )
}
