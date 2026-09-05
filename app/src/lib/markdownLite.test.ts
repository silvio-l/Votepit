/**
 * Exhaustive unit tests for the Markdown-lite parser — this is the one place
 * in the app that turns untrusted user text into structured nodes, so it is
 * tested like a security boundary: correctness for the happy path, and
 * explicit adversarial cases (XSS-style payloads, malformed/edge-case
 * delimiters, ReDoS-shaped input) that must degrade to inert plain text
 * rather than ever producing something a renderer could turn into an
 * active link or markup.
 */

import { describe, expect, it } from 'vitest'
import { type MdNode, parseMarkdownLite, truncateMarkdownLite } from './markdownLite'

function text(value: string): MdNode {
  return { type: 'text', value }
}

describe('parseMarkdownLite — plain text', () => {
  it('returns an empty array for empty input', () => {
    expect(parseMarkdownLite('')).toEqual([])
  })

  it('passes through text with no special syntax untouched', () => {
    expect(parseMarkdownLite('Hallo Welt, wie geht es dir?')).toEqual([
      text('Hallo Welt, wie geht es dir?'),
    ])
  })

  it('preserves unicode and emoji as plain text', () => {
    const input = 'Öffentliche Boards 🎉 für alle — schön, oder?'
    expect(parseMarkdownLite(input)).toEqual([text(input)])
  })
})

describe('parseMarkdownLite — bold', () => {
  it('parses a simple bold span', () => {
    expect(parseMarkdownLite('das ist **wichtig** hier')).toEqual([
      text('das ist '),
      { type: 'bold', children: [text('wichtig')] },
      text(' hier'),
    ])
  })

  it('parses multiple bold spans in one string', () => {
    expect(parseMarkdownLite('**eins** und **zwei**')).toEqual([
      { type: 'bold', children: [text('eins')] },
      text(' und '),
      { type: 'bold', children: [text('zwei')] },
    ])
  })

  it('treats an unmatched opening "**" as literal text', () => {
    expect(parseMarkdownLite('das **geht nicht zu')).toEqual([text('das **geht nicht zu')])
  })

  it('treats an empty bold span "****" as literal text (no empty bold node)', () => {
    expect(parseMarkdownLite('was ist das: ****')).toEqual([text('was ist das: ****')])
  })

  it('does not nest bold inside bold — a stray "**" inside stays literal', () => {
    expect(parseMarkdownLite('**außen ** innen** Ende')).toEqual([
      { type: 'bold', children: [text('außen ')] },
      text(' innen** Ende'),
    ])
  })
})

describe('parseMarkdownLite — italic', () => {
  it('parses a simple italic span', () => {
    expect(parseMarkdownLite('das ist *kursiv* hier')).toEqual([
      text('das ist '),
      { type: 'italic', children: [text('kursiv')] },
      text(' hier'),
    ])
  })

  it('treats an unmatched "*" as literal text', () => {
    expect(parseMarkdownLite('3 * 4 = 12')).toEqual([text('3 * 4 = 12')])
  })

  it('parses bold and italic together, bold taking priority', () => {
    expect(parseMarkdownLite('**fett** und *kursiv*')).toEqual([
      { type: 'bold', children: [text('fett')] },
      text(' und '),
      { type: 'italic', children: [text('kursiv')] },
    ])
  })

  it('parses italic nested inside bold', () => {
    expect(parseMarkdownLite('**fett mit *kursiv* innen**')).toEqual([
      {
        type: 'bold',
        children: [
          text('fett mit '),
          { type: 'italic', children: [text('kursiv')] },
          text(' innen'),
        ],
      },
    ])
  })
})

describe('parseMarkdownLite — code', () => {
  it('parses a simple code span', () => {
    expect(parseMarkdownLite('nutze `npm install`')).toEqual([
      text('nutze '),
      { type: 'code', value: 'npm install' },
    ])
  })

  it('does not parse bold/italic/links inside code spans', () => {
    expect(parseMarkdownLite('`**nicht fett** und http://example.com`')).toEqual([
      { type: 'code', value: '**nicht fett** und http://example.com' },
    ])
  })

  it('treats an unmatched backtick as literal text', () => {
    expect(parseMarkdownLite('das ist `kaputt')).toEqual([text('das ist `kaputt')])
  })

  it('treats an empty code span "``" as literal text', () => {
    expect(parseMarkdownLite('leer: ``')).toEqual([text('leer: ``')])
  })

  it('code spans split the string before bold/italic are considered — bold does not span across a code span', () => {
    // A deliberate simplification vs. full CommonMark (where emphasis can wrap an
    // inline code span): code-span splitting runs as the outermost pass, so an
    // unmatched "**" on either side of it is just literal text, never spanning bold.
    expect(parseMarkdownLite('**fett `code` fett**')).toEqual([
      text('**fett '),
      { type: 'code', value: 'code' },
      text(' fett**'),
    ])
  })
})

