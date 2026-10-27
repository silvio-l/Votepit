import { fireEvent, render, screen } from '@testing-library/react'
import { BottomTabBar, Sheet } from '@votepit/ui/ds'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'

const items = [
  { id: 'board', label: 'Board', href: '/demo', icon: <span>b</span> },
  { id: 'roadmap', label: 'Roadmap', href: '/demo/roadmap', icon: <span>r</span> },
  { id: 'new', label: 'New idea', href: '/demo/submit', icon: <span>+</span>, primary: true },
  {
    id: 'inbox',
    label: 'Inbox',
    href: '/admin/inbox',
    icon: <span>n</span>,
    dot: true,
    badgeLabel: 'Inbox (unread messages)',
  },
]

describe('BottomTabBar', () => {
  it('renders one link per item with stable test ids and marks the current page', () => {
    render(
      <BottomTabBar
        items={items}
        currentHref="/demo/roadmap"
        navLabel="App navigation"
        testId="tabbar"
      />,
    )
    const nav = screen.getByRole('navigation', { name: 'App navigation' })
    expect(nav).toHaveAttribute('data-testid', 'tabbar')
    expect(screen.getAllByRole('link')).toHaveLength(4)
    expect(screen.getByTestId('tabbar-roadmap')).toHaveAttribute('aria-current', 'page')
    expect(screen.getByTestId('tabbar-board')).not.toHaveAttribute('aria-current')
    expect(screen.getByTestId('tabbar-new')).toHaveAttribute('href', '/demo/submit')
  })

  it('gives the primary action its own modifier and exposes unread state as text', () => {
    render(<BottomTabBar items={items} navLabel="App navigation" testId="tabbar" />)
    expect(screen.getByTestId('tabbar-new')).toHaveClass('ds-tabbar__link--primary')
    expect(screen.getByTestId('tabbar-board')).not.toHaveClass('ds-tabbar__link--primary')
    expect(screen.getByText('Inbox (unread messages)')).toBeInTheDocument()
  })

  it('runs onSelect instead of following the link when an item has one', () => {
    const onSelect = vi.fn()
    render(
      <BottomTabBar
        items={[{ ...items[2], onSelect }]}
        navLabel="App navigation"
        testId="tabbar"
      />,
    )
    fireEvent.click(screen.getByTestId('tabbar-new'))
    expect(onSelect).toHaveBeenCalledTimes(1)
    expect(screen.getByTestId('tabbar-new')).toHaveAttribute('href', '/demo/submit')
  })

  it('sets the column count from the number of items', () => {
    const { container } = render(
      <BottomTabBar items={items.slice(0, 2)} navLabel="App navigation" testId="tabbar" />,
    )
    const list = container.querySelector('.ds-tabbar__list') as HTMLElement
    expect(list.style.getPropertyValue('--ds-tabbar-count')).toBe('2')
  })
})

function SheetHarness({ onClose = () => {} }: { onClose?: () => void }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>
        open filters
      </button>
      <Sheet
        open={open}
        title="Filters"
        closeLabel="Close"
        testId="filters"
        onClose={() => {
          onClose()
          setOpen(false)
        }}
      >
        <button type="button">Top</button>
        <button type="button">Newest</button>
      </Sheet>
    </>
  )
}

describe('Sheet', () => {
  it('renders nothing while closed', () => {
    render(<SheetHarness />)
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('opens as a modal dialog named by its title and moves focus inside', () => {
    render(<SheetHarness />)
    fireEvent.click(screen.getByText('open filters'))
    const dialog = screen.getByRole('dialog', { name: 'Filters' })
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    // The close button is the first focusable element in the panel.
    expect(screen.getByRole('button', { name: 'Close' })).toHaveFocus()
    expect(dialog).toContainElement(document.activeElement as HTMLElement)
  })

  it('closes on Escape and returns focus to the opener', () => {
    const onClose = vi.fn()
    render(<SheetHarness onClose={onClose} />)
    const opener = screen.getByText('open filters')
    opener.focus()
    fireEvent.click(opener)
    fireEvent.keyDown(document.activeElement as HTMLElement, { key: 'Escape' })
    expect(onClose).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(opener).toHaveFocus()
  })

  it('traps Tab inside the panel in both directions', () => {
    render(<SheetHarness />)
    fireEvent.click(screen.getByText('open filters'))
    const first = screen.getByRole('button', { name: 'Close' })
    const last = screen.getByRole('button', { name: 'Newest' })
    last.focus()
    fireEvent.keyDown(last, { key: 'Tab' })
    expect(first).toHaveFocus()
    fireEvent.keyDown(first, { key: 'Tab', shiftKey: true })
    expect(last).toHaveFocus()
  })

  it('closes from the scrim and the close button', () => {
    render(<SheetHarness />)
    fireEvent.click(screen.getByText('open filters'))
    fireEvent.click(screen.getByTestId('filters-close'))
    expect(screen.queryByRole('dialog')).toBeNull()
    fireEvent.click(screen.getByText('open filters'))
    const scrim = document.querySelector('.ds-sheet__scrim') as HTMLElement
    fireEvent.click(scrim)
    expect(screen.queryByRole('dialog')).toBeNull()
  })
})
