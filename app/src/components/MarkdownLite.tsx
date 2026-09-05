/**
 * Renders the plain MdNode[] tree from lib/markdownLite.ts as JSX — the only
 * place that tree ever touches the DOM. No dangerouslySetInnerHTML, no HTML
 * string ever exists in this pipeline (see markdownLite.ts's top comment for
 * why that matters here). Every link gets a hardcoded safe rel/target; the
 * href's scheme is re-checked here too, even though the parser already only
 * ever produces http(s) hrefs — defense in depth, not trust in the caller.
 *
 * Every link also opens in a new tab, and any link leaving Votepit's own
 * origin shows a confirmation first (the actual destination URL, not the
 * link text, is what's trusted — this is exactly the case link-text
 * spoofing relies on). Same-origin links (e.g. a pasted board/idea URL)
 * skip the interstitial, they're not "leaving" anything.
 */

import { ConfirmDialog } from '@votepit/ui'
import type { ReactElement, MouseEvent as ReactMouseEvent, ReactNode } from 'react'
import { useState } from 'react'
import { useT } from '../lib/i18n/context'
import type { MdNode } from '../lib/markdownLite'
import { parseMarkdownLite, truncateMarkdownLite } from '../lib/markdownLite'

const SAFE_HREF_PROTOCOLS = new Set(['http:', 'https:'])

function isSafeHref(href: string): boolean {
  try {
    return SAFE_HREF_PROTOCOLS.has(new URL(href).protocol)
  } catch {
    return false
  }
}

function isSameOrigin(href: string): boolean {
  try {
    return new URL(href).origin === window.location.origin
  } catch {
    return false
  }
}

/** Punycode (`xn--`) in the hostname can hide a homograph lookalike domain — flagged, not blocked. */
function hasPunycodeHost(href: string): boolean {
  try {
    return new URL(href).hostname.split('.').some((label) => label.startsWith('xn--'))
  } catch {
    return false
  }
}

type LinkClickHandler = (event: ReactMouseEvent<HTMLAnchorElement>, href: string) => void

function renderNode(node: MdNode, key: number, onLinkClick: LinkClickHandler): ReactNode {
  switch (node.type) {
    case 'text':
      return node.value
    case 'bold':
      return <strong key={key}>{renderNodes(node.children, onLinkClick)}</strong>
    case 'italic':
      return <em key={key}>{renderNodes(node.children, onLinkClick)}</em>
    case 'code':
      return <code key={key}>{node.value}</code>
    case 'link':
      if (!isSafeHref(node.href)) return node.text
      return (
        <a
          key={key}
          href={node.href}
          target="_blank"
          rel="noopener noreferrer nofollow ugc"
          onClick={(event) => onLinkClick(event, node.href)}
        >
          {node.text}
        </a>
      )
  }
}

function renderNodes(nodes: MdNode[], onLinkClick: LinkClickHandler): ReactNode {
  return nodes.map((node, i) => renderNode(node, i, onLinkClick))
}

interface MarkdownLiteProps {
  text: string
  /**
   * Caps the visible-character budget, truncating the parsed tree (not the
   * raw source) so a cut-off bold/italic span still renders as a
   * well-formed, shorter node — for excerpts (list rows, card
   * descriptions) that still render real Markdown-lite rather than either
   * showing raw `**`/backtick syntax or being stripped to plain text.
   */
  maxChars?: number
}

export function MarkdownLite({ text, maxChars }: MarkdownLiteProps): ReactElement {
  const t = useT('common')
  const [pendingHref, setPendingHref] = useState<string | null>(null)

  function handleLinkClick(event: ReactMouseEvent<HTMLAnchorElement>, href: string) {
    // Same-origin links (e.g. a pasted board/idea URL) aren't "leaving" anything.
    if (isSameOrigin(href)) return
    event.preventDefault()
    setPendingHref(href)
  }

  const parsed = parseMarkdownLite(text)
  const { nodes, truncated } =
    maxChars !== undefined
      ? truncateMarkdownLite(parsed, maxChars)
      : { nodes: parsed, truncated: false }

  return (
    <>
      {renderNodes(nodes, handleLinkClick)}
      {truncated && '…'}
      <ConfirmDialog
        open={pendingHref !== null}
        title={t('externalLink.title')}
        description={
          pendingHref !== null && (
            <>
              {t('externalLink.description')}
              <br />
              <span className="break-all font-mono-num text-vp-ink">{pendingHref}</span>
              {hasPunycodeHost(pendingHref) && (
                <>
                  <br />
                  <span className="text-vp-vote-down-strong">
                    {t('externalLink.suspiciousHint')}
                  </span>
                </>
              )}
            </>
          )
        }
        confirmLabel={t('externalLink.continue')}
        cancelLabel={t('action.cancel')}
        onConfirm={() => {
          if (pendingHref !== null) window.open(pendingHref, '_blank', 'noopener,noreferrer')
          setPendingHref(null)
        }}
        onCancel={() => setPendingHref(null)}
      />
    </>
  )
}
