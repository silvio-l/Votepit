import { useEffect, useRef } from 'react'

// Matomo: idSite=6 goal, guarded — fires once _paq exists
declare const window: Window & { _paq?: unknown[][] }

export default function DocsReadBeacon() {
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const el = ref.current
    if (!el) return
    let fired = false
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting && !fired) {
          fired = true
          if (window._paq) window._paq.push(['trackEvent', 'Engagement', 'Docs gelesen', 'docs'])
          observer.disconnect()
        }
      },
      { threshold: 0.5 },
    )
    observer.observe(el)
    return () => observer.disconnect()
  }, [])
  return <div ref={ref} aria-hidden="true" style={{ height: 1 }} />
}