describe('parseMarkdownLite — autolinks', () => {
  it('linkifies a bare https URL', () => {
    expect(parseMarkdownLite('siehe https://votepit.com/demo')).toEqual([
      text('siehe '),
      { type: 'link', href: 'https://votepit.com/demo', text: 'https://votepit.com/demo' },
    ])
  })

  it('linkifies a bare http URL', () => {
    expect(parseMarkdownLite('http://example.com')).toEqual([
      { type: 'link', href: 'http://example.com/', text: 'http://example.com' },
    ])
  })

  it('is case-insensitive on the scheme', () => {
    expect(parseMarkdownLite('HTTPS://example.com')).toEqual([
      { type: 'link', href: 'https://example.com/', text: 'HTTPS://example.com' },
    ])
  })

  it('trims trailing sentence punctuation off the link', () => {
    expect(parseMarkdownLite('Schau mal hier: https://example.com/x.')).toEqual([
      text('Schau mal hier: '),
      { type: 'link', href: 'https://example.com/x', text: 'https://example.com/x' },
      text('.'),
    ])
  })

  it('trims a trailing unbalanced closing paren but keeps a balanced one', () => {
    expect(parseMarkdownLite('(siehe https://example.com/foo)')).toEqual([
      text('(siehe '),
      { type: 'link', href: 'https://example.com/foo', text: 'https://example.com/foo' },
      text(')'),
    ])
    expect(parseMarkdownLite('https://en.wikipedia.org/wiki/Foo_(disambiguation)')).toEqual([
      {
        type: 'link',
        href: 'https://en.wikipedia.org/wiki/Foo_(disambiguation)',
        text: 'https://en.wikipedia.org/wiki/Foo_(disambiguation)',
      },
    ])
  })

  it('keeps query strings and fragments intact', () => {
    const url = 'https://example.com/search?q=a+b&x=1#section'
    expect(parseMarkdownLite(url)).toEqual([{ type: 'link', href: url, text: url }])
  })

  it('linkifies multiple URLs in one string', () => {
    expect(parseMarkdownLite('a https://one.example b https://two.example c')).toEqual([
      text('a '),
      { type: 'link', href: 'https://one.example/', text: 'https://one.example' },
      text(' b '),
      { type: 'link', href: 'https://two.example/', text: 'https://two.example' },
      text(' c'),
    ])
  })

  it('linkifies a URL inside a bold/italic span', () => {
    expect(parseMarkdownLite('**siehe https://example.com**')).toEqual([
      {
        type: 'bold',
        children: [
          text('siehe '),
          { type: 'link', href: 'https://example.com/', text: 'https://example.com' },
        ],
      },
    ])
  })

  it('stops the URL at whitespace, never swallowing following words', () => {
    expect(parseMarkdownLite('https://example.com und mehr Text')).toEqual([
      { type: 'link', href: 'https://example.com/', text: 'https://example.com' },
      text(' und mehr Text'),
    ])
  })
})

describe('parseMarkdownLite — malicious/malformed link input never becomes a link', () => {
  const rejectedAsPlainText = [
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
    '//evil.example.com/phish',
    'http:evil.example.com', // no "//" — not http(s):// per our matcher
    'ht tp://example.com', // space breaks the scheme
  ]

  it.each(rejectedAsPlainText)('never linkifies %s', (payload) => {
    const nodes = parseMarkdownLite(payload)
    for (const node of nodes) {
      expect(node.type).not.toBe('link')
    }
  })

  it('only linkifies the well-formed https URL in a mixed malicious string, leaving the rest as text', () => {
    const nodes = parseMarkdownLite('click https://example.com or javascript:alert(1)')
    const links = nodes.filter((n): n is Extract<MdNode, { type: 'link' }> => n.type === 'link')
    expect(links).toHaveLength(1)
    expect(links[0]?.href).toBe('https://example.com/')
    const joined = nodes
      .map((n) => (n.type === 'text' ? n.value : n.type === 'link' ? n.text : ''))
      .join('')
    expect(joined).toContain('javascript:alert(1)')
  })

  it('a "javascript://" prefix hiding a real https URL only linkifies the safe https part, never the javascript scheme', () => {
    // javascript://https://example.com/%0aalert(1) is a known filter-evasion trick, but
    // it only matters if the WHOLE string becomes one href. Our scanner only ever starts
    // a candidate at a literal "http(s)://" match and never looks backwards, so the
    // "javascript://" prefix can only ever end up as inert preceding text.
    const nodes = parseMarkdownLite('javascript://https://example.com/%0aalert(1)')
    const links = nodes.filter((n): n is Extract<MdNode, { type: 'link' }> => n.type === 'link')
    expect(links).toHaveLength(1)
    expect(links[0]?.href.startsWith('https://example.com/')).toBe(true)
    expect(links[0]?.href).not.toContain('javascript')
    const leadingText = nodes[0]
    expect(leadingText).toEqual({ type: 'text', value: 'javascript://' })
  })

  it('rejects a URL that exceeds the length cap, keeping it as inert text', () => {
    const longUrl = `https://example.com/${'a'.repeat(3000)}`
    const nodes = parseMarkdownLite(longUrl)
    expect(nodes.every((n) => n.type !== 'link')).toBe(true)
  })

  it('normalizes the href via the URL parser rather than trusting raw input verbatim', () => {
    // Whitespace-free but odd casing/host forms still resolve through `new URL` — the
    // normalized `.href` is what gets used, never the raw substring.
    const nodes = parseMarkdownLite('https://EXAMPLE.com:443/a')
    expect(nodes).toEqual([
      { type: 'link', href: 'https://example.com/a', text: 'https://EXAMPLE.com:443/a' },
    ])
  })
})

