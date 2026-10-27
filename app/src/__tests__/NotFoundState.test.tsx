/**
 * The shared not-found page: copy and landmarks, document title, the two
 * actions (home and back with its fallback), reduced motion, and parity of the
 * six locale files. Routing decisions live in NotFoundRouting.test.tsx.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { NotFoundState } from '../components/NotFoundState'
import deCopy from '../lib/i18n/dictionaries/notFoundPage.de'
import enCopy from '../lib/i18n/dictionaries/notFoundPage.en'
import esCopy from '../lib/i18n/dictionaries/notFoundPage.es'
import frCopy from '../lib/i18n/dictionaries/notFoundPage.fr'
import itCopy from '../lib/i18n/dictionaries/notFoundPage.it'
import nlCopy from '../lib/i18n/dictionaries/notFoundPage.nl'

function setReducedMotion(reduce: boolean) {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: reduce && query.includes('prefers-reduced-motion'),
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  )
}

function renderAt(entries: string[], index = entries.length - 1) {
  return render(
    <MemoryRouter initialEntries={entries} initialIndex={index}>
      <Routes>
        <Route path="/" element={<p>HOME</p>} />
        <Route path="/start" element={<p>START PAGE</p>} />
        <Route path="/start/deep" element={<NotFoundState />} />
        <Route path="/unknown" element={<NotFoundState />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  setReducedMotion(false)
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.title = 'Votepit'
})

describe('NotFoundState', () => {
  it('renders one H1 with the blame-free copy and no alert role', () => {
    renderAt(['/unknown'])

    expect(screen.getByRole('heading', { level: 1, name: deCopy.title })).toBeInTheDocument()
    expect(screen.getByTestId('not-found-message')).toHaveTextContent(deCopy.message)
    expect(screen.getByTestId('not-found-page')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('never blames the visitor', () => {
    renderAt(['/unknown'])
    const text = screen.getByTestId('not-found-page').textContent ?? ''
    for (const word of ['falsch', 'Tippfehler', 'ungültig', 'Fehler', 'existiert nicht']) {
      expect(text).not.toContain(word)
    }
  })

  it('offers the homepage as the primary link and a back button', () => {
    renderAt(['/unknown'])

    const home = screen.getByTestId('not-found-home')
    expect(home.tagName).toBe('A')
    expect(home).toHaveAttribute('href', '/')
    expect(home).toHaveTextContent(deCopy.backHome)
    expect(screen.getByTestId('not-found-back')).toHaveTextContent(deCopy.back)
  })

  it('goes back in history when there is a previous entry', async () => {
    const user = userEvent.setup()
    renderAt(['/start', '/start/deep'])

    await user.click(screen.getByTestId('not-found-back'))

    expect(screen.getByText('START PAGE')).toBeInTheDocument()
  })

  it('falls back to the homepage on a fresh entry without history', async () => {
    const user = userEvent.setup()
    renderAt(['/unknown'])

    await user.click(screen.getByTestId('not-found-back'))

    expect(screen.getByText('HOME')).toBeInTheDocument()
  })

  it('sets the document title and restores the previous one on unmount', () => {
    document.title = 'Votepit'
    const { unmount } = renderAt(['/unknown'])

    expect(document.title).toBe(`${deCopy.title} · Votepit`)

    unmount()
    expect(document.title).toBe('Votepit')
  })

  it('keeps the illustration decorative and turns its motion on by default', () => {
    const { container } = renderAt(['/unknown'])

    const art = container.querySelector('svg.ds-not-found__art')
    expect(art).toHaveAttribute('aria-hidden', 'true')
    expect(screen.getByTestId('not-found-page')).toHaveAttribute('data-motion', 'on')
  })

  it('switches the illustration motion off under prefers-reduced-motion', () => {
    setReducedMotion(true)
    renderAt(['/unknown'])

    expect(screen.getByTestId('not-found-page')).toHaveAttribute('data-motion', 'off')
  })

  it('renders page-specific copy when a page passes its own title and message', () => {
    render(
      <MemoryRouter>
        <NotFoundState title="Specific title" message="Specific message" />
      </MemoryRouter>,
    )

    expect(screen.getByRole('heading', { level: 1, name: 'Specific title' })).toBeInTheDocument()
    expect(screen.getByTestId('not-found-message')).toHaveTextContent('Specific message')
    expect(document.title).toBe('Specific title · Votepit')
    // The shared actions keep the generic labels.
    expect(screen.getByTestId('not-found-home')).toHaveTextContent(deCopy.backHome)
  })

  it('renders an optional secondary link between the homepage and back actions', () => {
    render(
      <MemoryRouter>
        <NotFoundState
          secondaryLink={{ to: '/demo', label: 'Zurück zum Board', testId: 'x-back' }}
        />
      </MemoryRouter>,
    )

    const link = screen.getByTestId('x-back')
    expect(link.tagName).toBe('A')
    expect(link).toHaveAttribute('href', '/demo')
    expect(link).toHaveTextContent('Zurück zum Board')
  })

  it('renders no secondary link by default', () => {
    renderAt(['/unknown'])
    expect(screen.queryByTestId('not-found-back-to-board')).not.toBeInTheDocument()
  })

  it('keeps the copy for every locale complete (title, message, both actions)', () => {
    for (const copy of [enCopy, esCopy, frCopy, itCopy, nlCopy]) {
      expect(Object.keys(copy).sort()).toEqual(Object.keys(deCopy).sort())
      for (const value of Object.values(copy)) expect(value.trim().length).toBeGreaterThan(0)
    }
  })
})
