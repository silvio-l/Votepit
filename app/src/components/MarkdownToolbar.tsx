/**
 * Bold/italic/code buttons for a controlled textarea, wrapping the current
 * selection with the matching Markdown-lite delimiter (see lib/markdownLite.ts).
 * Manipulates textarea.selectionStart/selectionEnd directly — deliberately
 * not document.execCommand (deprecated) — and never touches innerHTML, so
 * this component carries no injection risk of its own.
 */

import { Button } from '@votepit/ui/ds'
import type { RefObject } from 'react'
import { useT } from '../lib/i18n/context'

type Marker = '**' | '*' | '`'

function wrapSelection(
  textarea: HTMLTextAreaElement,
  value: string,
  onChange: (next: string) => void,
  marker: Marker,
): void {
  const start = textarea.selectionStart
  const end = textarea.selectionEnd
  const selected = value.slice(start, end)
  const next = value.slice(0, start) + marker + selected + marker + value.slice(end)
  onChange(next)

  requestAnimationFrame(() => {
    textarea.focus()
    textarea.setSelectionRange(start + marker.length, end + marker.length)
  })
}

export function MarkdownToolbar({
  textareaRef,
  value,
  onChange,
}: {
  textareaRef: RefObject<HTMLTextAreaElement | null>
  value: string
  onChange: (next: string) => void
}) {
  const t = useT('common')

  function apply(marker: Marker) {
    const textarea = textareaRef.current
    if (textarea === null) return
    wrapSelection(textarea, value, onChange, marker)
  }

  return (
    <div
      className="flex items-center gap-1"
      role="toolbar"
      aria-label={t('markdownToolbar.ariaLabel')}
    >
      <Button
        variant="secondary"
        testId="markdown-toolbar-bold"
        aria-label={t('markdownToolbar.bold')}
        title={t('markdownToolbar.bold')}
        onClick={() => apply('**')}
      >
        <span className="font-bold">B</span>
      </Button>
      <Button
        variant="secondary"
        testId="markdown-toolbar-italic"
        aria-label={t('markdownToolbar.italic')}
        title={t('markdownToolbar.italic')}
        onClick={() => apply('*')}
      >
        <span className="italic">I</span>
      </Button>
      <Button
        variant="secondary"
        testId="markdown-toolbar-code"
        aria-label={t('markdownToolbar.code')}
        title={t('markdownToolbar.code')}
        onClick={() => apply('`')}
      >
        <span className="font-mono text-vp-xs">{'</>'}</span>
      </Button>
    </div>
  )
}
