/**
 * Share a link: the Web Share API when the browser offers it, otherwise copy the link
 * to the clipboard. Pure helper (no React), so it can be tested without a DOM.
 */

export type ShareOutcome = 'shared' | 'copied' | 'cancelled' | 'failed'

export interface ShareEnvironment {
  share?: (data: { title?: string; url: string }) => Promise<void>
  canShare?: (data: { title?: string; url: string }) => boolean
  writeText?: (text: string) => Promise<void>
}

/** Reads the browser's sharing and clipboard capabilities (undefined when absent). */
export function browserShareEnvironment(): ShareEnvironment {
  const nav = typeof navigator === 'undefined' ? undefined : navigator
  return {
    share: nav?.share ? (data) => nav.share(data) : undefined,
    canShare: nav?.canShare ? (data) => nav.canShare(data) : undefined,
    writeText: nav?.clipboard?.writeText ? (text) => nav.clipboard.writeText(text) : undefined,
  }
}

/**
 * Shares `url` with the native sheet when possible. A user cancelling the sheet is not
 * an error. When sharing is not available, the link is copied instead; if that fails too,
 * the outcome is 'failed' and the caller shows its own fallback message.
 */
export async function shareLink(
  url: string,
  title: string,
  env: ShareEnvironment = browserShareEnvironment(),
): Promise<ShareOutcome> {
  const data = { title, url }
  const canNativeShare = env.share !== undefined && (env.canShare?.(data) ?? true)
  if (canNativeShare && env.share) {
    try {
      await env.share(data)
      return 'shared'
    } catch (error) {
      if (error instanceof Error && error.name === 'AbortError') return 'cancelled'
      // Any other share error: fall through to copying the link.
    }
  }
  if (env.writeText) {
    try {
      await env.writeText(url)
      return 'copied'
    } catch {
      return 'failed'
    }
  }
  return 'failed'
}