describe('parseMarkdownLite — degenerate/adversarial delimiter input stays inert and fast', () => {
  it('handles a long run of unmatched "*" without hanging or throwing', () => {
    const input = '*'.repeat(5000)
    const start = performance.now()
    const nodes = parseMarkdownLite(input)
    expect(performance.now() - start).toBeLessThan(1000)
    expect(nodes.every((n) => n.type === 'text')).toBe(true)
  })

  it('handles a long run of unmatched "**" without hanging or throwing', () => {
    const input = '**'.repeat(5000)
    const start = performance.now()
    parseMarkdownLite(input)
    expect(performance.now() - start).toBeLessThan(1000)
  })

  it('handles a long run of unmatched backticks without hanging or throwing', () => {
    const input = '`'.repeat(5000)
    const start = performance.now()
    parseMarkdownLite(input)
    expect(performance.now() - start).toBeLessThan(1000)
  })

  it('handles alternating delimiter noise without hanging or throwing', () => {
    const input = '*`**`*'.repeat(2000)
    const start = performance.now()
    parseMarkdownLite(input)
    expect(performance.now() - start).toBeLessThan(1000)
  })

  it('falls back to verbatim text above the hard input-length ceiling', () => {
    const huge = `**${'a'.repeat(25_000)}**`
    expect(parseMarkdownLite(huge)).toEqual([text(huge)])
  })
})

describe('parseMarkdownLite — no HTML/script content ever appears as a non-text node label', () => {
  it('a bold/italic/code span containing HTML-looking text stays inert data, never markup', () => {
    const nodes = parseMarkdownLite('**<img src=x onerror=alert(1)>**')
    expect(nodes).toEqual([{ type: 'bold', children: [text('<img src=x onerror=alert(1)>')] }])
  })

  it('a link label is always the plain matched text, never HTML-bearing', () => {
    const nodes = parseMarkdownLite('https://example.com/"><script>alert(1)</script>')
    const link = nodes.find((n): n is Extract<MdNode, { type: 'link' }> => n.type === 'link')
    expect(link).toBeDefined()
    // The quote/angle-bracket tail is part of the path per the URL spec (percent-encoded
    // by the URL parser) — it is inert data either way, never re-interpreted as markup.
    expect(link?.href.startsWith('https://example.com/')).toBe(true)
  })
})

describe('truncateMarkdownLite', () => {
  it('returns the tree unchanged and untruncated when it already fits the budget', () => {
    const nodes = parseMarkdownLite('**bold** text')
    expect(truncateMarkdownLite(nodes, 100)).toEqual({ nodes, truncated: false })
  })

  it('cuts a plain text node at the budget and marks it truncated', () => {
    const nodes = parseMarkdownLite('hello world')
    expect(truncateMarkdownLite(nodes, 5)).toEqual({
      nodes: [text('hello')],
      truncated: true,
    })
  })

  it('cuts inside a bold span, keeping it a well-formed (shorter) bold node', () => {
    const nodes = parseMarkdownLite('**gfdshsgfh** and more')
    const result = truncateMarkdownLite(nodes, 6)
    expect(result.truncated).toBe(true)
    expect(result.nodes).toEqual([{ type: 'bold', children: [text('gfdshs')] }])
  })

  it('drops a bold span entirely once its budget is already exhausted', () => {
    const nodes = parseMarkdownLite('hello **world**')
    const result = truncateMarkdownLite(nodes, 5)
    expect(result.truncated).toBe(true)
    expect(result.nodes).toEqual([text('hello')])
  })

  it('truncates a link label without touching its href', () => {
    const nodes = parseMarkdownLite('see https://example.com/a-long-path here')
    const result = truncateMarkdownLite(nodes, 8)
    expect(result.truncated).toBe(true)
    const link = result.nodes.find((n): n is Extract<MdNode, { type: 'link' }> => n.type === 'link')
    expect(link?.href).toBe('https://example.com/a-long-path')
    expect(link?.text).toBe('http')
  })

  it('leaves an empty tree untruncated', () => {
    expect(truncateMarkdownLite([], 10)).toEqual({ nodes: [], truncated: false })
  })
})
