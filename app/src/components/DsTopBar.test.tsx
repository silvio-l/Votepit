import { fireEvent, render, screen } from '@testing-library/react'
import { Avatar, DsMenu, initialsFrom, LangSwitch, TopBar } from '@votepit/ui/ds'
import { describe, expect, it, vi } from 'vitest'

describe('initialsFrom', () => {
  it('takes the first letters of the first and last name', () => {
    expect(initialsFrom('Ada Lovelace')).toBe('AL')
    expect(initialsFrom('ada')).toBe('A')
    expect(initialsFrom('  ')).toBe('?')
    expect(initialsFrom(null)).toBe('?')
  })
})

describe('Avatar', () => {
  it('shows initials decoratively when there is no image', () => {
    const { container } = render(<Avatar name="Ada Lovelace" />)
    const el = container.querySelector('.ds-avatar')
    expect(el?.textContent).toBe('AL')
    expect(el?.getAttribute('aria-hidden')).toBe('true')
  })

  it('uses the image when given', () => {
    const { container } = render(<Avatar src="/avatar/a.png" name="Ada" />)
    const img = container.querySelector('img.ds-avatar')
    expect(img?.getAttribute('src')).toBe('/avatar/a.png')
    expect(img?.getAttribute('alt')).toBe('')
  })
})

describe('TopBar', () => {
  it('renders the header with its test id, a labelled nav and the current link', () => {
    render(
      <TopBar
        testId="topbar"
        navLabel="Hauptnavigation"
        brand={<a href="/">Votepit</a>}
        nav={
          <a href="/board" aria-current="page">
            Board
          </a>
        }
        actions={<span>actions</span>}
      />,
    )
    expect(screen.getByTestId('topbar').tagName).toBe('HEADER')
    expect(screen.getByRole('navigation', { name: 'Hauptnavigation' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Board' }).getAttribute('aria-current')).toBe('page')
  })
})

describe('DsMenu', () => {
  const items = [
    { kind: 'link' as const, label: 'Profile', href: '/profile' },
    { kind: 'separator' as const },
    { kind: 'action' as const, label: 'Log out', onSelect: vi.fn() },
  ]

  it('opens on click, exposes item test ids, and Escape closes it returning focus', () => {
    render(<DsMenu testId="menu" label="Me" ariaLabel="Account" items={items} />)
    const trigger = screen.getByTestId('menu')
    expect(trigger.getAttribute('aria-expanded')).toBe('false')
    fireEvent.click(trigger)
    expect(trigger.getAttribute('aria-expanded')).toBe('true')
    expect(screen.getByRole('menu', { name: 'Account' })).toBeTruthy()
    expect(screen.getByTestId('menu-item-0').getAttribute('role')).toBe('menuitem')
    expect(screen.getByTestId('menu-item-1').tagName).toBe('BUTTON')
    fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' })
    expect(screen.queryByRole('menu')).toBeNull()
    expect(document.activeElement).toBe(trigger)
  })

  it('ArrowDown on the trigger opens and focuses the first item', () => {
    render(<DsMenu testId="menu" label="Me" ariaLabel="Account" items={items} />)
    const trigger = screen.getByTestId('menu')
    fireEvent.keyDown(trigger, { key: 'ArrowDown' })
    expect(document.activeElement).toBe(screen.getByTestId('menu-item-0'))
  })

  it('selecting an action calls it and closes the menu', () => {
    const onSelect = vi.fn()
    render(
      <DsMenu
        testId="menu"
        label="Me"
        ariaLabel="Account"
        items={[{ kind: 'action', label: 'Log out', onSelect }]}
      />,
    )
    fireEvent.click(screen.getByTestId('menu'))
    fireEvent.click(screen.getByTestId('menu-item-0'))
    expect(onSelect).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('menu')).toBeNull()
  })
})

describe('LangSwitch', () => {
  it('marks the current locale as the checked radio item', () => {
    render(
      <LangSwitch
        testId="lang"
        value="de"
        ariaLabel="Language"
        options={[
          { code: 'de', label: 'Deutsch' },
          { code: 'en', label: 'English' },
        ]}
        onChange={() => {}}
      />,
    )
    fireEvent.click(screen.getByTestId('lang'))
    expect(
      screen.getByRole('menuitemradio', { name: 'Deutsch' }).getAttribute('aria-checked'),
    ).toBe('true')
    expect(
      screen.getByRole('menuitemradio', { name: 'English' }).getAttribute('aria-checked'),
    ).toBe('false')
  })
})
