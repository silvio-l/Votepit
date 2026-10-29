import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as api from '../lib/api'
import { NewIdeaSheet } from './NewIdeaSheet'

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  getSubmitForm: vi.fn(async () => ({
    board: { id: 1, slug: 'demo', name: 'Demo Board' },
    is_authenticated: true,
    form_at: 'stamp',
  })),
  searchDuplicates: vi.fn(async () => ({ candidates: [] })),
}))

function renderSheet(open: boolean, onClose = vi.fn()) {
  render(
    <MemoryRouter>
      <NewIdeaSheet
        open={open}
        onClose={onClose}
        boardSlug="demo"
        boardHref="/demo"
        user={{ id: 1, is_admin: false } as never}
      />
    </MemoryRouter>,
  )
  return { onClose }
}

describe('NewIdeaSheet', () => {
  beforeEach(() => {
    vi.mocked(api.getSubmitForm).mockClear()
  })

  it('renders nothing while closed and does not load the form', () => {
    renderSheet(false)
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(api.getSubmitForm).not.toHaveBeenCalled()
  })

  it('loads the submit form when opened, inside a modal dialog', async () => {
    renderSheet(true)
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    await waitFor(() => expect(screen.getByTestId('submit-idea-submit')).toBeInTheDocument())
    expect(api.getSubmitForm).toHaveBeenCalledWith('demo')
  })

  it('closes through the sheet cancel button', async () => {
    const { onClose } = renderSheet(true)
    await screen.findByTestId('submit-cancel')
    await userEvent.click(screen.getByTestId('submit-cancel'))
    expect(onClose).toHaveBeenCalledTimes(1)
  })
})
