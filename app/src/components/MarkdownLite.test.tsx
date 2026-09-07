/**
 * RTL tests for MarkdownLite — asserts actual rendered DOM, not just the
 * MdNode[] tree (that's covered exhaustively in lib/markdownLite.test.ts).
 * Focus here: correct element mapping, safe link attributes, and that no
 * user text is ever interpreted as markup by the DOM.
 */

import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { MarkdownLite } from './MarkdownLite'

describe('MarkdownLite', () => {
  it('renders plain text as-is', () => {
    render(<MarkdownLite text="hello world" />)
    expect(screen.getByText('hello world')).toBeInTheDocument()
  })

  it('renders **bold** as a <strong> element', () => {
    const { container } = render(<MarkdownLite text="**bold**" />)
    const strong = container.querySelector('strong')
    expect(strong).not.toBeNull()
    expect(strong).toHaveTextContent('bold')
  })

  it('renders *italic* as an <em> element', () => {
    const { container } = render(<MarkdownLite text="*italic*" />)
    const em = container.querySelector('em')
    expect(em).not.toBeNull()
    expect(em).toHaveTextContent('italic')
  })

  it('renders `code` as a <code> element', () => {
    const { container } = render(<MarkdownLite text="`code`" />)
    const code = container.querySelector('code')
    expect(code).not.toBeNull()
    expect(code).toHaveTextContent('code')
  })

  it('renders a bare https URL as a link with safe attributes', () => {
    render(<MarkdownLite text="see https://example.com/path for more" />)
    const link = screen.getByRole('link', { name: 'https://example.com/path' })
    expect(link).toHaveAttribute('href', 'https://example.com/path')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', 'noopener noreferrer nofollow ugc')
  })

  it('never produces a link for a javascript: URL', () => {
    render(<MarkdownLite text="javascript:alert(1)" />)
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })

  it('a literal <img> tag in the input is rendered as inert text, never as an element', () => {
    const { container } = render(<MarkdownLite text="<img src=x onerror=alert(1)>" />)
    expect(container.querySelector('img')).toBeNull()
    expect(container).toHaveTextContent('<img src=x onerror=alert(1)>')
  })

  it('a literal <script> tag in the input never becomes a script element', () => {
    const { container } = render(<MarkdownLite text="<script>alert(1)</script>" />)
    expect(container.querySelector('script')).toBeNull()
    expect(container).toHaveTextContent('<script>alert(1)</script>')
  })

  it('nested bold+italic renders nested elements correctly', () => {
    const { container } = render(<MarkdownLite text="**bold *and italic* text**" />)
    const strong = container.querySelector('strong')
    expect(strong).not.toBeNull()
    const em = strong?.querySelector('em')
    expect(em).not.toBeNull()
    expect(em).toHaveTextContent('and italic')
  })

  it('renders an empty string as nothing', () => {
    const { container } = render(<MarkdownLite text="" />)
    expect(container).toHaveTextContent('')
  })

  describe('external-link warning', () => {
    afterEach(() => {
      vi.restoreAllMocks()
    })

    it('shows a "leaving Votepit" confirmation before opening a cross-origin link', async () => {
      render(<MarkdownLite text="see https://example.com/path for more" />)
      await userEvent.click(screen.getByRole('link', { name: 'https://example.com/path' }))
      const dialog = screen.getByRole('alertdialog')
      expect(within(dialog).getByText('Du verlässt Votepit')).toBeInTheDocument()
      expect(within(dialog).getByText('https://example.com/path')).toBeInTheDocument()
    })

    it('opens the link in a new tab via window.open only after confirming', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
      render(<MarkdownLite text="https://example.com/path" />)
      await userEvent.click(screen.getByRole('link', { name: 'https://example.com/path' }))
      expect(openSpy).not.toHaveBeenCalled()
      await userEvent.click(screen.getByRole('button', { name: 'Trotzdem öffnen' }))
      expect(openSpy).toHaveBeenCalledWith(
        'https://example.com/path',
        '_blank',
        'noopener,noreferrer',
      )
      expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    })

    it('cancelling the warning does not open the link', async () => {
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
      render(<MarkdownLite text="https://example.com/path" />)
      await userEvent.click(screen.getByRole('link', { name: 'https://example.com/path' }))
      await userEvent.click(screen.getByRole('button', { name: 'Abbrechen' }))
      expect(openSpy).not.toHaveBeenCalled()
      expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    })

    it('does not warn for a same-origin link', async () => {
      const href = `${window.location.origin}/some-board/idea/42`
      render(<MarkdownLite text={href} />)
      await userEvent.click(screen.getByRole('link', { name: href }))
      expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    })

    it('flags a punycode hostname as suspicious', async () => {
      render(<MarkdownLite text="https://xn--pple-43d.com/login" />)
      await userEvent.click(screen.getByRole('link'))
      expect(screen.getByText(/Punycode/)).toBeInTheDocument()
    })
  })
})
