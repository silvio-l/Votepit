import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

// Keyboard focus on a voted vote control must stay distinct from the voted ring (W49).
// The pressed ring reaches 2x the ring width; the focus outline must sit outside it.
const css = readFileSync(new URL('./components.css', import.meta.url), 'utf8')

test('a pressed vote control moves its focus outline outside the voted ring', () => {
  const rule = css.match(
    /\.ds-vote-control\[aria-pressed='true'\]:focus-visible,\s*\.ds-vote-control\[aria-pressed='true'\]\.ds-is-focus\s*\{([^}]*)\}/,
  )
  assert.ok(rule, 'pressed focus rule is missing')
  assert.match(
    rule[1] ?? '',
    /outline-offset:\s*calc\(var\(--vpds-c-vote-control-selected-ring\)\s*\*\s*2\s*\+\s*var\(--vpds-focus-offset\)\);/,
  )
})

test('the voted ring is still drawn at 2x the selected ring width', () => {
  assert.match(
    css,
    /\.ds-vote-control\[aria-pressed='true'\] \{\s*box-shadow:\s*0 0 0 var\(--vpds-c-vote-control-selected-ring\) var\(--vpds-surface-card\),\s*0 0 0 calc\(var\(--vpds-c-vote-control-selected-ring\) \* 2\)/,
  )
})
