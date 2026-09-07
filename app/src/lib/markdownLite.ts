/**
 * A tiny, intentionally limited Markdown-like inline syntax: **bold**,
 * *italic*, `code` and bare http(s) autolinks — nothing else (no headings,
 * lists, images, raw HTML, or `[text](url)` link syntax). Parses to a plain
 * data tree (MdNode[]), never to an HTML string — the tenant-isolation
 * invariant in this repo's CLAUDE.md forbids rendering user-controlled
 * active content, so there must be no `dangerouslySetInnerHTML`/HTML-string
 * step anywhere in this pipeline; see MarkdownLite.tsx for the React
 * renderer that turns this tree into elements.
 *
 * Deliberately implemented as linear character scans (indexOf/charAt), not
 * a single big regex — nested-quantifier Markdown regexes are a classic
 * ReDoS source on adversarial input (unmatched `**`/`*`/backtick runs).
 * Every scan below is O(n) in the length of the segment it's given.
 */

export type MdNode =
  | { type: 'text'; value: string }
  | { type: 'bold'; children: MdNode[] }
  | { type: 'italic'; children: MdNode[] }
  | { type: 'code'; value: string }
  | { type: 'link'; href: string; text: string }

/** Hard ceiling — defense in depth even though callers already cap body length (2000 chars). */
const MAX_INPUT_LENGTH = 20_000
const MAX_URL_LENGTH = 2000
const ALLOWED_URL_PROTOCOLS = new Set(['http:', 'https:'])
const TRAILING_PUNCTUATION = new Set(['.', ',', '!', '?', ';', ':', "'", '"'])
const CLOSING_TO_OPENING: Record<string, string> = { ')': '(', ']': '[', '}': '{' }

function isWhitespace(ch: string): boolean {
  return ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r'
}

/** Validates a candidate autolink URL — the single place hrefs are trusted from. */
function safeUrl(candidate: string): string | null {
  if (candidate.length === 0 || candidate.length > MAX_URL_LENGTH) return null
  let parsed: URL
  try {
    parsed = new URL(candidate)
  } catch {
    return null
  }
  if (!ALLOWED_URL_PROTOCOLS.has(parsed.protocol)) return null
  return parsed.href
}

/** Trims trailing punctuation a sentence would naturally follow a URL with, e.g. "see http://x.com." */
function trimTrailingPunctuation(url: string): string {
  let end = url.length
  while (end > 0) {
    const last = url[end - 1]
    if (last === undefined) break
    const opening = CLOSING_TO_OPENING[last]
    if (opening !== undefined) {
      // Keep a closing bracket if its opener appears earlier in the (still untrimmed) URL.
      if (url.slice(0, end - 1).includes(opening)) break
      end--
      continue
    }
    if (TRAILING_PUNCTUATION.has(last)) {
      end--
      continue
    }
    break
  }
  return url.slice(0, end)
}

function textNode(value: string): MdNode[] {
  return value.length > 0 ? [{ type: 'text', value }] : []
}

/** Innermost pass — plain text scanned only for bare http(s) autolinks. */
function parseAutolinks(text: string): MdNode[] {
  const nodes: MdNode[] = []
  let i = 0
  let plainStart = 0

  while (i < text.length) {
    const rest = text.slice(i, i + 8).toLowerCase()
    const isHttp = rest.startsWith('http://')
    const isHttps = rest.startsWith('https://')
    if (!isHttp && !isHttps) {
      i++
      continue
    }

    let end = i
    while (end < text.length && !isWhitespace(text[end] as string)) end++
    const rawCandidate = text.slice(i, end)
    const trimmed = trimTrailingPunctuation(rawCandidate)
    const href = trimmed.length > 0 ? safeUrl(trimmed) : null

    if (href === null) {
      i = end > i ? end : i + 1
      continue
    }

    nodes.push(...textNode(text.slice(plainStart, i)))
    nodes.push({ type: 'link', href, text: trimmed })
    i += trimmed.length
    plainStart = i
  }

  nodes.push(...textNode(text.slice(plainStart)))
  return nodes
}

