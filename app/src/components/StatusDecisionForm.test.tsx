/**
 * RTL tests for the moderator reason form (P-01): the soft prompt appears for
 * declined statuses only and never blocks saving, and the submitted payload
 * carries trimmed plaintext plus the optional role.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { StatusDecisionForm } from './StatusDecisionForm'

function renderForm(overrides: Partial<Parameters<typeof StatusDecisionForm>[0]> = {}) {
  const onSubmit = vi.fn()
  const onCancel = vi.fn()
  render(
    <StatusDecisionForm
      statusLabel="Declined"
      declined={false}
      pending={false}
      onSubmit={onSubmit}
      onCancel={onCancel}
      {...overrides}
    />,
  )
  return { onSubmit, onCancel }
}

describe('StatusDecisionForm', () => {
  it('shows the gentle prompt only for declined statuses without a reason', async () => {
    renderForm({ declined: true })
    expect(screen.getByText('Eine kurze Begründung hilft deiner Community.')).toBeInTheDocument()

    await userEvent.type(screen.getByTestId('idea-decision-reason'), 'x')
    expect(screen.queryByText('Eine kurze Begründung hilft deiner Community.')).toBeNull()
  })

  it('shows no prompt for non-declined statuses', () => {
    renderForm({ declined: false })
    expect(screen.queryByText('Eine kurze Begründung hilft deiner Community.')).toBeNull()
  })

  it('never blocks saving: submit works with an empty reason', async () => {
    const { onSubmit } = renderForm({ declined: true })
    await userEvent.click(screen.getByTestId('idea-decision-save'))
    expect(onSubmit).toHaveBeenCalledWith({ reason: '', nextStep: '', responsibleRole: '' })
  })

  it('submits the optional fields including the responsible role', async () => {
    const { onSubmit } = renderForm()
    await userEvent.type(screen.getByTestId('idea-decision-reason'), 'Not in scope now.')
    await userEvent.type(screen.getByTestId('idea-decision-next-step'), 'Revisit in Q1')
    await userEvent.selectOptions(screen.getByTestId('idea-decision-role'), 'moderation')
    await userEvent.click(screen.getByTestId('idea-decision-save'))

    expect(onSubmit).toHaveBeenCalledWith({
      reason: 'Not in scope now.',
      nextStep: 'Revisit in Q1',
      responsibleRole: 'moderation',
    })
  })

  it('cancel calls onCancel without submitting', async () => {
    const { onSubmit, onCancel } = renderForm()
    await userEvent.click(screen.getByTestId('idea-decision-cancel'))
    expect(onCancel).toHaveBeenCalledTimes(1)
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('blocks saving with a blank reason only when the board requires a reason', async () => {
    const { onSubmit } = renderForm({ reasonRequired: true })
    expect(screen.getByTestId('idea-decision-save')).toBeDisabled()
    expect(
      screen.getByText('Für diese Entscheidung ist eine Begründung Pflicht.'),
    ).toBeInTheDocument()
    await userEvent.type(screen.getByTestId('idea-decision-reason'), '   ')
    expect(screen.getByTestId('idea-decision-save')).toBeDisabled()
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('keeps saving open with a blank reason when the board does not require one', async () => {
    renderForm({ reasonRequired: false })
    expect(screen.getByTestId('idea-decision-save')).toBeEnabled()
    expect(screen.getByTestId('idea-decision-reason')).not.toBeRequired()
  })

  it('enforces the 1000-character reason limit in the field', async () => {
    renderForm()
    const field = screen.getByTestId('idea-decision-reason') as HTMLTextAreaElement
    expect(field.maxLength).toBe(1000)
    expect((screen.getByTestId('idea-decision-next-step') as HTMLInputElement).maxLength).toBe(280)
  })
})
