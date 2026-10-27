/**
 * SetupWizard test ids — every step landmark and interactive control carries a
 * stable kebab-case `setup-wizard-*` id (the testid guard only checks that an
 * id exists; this pins the names tests and agents address).
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import SetupWizard from './SetupWizard'

function renderWizard() {
  return render(
    <MemoryRouter>
      <SetupWizard
        boards={[]}
        allowedVisibilities={['public', 'unlisted', 'private']}
        defaultVisibility="public"
        onDone={() => {}}
      />
    </MemoryRouter>,
  )
}

describe('SetupWizard test ids', () => {
  it('exposes the welcome step and its actions', () => {
    renderWizard()

    expect(screen.getByTestId('setup-wizard-step-welcome')).toBeInTheDocument()
    expect(screen.getByTestId('setup-wizard-steps')).toBeInTheDocument()
    expect(screen.getByTestId('setup-wizard-get-started')).toBeInTheDocument()
    expect(screen.getByTestId('setup-wizard-welcome-later')).toBeInTheDocument()
  })

  it('exposes every control of the board step once the first step is passed', async () => {
    renderWizard()
    await userEvent.click(screen.getByTestId('setup-wizard-get-started'))

    expect(screen.getByTestId('setup-wizard-step-board')).toBeInTheDocument()
    expect(screen.getByTestId('setup-wizard-board-form')).toBeInTheDocument()
    for (const id of [
      'setup-wizard-template',
      'setup-wizard-name',
      'setup-wizard-slug',
      'setup-wizard-visibility',
      'setup-wizard-anonymous-voting',
      'setup-wizard-create-board',
      'setup-wizard-board-later',
    ]) {
      expect(screen.getByTestId(id)).toBeInTheDocument()
    }
    // Operator acknowledgement exists on Cloud only (Community shows none).
    expect(screen.queryByTestId('setup-wizard-operator-ack')).toBeNull()
  })
})

describe('SetupWizard reopen and look step', () => {
  const ALPHA = {
    id: 1,
    slug: 'alpha',
    name: 'Alpha Board',
    frozen_at: null,
    idea_count: 0,
    vote_count: 0,
  }

  it('reopens straight at the look step for the requested board', () => {
    render(
      <MemoryRouter>
        <SetupWizard
          boards={[ALPHA]}
          allowedVisibilities={['public']}
          defaultVisibility="public"
          mode="reopen"
          targetBoardSlug="alpha"
          onDone={() => {}}
        />
      </MemoryRouter>,
    )
    expect(screen.getByTestId('setup-wizard-step-look')).toBeInTheDocument()
    expect(screen.queryByTestId('setup-wizard-step-welcome')).toBeNull()
  })

  it('shows a contrast warning for the default accent instead of blocking', async () => {
    render(
      <MemoryRouter>
        <SetupWizard
          boards={[ALPHA]}
          allowedVisibilities={['public']}
          defaultVisibility="public"
          mode="reopen"
          targetBoardSlug="alpha"
          onDone={() => {}}
        />
      </MemoryRouter>,
    )
    expect(screen.getByTestId('setup-wizard-look-contrast')).toBeInTheDocument()
    // Save waits for the saved look to load (reopen), so wait for it instead of reading it at once.
    await waitFor(() => expect(screen.getByTestId('setup-wizard-look-save')).toBeEnabled())
  })

  it('skipping the look step leads to the ready step without changing anything', async () => {
    const onDone = vi.fn()
    render(
      <MemoryRouter>
        <SetupWizard
          boards={[ALPHA]}
          allowedVisibilities={['public']}
          defaultVisibility="public"
          mode="reopen"
          targetBoardSlug="alpha"
          onDone={onDone}
        />
      </MemoryRouter>,
    )
    await userEvent.click(screen.getByTestId('setup-wizard-look-skip'))
    // Skip goes to the ready step; the wizard only finishes from its own button.
    expect(await screen.findByTestId('setup-wizard-step-ready')).toBeInTheDocument()
    expect(onDone).not.toHaveBeenCalled()
    await userEvent.click(screen.getByTestId('setup-wizard-go-to-dashboard'))
    expect(onDone).toHaveBeenCalledTimes(1)
  })

  it('shows no plan badges on a Community installation', () => {
    render(
      <MemoryRouter>
        <SetupWizard
          boards={[ALPHA]}
          allowedVisibilities={['public']}
          defaultVisibility="public"
          mode="reopen"
          targetBoardSlug="alpha"
          onDone={() => {}}
        />
      </MemoryRouter>,
    )
    expect(screen.queryByTestId('setup-wizard-look-cover-locked')).toBeNull()
    expect(screen.queryByTestId('setup-wizard-look-logo-locked')).toBeNull()
  })
})
