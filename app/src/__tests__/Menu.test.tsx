/**
 * RTL tests for @votepit/ui's Menu — the WAI-ARIA menu-button contract that
 * the app's account menu relies on: closed by default, opens on click and
 * ArrowDown, arrow keys move between items, Escape closes and restores focus
 * to the trigger, selecting an action closes the menu and runs it.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Menu, type MenuItem } from '@votepit/ui'
import { describe, expect, it, vi } from 'vitest'

function renderMenu(onLogout = vi.fn()) {
  const items: MenuItem[] = [
    { kind: 'link', label: 'Profil', href: '/profile' },
    { kind: 'link', label: 'Verwaltung', href: '/admin/boards' },
    { kind: 'separator' },
    { kind: 'action', label: 'Abmelden', onSelect: onLogout },
  ]
  render(<Menu label="Konto" items={items} />)
  return { onLogout }
}

describe('Menu', () => {
  it('is closed by default and opens on click with the first item focused', async () => {
    renderMenu()
    const trigger = screen.getByRole('button', { name: 'Konto' })
    expect(trigger).toHaveAttribute('aria-haspopup', 'menu')
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()

    await userEvent.click(trigger)

    expect(trigger).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('menu', { name: 'Konto' })).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: 'Profil' })).toHaveFocus()
    expect(screen.getByRole('menuitem', { name: 'Profil' })).toHaveAttribute('href', '/profile')
  })

  it('moves focus with the arrow keys and closes on Escape, returning focus to the trigger', async () => {
    renderMenu()
    const trigger = screen.getByRole('button', { name: 'Konto' })
    trigger.focus()

    await userEvent.keyboard('{ArrowDown}')
    expect(screen.getByRole('menuitem', { name: 'Profil' })).toHaveFocus()

    await userEvent.keyboard('{ArrowDown}')
    expect(screen.getByRole('menuitem', { name: 'Verwaltung' })).toHaveFocus()

    await userEvent.keyboard('{End}')
    expect(screen.getByRole('menuitem', { name: 'Abmelden' })).toHaveFocus()

    // Wraps around past the last item.
    await userEvent.keyboard('{ArrowDown}')
    expect(screen.getByRole('menuitem', { name: 'Profil' })).toHaveFocus()

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
  })

  it('runs an action item and closes the menu', async () => {
    const { onLogout } = renderMenu()
    await userEvent.click(screen.getByRole('button', { name: 'Konto' }))
    await userEvent.click(screen.getByRole('menuitem', { name: 'Abmelden' }))

    expect(onLogout).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('closes on a pointer press outside the widget', async () => {
    render(
      <>
        <Menu label="Konto" items={[{ kind: 'link', label: 'Profil', href: '/profile' }]} />
        <p>Seiteninhalt</p>
      </>,
    )
    await userEvent.click(screen.getByRole('button', { name: 'Konto' }))
    expect(screen.getByRole('menu')).toBeInTheDocument()

    await userEvent.click(screen.getByText('Seiteninhalt'))
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })
})
