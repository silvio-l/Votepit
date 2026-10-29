import { describe, expect, it } from 'vitest'
import { buildWidgetAgentPrompt, buildWidgetSnippet, WIDGET_SECRET_ENV } from './widgetPrompt'

const opts = {
  origin: 'https://votes.example.com',
  boardPath: 'acme/ideas',
  audience: 'votepit:board:42',
  maxLifetime: 600,
}

describe('buildWidgetSnippet', () => {
  it('loads the loader from this installation with the board path', () => {
    const snippet = buildWidgetSnippet(opts)
    expect(snippet).toContain(
      '<script src="https://votes.example.com/widget.js" data-board="acme/ideas" async></script>',
    )
    expect(snippet).toContain('window.Votepit.q')
  })
})

describe('buildWidgetAgentPrompt', () => {
  it('names the exact audience, lifetime and algorithm', () => {
    const prompt = buildWidgetAgentPrompt(opts)
    expect(prompt).toContain('aud: "votepit:board:42"')
    expect(prompt).toContain('at most 600 seconds (10 minutes)')
    expect(prompt).toContain('"alg":"HS256"')
    expect(prompt).toContain(buildWidgetSnippet(opts))
  })

  it('points at an environment variable instead of embedding a secret', () => {
    const prompt = buildWidgetAgentPrompt(opts)
    expect(prompt).toContain(WIDGET_SECRET_ENV)
    expect(prompt).toContain('never ship the secret to the browser')
  })
})
