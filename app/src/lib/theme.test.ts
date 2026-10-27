import { describe, expect, it } from 'vitest'
import { resolveTheme } from './theme'

describe('resolveTheme', () => {
  it('follows the system preference when the viewer chose System and the board has no mode', () => {
    expect(resolveTheme({ pref: 'system', board: null, systemDark: true })).toBe('dark')
    expect(resolveTheme({ pref: 'system', board: null, systemDark: false })).toBe('light')
  })

  it('uses the viewer choice over the system preference', () => {
    expect(resolveTheme({ pref: 'light', board: null, systemDark: true })).toBe('light')
    expect(resolveTheme({ pref: 'dark', board: null, systemDark: false })).toBe('dark')
  })

  it('lets an explicit board mode win over the viewer choice', () => {
    expect(resolveTheme({ pref: 'light', board: 'dark', systemDark: false })).toBe('dark')
    expect(resolveTheme({ pref: 'dark', board: 'light', systemDark: true })).toBe('light')
  })
})
