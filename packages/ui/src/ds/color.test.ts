import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
  accessibleFill,
  adjustLightnessForContrast,
  adjustLightnessUntil,
  contrastDirection,
  contrastRatio,
  hexToHsl,
  hslToHex,
  INK_HEX,
  initialsPaletteFor,
  isHexColor,
  mixHex,
  PORCELAIN_HEX,
  tonalScale,
  validHexOr,
  WHITE_HEX,
} from './color.ts'

test('isHexColor accepts only six-digit hex', () => {
  assert.equal(isHexColor('#0B6B4C'), true)
  assert.equal(isHexColor('#0b6b4c'), true)
  assert.equal(isHexColor('#fff'), false)
  assert.equal(isHexColor('red'), false)
  assert.equal(isHexColor('#0B6B4C;background:url(x)'), false)
  assert.equal(isHexColor(null), false)
})

test('validHexOr falls back for invalid input and upper-cases valid input', () => {
  assert.equal(validHexOr('#0b6b4c', '#000000'), '#0B6B4C')
  assert.equal(validHexOr('nope', '#123456'), '#123456')
})

test('contrast: black on white is 21:1, identical colours are 1:1', () => {
  assert.equal(Math.round(contrastRatio('#000000', '#FFFFFF')), 21)
  assert.equal(contrastRatio('#777777', '#777777'), 1)
})

test('mixHex interpolates and keeps the endpoints', () => {
  assert.equal(mixHex('#000000', '#FFFFFF', 0), '#000000')
  assert.equal(mixHex('#000000', '#FFFFFF', 1), '#FFFFFF')
  assert.equal(mixHex('#000000', '#FFFFFF', 0.5), '#808080')
})

test('initials palette: white on a dark accent, ink on a light accent, porcelain for very light', () => {
  assert.deepEqual(initialsPaletteFor('#0B6B4C'), { background: '#0B6B4C', foreground: WHITE_HEX })
  // Light yellow: white fails, ink passes.
  assert.deepEqual(initialsPaletteFor('#FFE37A'), { background: '#FFE37A', foreground: INK_HEX })
  // Mid tone where neither reaches 4.5:1 falls back to porcelain.
  const mid = '#808080'
  assert.equal(contrastRatio(WHITE_HEX, mid) < 4.5 && contrastRatio(INK_HEX, mid) < 4.5, true)
  assert.deepEqual(initialsPaletteFor(mid), { background: PORCELAIN_HEX, foreground: INK_HEX })
})

test('every palette result meets 4.5:1 except the porcelain fallback', () => {
  for (const accent of ['#0B6B4C', '#FF8F6B', '#7B3FA0', '#FFE37A', '#2F6B5E']) {
    const p = initialsPaletteFor(accent)
    if (p.background !== PORCELAIN_HEX) {
      assert.ok(contrastRatio(p.foreground, p.background) >= 4.5, `${accent} fails`)
    }
  }
})

test('hsl round trip keeps a colour within rounding', () => {
  for (const hex of ['#0B6B4C', '#1FA890', '#312E81', '#FFD166', '#7F7F7F']) {
    const { h, s, l } = hexToHsl(hex)
    assert.equal(hslToHex(h, s, l), hex)
  }
})

test('tonalScale is deterministic, ordered light to dark, and keeps the hue', () => {
  const a = tonalScale('#1FA890')
  assert.deepEqual(tonalScale('#1FA890'), a)
  const lum = (hex: string) => hexToHsl(hex).l
  assert.ok(lum(a[50]) > lum(a[500]) && lum(a[500]) > lum(a[900]))
  assert.ok(Math.abs(hexToHsl(a[500]).h - hexToHsl('#1FA890').h) < 3)
})

test('adjustLightnessForContrast leaves a passing colour unchanged', () => {
  assert.equal(adjustLightnessForContrast('#0B6B4C', WHITE_HEX, 4.5, 'darker'), '#0B6B4C')
})

test('adjustLightnessForContrast darkens a light accent on white until 4.5:1', () => {
  const out = adjustLightnessForContrast('#FFD166', WHITE_HEX, 4.5, 'darker')
  assert.ok(contrastRatio(out, WHITE_HEX) >= 4.5)
  assert.ok(hexToHsl(out).l < hexToHsl('#FFD166').l)
})

test('adjustLightnessForContrast lightens a dark accent on ink until 4.5:1', () => {
  const out = adjustLightnessForContrast('#312E81', INK_HEX, 4.5, 'lighter')
  assert.ok(contrastRatio(out, INK_HEX) >= 4.5)
})

test('contrastDirection darkens on light grounds and lightens on dark ones', () => {
  assert.equal(contrastDirection(PORCELAIN_HEX), 'darker')
  assert.equal(contrastDirection(INK_HEX), 'lighter')
})

test('accessibleFill keeps a fill that already has a 4.5:1 text colour', () => {
  assert.deepEqual(accessibleFill('#0B6B4C'), { fill: '#0B6B4C', text: WHITE_HEX, adjusted: false })
})

test('accessibleFill adjusts a mid tone until its text reaches 4.5:1', () => {
  const r = accessibleFill('#7F7F7F')
  assert.equal(r.adjusted, true)
  assert.ok(Math.max(contrastRatio(WHITE_HEX, r.fill), contrastRatio(INK_HEX, r.fill)) >= 4.5)
  assert.ok(contrastRatio(r.text, r.fill) >= 4.5)
})

test('adjustLightnessUntil moves lightness until the predicate holds, keeping hue', () => {
  const passes = (c: string) => contrastRatio(c, WHITE_HEX) >= 4.5 && contrastRatio(c, PORCELAIN_HEX) >= 4.5
  const adjusted = adjustLightnessUntil('#FFFF00', passes, 'darker')
  assert.ok(passes(adjusted))
  assert.ok(Math.abs(hexToHsl(adjusted).h - hexToHsl('#FFFF00').h) < 3)
  assert.equal(adjustLightnessUntil('#0B6B4C', passes, 'darker'), '#0B6B4C')
})
