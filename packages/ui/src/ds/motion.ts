/** Media query for viewers who asked the OS for less motion. Mirrors the CSS `prefers-reduced-motion` blocks. */
export const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)'

/** Minimal view of `window` that the check needs, so tests can pass a stub. */
export interface MotionWindow {
  matchMedia?: (query: string) => { matches: boolean }
}

/**
 * True when the viewer asked for reduced motion. Anything that cannot be read
 * (no `matchMedia`, a throwing stub) counts as "no preference", so the default
 * motion stays on and the UI never depends on the query being available.
 */
export function prefersReducedMotion(win: MotionWindow | undefined = globalThis.window): boolean {
  try {
    return win?.matchMedia?.(REDUCED_MOTION_QUERY).matches ?? false
  } catch {
    return false
  }
}
