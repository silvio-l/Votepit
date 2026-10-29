import { useEffect, useRef, useState } from 'react'

/**
 * Sticky viewport-visibility flag for board-list auto-translate
 * (idea-comment-translation, ticket 07 board-list follow-up): once an
 * element has intersected the viewport, `inView` stays `true` even if it's
 * later scrolled out again — a card whose translation was already fetched
 * must keep showing it, not re-hide/re-fetch on scroll.
 *
 * Falls back to always-`true` when `IntersectionObserver` isn't available
 * (very old browser) rather than silently never translating a board list.
 */
export function useInView(rootMargin = '200px'): [React.RefObject<HTMLDivElement | null>, boolean] {
  const ref = useRef<HTMLDivElement>(null)
  const [inView, setInView] = useState(typeof IntersectionObserver === 'undefined')

  useEffect(() => {
    if (inView) return
    const el = ref.current
    if (!el) return

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) setInView(true)
      },
      { rootMargin },
    )
    observer.observe(el)
    return () => observer.disconnect()
  }, [inView, rootMargin])

  return [ref, inView]
}
