import { afterEach, describe, expect, it } from 'vitest'
import {
  CLOUD_SITE_ORIGIN,
  COMMUNITY_PROJECT_URL,
  getEdition,
  poweredByHref,
  publicDocUrl,
  setEdition,
} from './edition'

afterEach(() => {
  setEdition('self-host')
})

describe('setEdition / getEdition', () => {
  it('derives the edition from the routing mode', () => {
    setEdition('self-host')
    expect(getEdition()).toBe('community')
    setEdition('cloud')
    expect(getEdition()).toBe('cloud')
  })

  it('lets an explicit override win over the routing mode', () => {
    setEdition('self-host', 'cloud')
    expect(getEdition()).toBe('cloud')
  })
})

describe('poweredByHref', () => {
  it('community: links to the public project, never to the Cloud site', () => {
    expect(poweredByHref('community')).toBe(COMMUNITY_PROJECT_URL)
    expect(poweredByHref('community')).not.toContain('votepit.com')
  })

  it('cloud: links to the marketing site', () => {
    expect(poweredByHref('cloud')).toBe(CLOUD_SITE_ORIGIN)
  })

  it('defaults to the current edition', () => {
    setEdition('self-host')
    expect(poweredByHref()).toBe(COMMUNITY_PROJECT_URL)
    setEdition('cloud')
    expect(poweredByHref()).toBe(CLOUD_SITE_ORIGIN)
  })
})

describe('publicDocUrl', () => {
  it('community: points at the docs shipped in the public repository', () => {
    expect(publicDocUrl('api-reference', 'community')).toBe(
      `${COMMUNITY_PROJECT_URL}/blob/main/documentation/api-reference.md`,
    )
    expect(publicDocUrl('mcp-server', 'community')).toBe(
      `${COMMUNITY_PROJECT_URL}/blob/main/documentation/mcp-server.md`,
    )
  })

  it('cloud: points at the marketing site docs', () => {
    expect(publicDocUrl('api-reference', 'cloud')).toBe(`${CLOUD_SITE_ORIGIN}/docs/api-reference/`)
    expect(publicDocUrl('mcp-server', 'cloud')).toBe(`${CLOUD_SITE_ORIGIN}/docs/mcp-server/`)
  })
})
