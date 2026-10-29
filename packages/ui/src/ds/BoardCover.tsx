import { cx } from '../lib/cx'
import { INK_HEX, mixHex, PORCELAIN_HEX, relativeLuminance, validHexOr, WHITE_HEX } from './color'
import { tokensDark } from './tokens.generated'

/** The dark page canvas (generated), the ground of a cover on a dark theme. */
const DARK_CANVAS = tokensDark['semantic.surface.canvas'] ?? '#0E1412'
/** Below this relative luminance the ground reads as dark and the cover tones lighten. */
const DARK_GROUND_LUMINANCE = 0.18

/**
 * Used when the board has no valid accent. Matches the Votepit green seal tone. Fixed on purpose: the generated
 * cover is painted from one accent, and this is its fallback accent (exception, see DESIGN.md "Fixed colours").
 */
export const COVER_FALLBACK_ACCENT = '#0B6B4C'

export type CoverFamily = 0 | 1 | 2

/** Selectable generated cover patterns (P-28c). `null` keeps the default family cover. */
export const COVER_PATTERNS = ['waves', 'dots', 'stripes', 'bokeh', 'grid'] as const
export type CoverPattern = (typeof COVER_PATTERNS)[number]

/** Stable pattern family per board (simple string hash over the seed). */
export function coverFamilyFor(seed: string | number): CoverFamily {
  let hash = 0
  for (const ch of String(seed)) hash = (hash * 31 + (ch.codePointAt(0) ?? 0)) >>> 0
  return (hash % 3) as CoverFamily
}

export interface BoardCoverProps {
  /** Board accent; invalid or missing values fall back to COVER_FALLBACK_ACCENT. */
  accent?: string | null
  /** Board id (or any stable value): picks one of the three default families. */
  seed: string | number
  /** Selected generated pattern; null or unknown keeps the default family cover. */
  pattern?: CoverPattern | null
  /**
   * The cover's ground: the board's secondary colour (surfaces only, owner decision
   * 2026-10-27). Null uses porcelain on a light theme and the dark canvas on a dark one.
   */
  surface?: string | null
  /** The theme the cover is shown in; only used when no surface colour is given. */
  dark?: boolean
  className?: string
  testId?: string
}

/** Arrow pointing up, centred on (0, 0), about 46 units tall. Drawn with our own path data. */
const ARROW_UP = 'M0 -23 L23 0 L13 0 L13 23 L-13 23 L-13 0 L-23 0 Z'

function Arrow({
  x,
  y,
  scale = 1,
  down = false,
  fill,
}: {
  x: number
  y: number
  scale?: number
  down?: boolean
  fill: string
}) {
  const rotate = down ? ' rotate(180)' : ''
  return (
    <path
      d={ARROW_UP}
      fill={fill}
      stroke={fill}
      strokeWidth={6}
      strokeLinejoin="round"
      transform={`translate(${x} ${y}) scale(${scale})${rotate}`}
    />
  )
}

/**
 * Default board cover: our own SVG shapes in three families (arrow with arcs, arrow with
 * rings, arrow with rounded bars), coloured only from the validated accent and the ground
 * (secondary colour, porcelain or the dark canvas). Decorative, no text. Tenant SVG, HTML
 * or CSS never reaches this component.
 */
