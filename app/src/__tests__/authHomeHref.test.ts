import { describe, expect, it } from 'vitest'
import { authHomeHref } from '../components/AuthShell'

describe('authHomeHref', () => {
  it('sends self-host and community instances to their own start page, never to the cloud site', () => {
    expect(authHomeHref('community', 'de')).toBe('/')
    expect(authHomeHref('community', 'en')).toBe('/')
  })

  it('sends cloud visitors to the marketing site, in their language where the landing has it', () => {
    expect(authHomeHref('cloud', 'en')).toBe('https://votepit.com/')
    expect(authHomeHref('cloud', 'de')).toBe('https://votepit.com/de/')
    expect(authHomeHref('cloud', 'fr')).toBe('https://votepit.com/fr/')
  })
})