/** Single `*italic*` spans — must not be a `**` run (that's bold's delimiter, handled one level up). */
function parseItalic(text: string): MdNode[] {
  const nodes: MdNode[] = []
  let plainStart = 0
  let i = 0

  function isLoneStar(idx: number): boolean {
    if (text[idx] !== '*') return false
    if (text[idx - 1] === '*' || text[idx + 1] === '*') return false
    return true
  }

  while (i < text.length) {
    if (!isLoneStar(i)) {
      i++
      continue
    }
    const openIdx = i
    let closeIdx = -1
    for (let j = openIdx + 1; j < text.length; j++) {
      if (isLoneStar(j)) {
        closeIdx = j
        break
      }
    }
    if (closeIdx === -1 || closeIdx === openIdx + 1) {
      // No closing marker, or an empty `**`-adjacent span — literal asterisk.
      i++
      continue
    }
    nodes.push(...parseAutolinks(text.slice(plainStart, openIdx)))
    nodes.push({ type: 'italic', children: parseAutolinks(text.slice(openIdx + 1, closeIdx)) })
    i = closeIdx + 1
    plainStart = i
  }

  nodes.push(...parseAutolinks(text.slice(plainStart)))
  return nodes
}

/** `**bold**` spans — bold does not nest inside bold; its content is scanned for italic/autolinks only. */
function parseBold(text: string): MdNode[] {
  const nodes: MdNode[] = []
  let plainStart = 0
  let i = 0

  while (i < text.length - 1) {
    if (text[i] !== '*' || text[i + 1] !== '*') {
      i++
      continue
    }
    const openIdx = i
    const closeIdx = text.indexOf('**', openIdx + 2)
    if (closeIdx === -1 || closeIdx === openIdx + 2) {
      i++
      continue
    }
    nodes.push(...parseItalic(text.slice(plainStart, openIdx)))
    nodes.push({ type: 'bold', children: parseItalic(text.slice(openIdx + 2, closeIdx)) })
    i = closeIdx + 2
    plainStart = i
  }

  nodes.push(...parseItalic(text.slice(plainStart)))
  return nodes
}

/** Outermost pass — `` `code` `` spans, verbatim (never scanned for bold/italic/links). */
function parseCode(text: string): MdNode[] {
  const nodes: MdNode[] = []
  let plainStart = 0
  let i = 0

  while (i < text.length) {
    if (text[i] !== '`') {
      i++
      continue
    }
    const openIdx = i
    const closeIdx = text.indexOf('`', openIdx + 1)
    if (closeIdx === -1 || closeIdx === openIdx + 1) {
      i++
      continue
    }
    nodes.push(...parseBold(text.slice(plainStart, openIdx)))
    nodes.push({ type: 'code', value: text.slice(openIdx + 1, closeIdx) })
    i = closeIdx + 1
    plainStart = i
  }

  nodes.push(...parseBold(text.slice(plainStart)))
  return nodes
}

export function parseMarkdownLite(input: string): MdNode[] {
  if (input.length === 0) return []
  if (input.length > MAX_INPUT_LENGTH) return [{ type: 'text', value: input }]
  return parseCode(input)
}

interface TruncateState {
  nodes: MdNode[]
  used: number
  truncated: boolean
}

function truncateNodes(nodes: MdNode[], budget: number): TruncateState {
  const out: MdNode[] = []
  let used = 0
  let truncated = false

  for (const node of nodes) {
    if (used >= budget) {
      truncated = true
      break
    }
    const remaining = budget - used
    if (node.type === 'bold' || node.type === 'italic') {
      const child = truncateNodes(node.children, remaining)
      if (child.nodes.length > 0) out.push({ type: node.type, children: child.nodes })
      used += child.used
      if (child.truncated) truncated = true
      continue
    }
    // 'text' | 'code' | 'link' all carry their visible length in a single string field.
    const value = node.type === 'link' ? node.text : node.value
    if (value.length <= remaining) {
      out.push(node)
      used += value.length
    } else {
      out.push(
        node.type === 'link'
          ? { ...node, text: value.slice(0, remaining) }
          : { ...node, value: value.slice(0, remaining) },
      )
      used = budget
      truncated = true
    }
  }

  return { nodes: out, used, truncated }
}

/**
 * Truncates an already-parsed tree to a visible-character budget — safe to
 * cut mid-span because the result is still a well-formed MdNode[] tree (a
 * bold/italic span just ends up with fewer/no children), never a string cut
 * that could leave an unbalanced tag. Used for excerpts that still render
 * as real Markdown-lite (list rows, card descriptions) instead of showing
 * raw `**`/backtick syntax or being stripped to plain text.
 */
export function truncateMarkdownLite(
  nodes: MdNode[],
  maxChars: number,
): { nodes: MdNode[]; truncated: boolean } {
  const { nodes: truncatedNodes, truncated } = truncateNodes(nodes, maxChars)
  return { nodes: truncatedNodes, truncated }
}