export function BoardCover({ accent, seed, pattern, surface, dark, className, testId }: BoardCoverProps) {
  const base = validHexOr(accent, COVER_FALLBACK_ACCENT)
  const ground = validHexOr(surface, dark === true ? DARK_CANVAS : PORCELAIN_HEX)
  // Tones are mixed towards the ground, so the shapes keep their contrast on any ground.
  // On a dark ground the deep tone lightens (a mix towards ink would vanish there).
  const onDark = relativeLuminance(ground) < DARK_GROUND_LUMINANCE
  const paper = mixHex(ground, base, 0.06)
  const soft = mixHex(base, ground, 0.45)
  const pale = mixHex(base, ground, 0.72)
  const deep = onDark ? mixHex(base, WHITE_HEX, 0.2) : mixHex(base, INK_HEX, 0.35)
  const family = coverFamilyFor(seed)
  const generated =
    pattern !== undefined &&
    pattern !== null &&
    (COVER_PATTERNS as readonly string[]).includes(pattern)

  return (
    <svg
      className={cx('ds-board-cover', className)}
      viewBox="0 0 300 100"
      preserveAspectRatio="xMidYMid slice"
      aria-hidden="true"
      focusable="false"
      data-testid={testId}
      data-cover-family={generated ? undefined : family}
      data-cover-pattern={generated ? pattern : undefined}
    >
      <rect width="300" height="100" fill={paper} />
      {generated && (
        <PatternShapes pattern={pattern} base={base} soft={soft} pale={pale} deep={deep} />
      )}
      {!generated && family === 0 && (
        <>
          <path d="M-20 100 A90 90 0 0 1 90 10" fill="none" stroke={soft} strokeWidth={28} />
          <path
            d="M-10 100 L70 30"
            fill="none"
            stroke={deep}
            strokeWidth={18}
            strokeLinecap="round"
          />
          <path
            d="M300 0 L210 80"
            fill="none"
            stroke={deep}
            strokeWidth={14}
            strokeLinecap="round"
          />
          <Arrow x={95} y={50} fill={base} />
          <Arrow x={230} y={52} scale={0.9} down fill={soft} />
        </>
      )}
      {!generated && family === 1 && (
        <>
          <circle cx={250} cy={0} r={70} fill={pale} />
          <circle cx={250} cy={0} r={40} fill={deep} />
          <circle cx={20} cy={110} r={60} fill={soft} />
          <circle cx={20} cy={110} r={30} fill={pale} />
          <circle cx={160} cy={20} r={6} fill={soft} />
          <Arrow x={110} y={48} fill={base} />
          <Arrow x={205} y={64} scale={0.6} down fill={deep} />
        </>
      )}
      {!generated && family === 2 && (
        <>
          <rect x={0} y={60} width={170} height={40} rx={20} fill={soft} />
          <path d="M0 60 A30 30 0 0 1 60 60 Z" fill={deep} />
          <circle cx={270} cy={20} r={60} fill={pale} />
          <circle cx={270} cy={20} r={34} fill={base} />
          <Arrow x={75} y={38} fill={deep} />
          <Arrow x={240} y={72} scale={0.9} down fill={base} />
        </>
      )}
    </svg>
  )
}

/** Generated pattern shapes (P-28c). Own SVG geometry, coloured from the same validated tones. */
function PatternShapes({
  pattern,
  base,
  soft,
  pale,
  deep,
}: {
  pattern: CoverPattern
  base: string
  soft: string
  pale: string
  deep: string
}) {
  if (pattern === 'waves') {
    return (
      <>
        {[18, 42, 66, 90].map((y) => (
          <path
            key={y}
            d={`M-10 ${y} Q 40 ${y - 14} 90 ${y} T 190 ${y} T 310 ${y}`}
            fill="none"
            stroke={y % 2 === 0 ? soft : pale}
            strokeWidth={9}
            strokeLinecap="round"
          />
        ))}
      </>
    )
  }
  if (pattern === 'dots') {
    const dots: Array<{ x: number; y: number }> = []
    for (let x = 15; x < 300; x += 30) for (let y = 15; y < 100; y += 30) dots.push({ x, y })
    return (
      <>
        {dots.map((d) => (
          <circle key={`${d.x}-${d.y}`} cx={d.x} cy={d.y} r={6} fill={soft} />
        ))}
      </>
    )
  }
  if (pattern === 'stripes') {
    return (
      <>
        {[-40, -10, 20, 50, 80, 110, 140, 170, 200, 230, 260, 290, 320].map((x, i) => (
          <path
            key={x}
            d={`M${x} 100 L${x + 60} 0 L${x + 76} 0 L${x + 16} 100 Z`}
            fill={i % 2 === 0 ? soft : pale}
          />
        ))}
      </>
    )
  }
  if (pattern === 'bokeh') {
    const spots: Array<{ x: number; y: number; r: number; fill: string }> = [
      { x: 40, y: 30, r: 26, fill: pale },
      { x: 120, y: 70, r: 18, fill: soft },
      { x: 190, y: 25, r: 34, fill: pale },
      { x: 260, y: 75, r: 22, fill: base },
      { x: 150, y: 15, r: 10, fill: deep },
      { x: 225, y: 55, r: 12, fill: soft },
    ]
    return (
      <>
        {spots.map((c) => (
          <circle key={`${c.x}-${c.y}`} cx={c.x} cy={c.y} r={c.r} fill={c.fill} opacity={0.75} />
        ))}
      </>
    )
  }
  // grid
  const lines: number[] = []
  for (let x = 0; x <= 300; x += 25) lines.push(x)
  return (
    <>
      {lines.map((x) => (
        <path key={`v${x}`} d={`M${x} 0 V100`} stroke={soft} strokeWidth={2} />
      ))}
      {[0, 25, 50, 75, 100].map((y) => (
        <path key={`h${y}`} d={`M0 ${y} H300`} stroke={soft} strokeWidth={2} />
      ))}
      <rect x={100} y={25} width={100} height={50} fill={base} />
    </>
  )
}
