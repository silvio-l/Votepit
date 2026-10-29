/** Initials tints for avatars. Pure module (no React) so Astro pages can import it too. */
export const AVATAR_TINTS = ['mint', 'sage', 'positive', 'info', 'warning', 'negative'] as const
export type AvatarTint = (typeof AVATAR_TINTS)[number]

/** Stable tint per name (simple string hash), so the same author always gets the same colour. */
export function avatarTintFor(name: string | null | undefined): AvatarTint {
  const source = (name ?? '').trim()
  let hash = 0
  for (const ch of source) hash = (hash * 31 + (ch.codePointAt(0) ?? 0)) >>> 0
  return AVATAR_TINTS[hash % AVATAR_TINTS.length] ?? 'mint'
}
