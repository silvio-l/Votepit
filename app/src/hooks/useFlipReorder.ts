import { useCallback, useLayoutEffect, useRef } from 'react'

const FLIP_DURATION_MS = 320
const FLIP_EASING = 'cubic-bezier(0.22, 1, 0.36, 1)'
/** Sub-pixel jitter from layout rounding — anything below this is not a real move. */
const MIN_DELTA_PX = 1

/**
 * Generic FLIP (First-Last-Invert-Play) reorder animation for a list keyed by
 * a stable id, using only measured DOM positions — no animation library.
 *
 * Usage: call `captureBeforeReorder()` synchronously, BEFORE the state update
 * that may change row order (so the DOM still reflects the OLD positions).
 * After React commits the new order, a layout effect measures the NEW
 * positions and animates the delta away. Rows below `MIN_DELTA_PX` of actual
 * movement, or a viewer with `prefers-reduced-motion: reduce`, are left alone.
 */
export function useFlipReorder<Id extends string | number>() {
  const rowRefs = useRef(new Map<Id, HTMLElement>())
  const pendingRects = useRef<Map<Id, DOMRect> | null>(null)

  const registerRow = useCallback(
    (id: Id) => (el: HTMLElement | null) => {
      if (el) rowRefs.current.set(id, el)
      else rowRefs.current.delete(id)
    },
    [],
  )

  const captureBeforeReorder = useCallback(() => {
    const rects = new Map<Id, DOMRect>()
    for (const [id, el] of rowRefs.current) rects.set(id, el.getBoundingClientRect())
    pendingRects.current = rects
  }, [])

  // Runs after every commit; a no-op unless captureBeforeReorder queued a
  // "before" snapshot to diff against — cheap to leave unconditional.
  useLayoutEffect(() => {
    const before = pendingRects.current
    pendingRects.current = null
    if (!before) return

    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
    if (reduced) return

    for (const [id, el] of rowRefs.current) {
      const oldRect = before.get(id)
      if (!oldRect) continue
      const newRect = el.getBoundingClientRect()
      const dy = oldRect.top - newRect.top
      if (Math.abs(dy) < MIN_DELTA_PX) continue

      el.style.transition = 'none'
      el.style.transform = `translateY(${dy}px)`
      // Force a reflow so the browser registers the start position before
      // the transition below is applied — otherwise it would just jump.
      el.getBoundingClientRect()
      el.style.transition = `transform ${FLIP_DURATION_MS}ms ${FLIP_EASING}`
      el.style.transform = ''
    }
  })

  return { registerRow, captureBeforeReorder }
}
