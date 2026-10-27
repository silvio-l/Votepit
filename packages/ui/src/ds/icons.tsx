import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

/** Stroke icons on a 24-unit grid. Decorative by design: pair with visible text or an accessible label. */
const PATHS = {
  'arrow-up': <path d="M12 19V5M5 12l7-7 7 7" />,
  'arrow-down': <path d="M12 5v14M19 12l-7 7-7-7" />,
  'chevron-down': <path d="M6 9l6 6 6-6" />,
  copy: (
    <>
      <rect x="8" y="8" width="12" height="12" rx="2" />
      <path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" />
    </>
  ),
  check: <path d="M5 12.5l4.5 4.5L19 7.5" />,
  share: (
    <>
      <circle cx="18" cy="5" r="2.5" />
      <circle cx="6" cy="12" r="2.5" />
      <circle cx="18" cy="19" r="2.5" />
      <path d="M8.2 10.8l7.6-4.6M8.2 13.2l7.6 4.6" />
    </>
  ),
  'check-circle': (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M8 12.5l2.5 2.5L16 9.5" />
    </>
  ),
  info: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 11v5M12 7.5v.5" />
    </>
  ),
  alert: (
    <>
      <path d="M12 4L21 19H3Z" />
      <path d="M12 10v4M12 16.5v.5" />
    </>
  ),
  error: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M9 9l6 6M15 9l-6 6" />
    </>
  ),
  'minus-circle': (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M8 12h8" />
    </>
  ),
  circle: <circle cx="12" cy="12" r="6" />,
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </>
  ),
  search: (
    <>
      <circle cx="11" cy="11" r="6.5" />
      <path d="M20 20l-4.5-4.5" />
    </>
  ),
  pencil: <path d="M4 20h4L19 9l-4-4L4 16z" />,
  bell: (
    <>
      <path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6" />
      <path d="M10 19a2 2 0 0 0 4 0" />
    </>
  ),
  comment: <path d="M4 5h16v11H9l-5 4z" />,
  external: (
    <>
      <path d="M14 4h6v6M20 4l-9 9" />
      <path d="M18 14v5H5V6h5" />
    </>
  ),
  file: (
    <>
      <path d="M7 3h7l5 5v13H7z" />
      <path d="M14 3v5h5" />
    </>
  ),
  server: (
    <>
      <rect x="4" y="4" width="16" height="6" rx="1.5" />
      <rect x="4" y="14" width="16" height="6" rx="1.5" />
    </>
  ),
  list: <path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01" />,
  close: <path d="M6 6l12 12M18 6L6 18" />,
} satisfies Record<string, ReactNode>

export type IconName = keyof typeof PATHS

export function Icon({
  name,
  size = 'default',
  className,
}: {
  name: IconName
  size?: 'small' | 'default' | 'large'
  className?: string
}) {
  return (
    <svg
      className={cx(
        'ds-icon',
        size === 'small' && 'ds-icon--small',
        size === 'large' && 'ds-icon--large',
        className,
      )}
      viewBox="0 0 24 24"
      aria-hidden="true"
      focusable="false"
    >
      {PATHS[name]}
    </svg>
  )
}
