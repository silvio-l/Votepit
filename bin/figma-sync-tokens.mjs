#!/usr/bin/env node
// @ts-check
/**
 * Figma-Variablen → packages/ui/tokens.css (@theme) — Drift-Wächter / Generator.
 *
 * Single Source of Truth = Figma-Variablen. Dieses Skript zieht sie und gleicht
 * den @theme-Block ab, damit Figma ↔ Code nicht auseinanderlaufen.
 *
 *   FIGMA_ACCESS_TOKEN=figd_… node bin/figma-sync-tokens.mjs [--write]
 *   (Token-Datei: ~/.config/figma/token.env → `source` davor.)
 *
 * --write  : schreibt den generierten @theme-Block in tokens.css (sonst nur Diff).
 *
 * WICHTIG (Stand 2026): Die Figma Variables-REST-API
 * (GET /v1/files/:key/variables/local) ist **Enterprise-only** UND braucht einen
 * Token mit Scope `file_variables:read`. Auf Pro/ohne Scope → HTTP 403. Dann ist
 * der Abgleich manuell über die Figma-MCP-Tools (`get_variable_defs`) zu machen
 * (das tut der Agent on-demand). Dieses Skript aktiviert sich automatisch, sobald
 * Plan + Scope passen.
 */

const FILE_KEY = 'LF4w4ib8q7m8EAemr0P4k6'
const TOKEN = process.env.FIGMA_ACCESS_TOKEN || ''
const WRITE = process.argv.includes('--write')

// Figma-Variablenname → CSS-Custom-Property im @theme (Repo-Konvention).
// Bewusste Umbenennungen: surface-bg→bg, brand-radius-base→radius-vp-xl, brand-*→vp-*.
const COLOR_MAP = {
  'brand-primary': '--vp-primary',
  'brand-ink': '--vp-ink',
  'brand-on-ink': '--vp-on-ink',
  'color-surface-bg': '--color-vp-bg',
  'color-surface-card': '--color-vp-surface',
  'color-surface-card-frost': '--color-vp-surface-frost',
  'color-text-secondary': '--color-vp-text-secondary',
  'color-text-muted': '--color-vp-text-muted',
  'color-border-subtle': '--color-vp-border-subtle',
  'color-vote-up': '--color-vp-vote-up',
  'color-consensus-strong': '--color-vp-consensus-strong',
  'color-status-open': '--color-vp-status-open',
  'color-status-planned': '--color-vp-status-planned',
  'color-status-in-progress': '--color-vp-status-in-progress',
  'color-status-done': '--color-vp-status-done',
}
const RADIUS_MAP = {
  'radius-sm': '--radius-vp-sm',
  'radius-md': '--radius-vp-md',
  'radius-lg': '--radius-vp-lg',
  'brand-radius-base': '--radius-vp-xl',
  'radius-full': '--radius-vp-full',
}

if (!TOKEN) {
  console.error('✗ FIGMA_ACCESS_TOKEN fehlt. → source ~/.config/figma/token.env')
  process.exit(2)
}

const res = await fetch(`https://api.figma.com/v1/files/${FILE_KEY}/variables/local`, {
  headers: { 'X-Figma-Token': TOKEN },
})

if (res.status === 403) {
  console.error(
    '✗ HTTP 403 — Figma Variables-API nicht verfügbar.\n' +
      '  Sie ist Enterprise-only UND braucht Token-Scope `file_variables:read`.\n' +
      '  Auf Pro: Abgleich manuell über Figma-MCP (`get_variable_defs`) — der Agent\n' +
      '  macht das on-demand. Dieses Skript läuft automatisch, sobald Plan+Scope passen.',
  )
  process.exit(0)
}
if (!res.ok) {
  console.error(`✗ HTTP ${res.status}: ${await res.text()}`)
  process.exit(1)
}

const { meta } = await res.json()
const vars = Object.values(meta?.variables ?? {})

/** Sammelt aufgelöste Werte (erster Mode) je Figma-Variablenname. */
const byName = {}
for (const v of vars) {
  const val = Object.values(v.valuesByMode ?? {})[0]
  byName[v.name.replaceAll('/', '-')] = val
}

const lines = []
for (const [figName, cssVar] of Object.entries(COLOR_MAP)) {
  const v = byName[figName]
  if (v && typeof v === 'object' && 'r' in v) {
    const hex = rgbaToCss(v)
    lines.push(`  ${cssVar}: ${hex};`)
  }
}
for (const [figName, cssVar] of Object.entries(RADIUS_MAP)) {
  const v = byName[figName]
  if (typeof v === 'number') lines.push(`  ${cssVar}: ${v}px;`)
}

const block = lines.join('\n')
console.log('Generierter @theme-Auszug (aus Figma-Variablen):\n' + block)
if (WRITE) {
  console.log('\n[--write] Bitte den Block manuell mit dem @theme in packages/ui/tokens.css\n' +
    'abgleichen (gezieltes Review statt Blind-Überschreiben der handgepflegten Datei).')
}

/** @param {{r:number,g:number,b:number,a:number}} c */
function rgbaToCss(c) {
  const to255 = (x) => Math.round(x * 255)
  if (c.a === 1) {
    return '#' + [c.r, c.g, c.b].map((x) => to255(x).toString(16).padStart(2, '0')).join('')
  }
  return `rgba(${to255(c.r)}, ${to255(c.g)}, ${to255(c.b)}, ${Number(c.a.toFixed(3))})`
}
