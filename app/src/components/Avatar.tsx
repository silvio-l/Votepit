/**
 * Avatar — small circular profile picture, or a deterministic placeholder
 * when none is set (profile-avatar-social).
 *
 * NOTE on "initials": the ticket's default fallback description assumes a
 * display name to derive initials from. This app deliberately has NO
 * username/display-name concept anywhere (ADR 0002 — identity is a
 * pseudonymized email HMAC only) — every other surface already shows "You" /
 * "Voter" instead of a name (AuthorBadge.tsx). Deriving initials
 * from anything user-controlled here would either require inventing a new
 * PII-adjacent display-name field (out of scope) or would be empty for
 * everyone. The fallback is therefore a neutral, deterministic silhouette
 * glyph — same idea (no third-party gravatar-style service, no identity
 * leak), adapted to what this app actually has.
 */

interface AvatarProps {
  avatarUrl: string | null
  size?: number
  alt?: string
  className?: string
}

export function Avatar({ avatarUrl, size = 28, alt = '', className = '' }: AvatarProps) {
  const style = { width: size, height: size }

  if (avatarUrl !== null) {
    return (
      // eslint-disable-next-line jsx-a11y/alt-text -- alt is passed through as a prop
      <img
        src={avatarUrl}
        alt={alt}
        style={style}
        className={`inline-block shrink-0 rounded-full object-cover border border-vp-rule ${className}`}
      />
    )
  }

  return (
    <span
      style={style}
      role="img"
      aria-label={alt}
      className={`inline-flex shrink-0 items-center justify-center rounded-full border border-vp-rule bg-vp-surface-sunken text-vp-text-muted ${className}`}
    >
      <svg viewBox="0 0 24 24" width="60%" height="60%" fill="currentColor" aria-hidden="true">
        <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.24-8 5v1a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-1c0-2.76-3.58-5-8-5Z" />
      </svg>
    </span>
  )
}
