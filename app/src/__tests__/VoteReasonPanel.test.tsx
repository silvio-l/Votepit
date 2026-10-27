import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import {
  VoteReasonAggregateView,
  VoteReasonPanel,
  type VoteReasonPanelLabels,
} from '@votepit/ui/ds'
import { describe, expect, it, vi } from 'vitest'

const labels: VoteReasonPanelLabels = {
  question: 'Warum dagegen? (optional)',
  options: {
    not_needed: 'Nicht nötig',
    too_expensive: 'Zu teuer oder aufwendig',
    worsens_workflow: 'Verschlechtert meinen Ablauf',
    other: 'Anderes',
  },
  noteLabel: 'Kurze Begründung (optional)',
  counter: (count, max) => `${count}/${max}`,
  save: 'Speichern',
  skip: 'Überspringen',
  privacy: 'Nur Betreiber sehen Begründungen, zusammengefasst. Nie öffentlich.',
  saved: 'Danke, deine Begründung ist gespeichert.',
}

function panelProps(overrides: Partial<Parameters<typeof VoteReasonPanel>[0]> = {}) {
  return {
    testId: 'idea-reason',
    reason: null,
    note: '',
    maxLength: 200,
    status: 'idle' as const,
    error: null,
    labels,
    onReasonChange: vi.fn(),
    onNoteChange: vi.fn(),
    onSave: vi.fn(),
    onSkip: vi.fn(),
    ...overrides,
  }
}

describe('VoteReasonPanel (P-03 follow-up after a vote against)', () => {
  it('offers the four fixed reasons as one radio group with a question', () => {
    render(<VoteReasonPanel {...panelProps()} />)

    const group = screen.getByRole('radiogroup', { name: 'Warum dagegen? (optional)' })
    expect(group).toBeInTheDocument()
    expect(screen.getAllByRole('radio')).toHaveLength(4)
    expect(screen.getByRole('radio', { name: 'Zu teuer oder aufwendig' })).toBeInTheDocument()
    expect(screen.getByTestId('idea-reason-option-too_expensive')).toBeInTheDocument()
  })

  it('reports the chosen reason and keeps the rest untouched', async () => {
    const onReasonChange = vi.fn()
    render(<VoteReasonPanel {...panelProps({ onReasonChange })} />)

    await userEvent.click(screen.getByRole('radio', { name: 'Anderes' }))

    expect(onReasonChange).toHaveBeenCalledWith('other')
  })

  it('shows a live counter for the plain-text note and reports typing', async () => {
    const onNoteChange = vi.fn()
    render(<VoteReasonPanel {...panelProps({ note: 'abc', onNoteChange })} />)

    expect(screen.getByText('3/200')).toBeInTheDocument()
    expect(screen.getByLabelText('Kurze Begründung (optional)')).toHaveValue('abc')
    await userEvent.type(screen.getByLabelText('Kurze Begründung (optional)'), 'x')
    expect(onNoteChange).toHaveBeenCalled()
  })

  it('save and skip are separate buttons; skip changes nothing on its own', async () => {
    const onSave = vi.fn()
    const onSkip = vi.fn()
    render(<VoteReasonPanel {...panelProps({ reason: 'other', onSave, onSkip })} />)

    await userEvent.click(screen.getByRole('button', { name: 'Überspringen' }))
    expect(onSkip).toHaveBeenCalledTimes(1)
    expect(onSave).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: 'Speichern' }))
    expect(onSave).toHaveBeenCalledTimes(1)
  })

  it('announces a validation or save error as an alert and a saved state as a status', () => {
    const { rerender } = render(
      <VoteReasonPanel {...panelProps({ error: 'Bitte wähle einen Grund aus.' })} />,
    )
    expect(screen.getByRole('alert')).toHaveTextContent('Bitte wähle einen Grund aus.')

    rerender(<VoteReasonPanel {...panelProps({ status: 'saved' })} />)
    expect(screen.getByRole('status')).toHaveTextContent('Danke, deine Begründung ist gespeichert.')
  })

  it('states that only operators see reasons, in summary', () => {
    render(<VoteReasonPanel {...panelProps()} />)
    expect(screen.getByText(labels.privacy)).toBeInTheDocument()
  })

  it('gives every interactive element a stable test id', () => {
    render(<VoteReasonPanel {...panelProps({ reason: 'not_needed' })} />)
    for (const button of screen.getAllByRole('button')) {
      expect(button.getAttribute('data-testid')).toMatch(/^idea-reason-/)
    }
    for (const radio of screen.getAllByRole('radio')) {
      expect(radio.getAttribute('data-testid')).toMatch(/^idea-reason-option-/)
    }
    expect(screen.getByLabelText('Kurze Begründung (optional)').getAttribute('data-testid')).toBe(
      'idea-reason-note',
    )
  })
})

describe('VoteReasonPanel accessibility (P-03)', () => {
  it('closes on Escape', () => {
    const onSkip = vi.fn()
    render(<VoteReasonPanel {...panelProps({ onSkip })} />)
    fireEvent.keyDown(screen.getByTestId('idea-reason'), { key: 'Escape' })
    expect(onSkip).toHaveBeenCalledTimes(1)
  })

  it('announces the character limit only at 180 and 200 characters', () => {
    const limited = {
      ...labels,
      nearLimit: 'Noch 20 Zeichen.',
      limitReached: 'Zeichenlimit erreicht.',
    }
    const { rerender } = render(
      <VoteReasonPanel {...panelProps({ labels: limited, note: 'a'.repeat(179) })} />,
    )
    const live = screen.getByTestId('idea-reason-live')
    expect(live).toHaveTextContent('')
    rerender(<VoteReasonPanel {...panelProps({ labels: limited, note: 'a'.repeat(180) })} />)
    expect(live).toHaveTextContent('Noch 20 Zeichen.')
    rerender(<VoteReasonPanel {...panelProps({ labels: limited, note: 'a'.repeat(200) })} />)
    expect(live).toHaveTextContent('Zeichenlimit erreicht.')
    expect(live).toHaveAttribute('aria-live', 'polite')
  })
})

describe('VoteReasonAggregateView (operator view, no names)', () => {
  const emptyLabels = {
    title: 'Begründungen zu Dagegen-Stimmen (nur Betreiber)',
    empty: 'Noch keine Begründungen.',
    downCount: (count: number) => `${count} Dagegen-Stimmen`,
    textsTitle: 'Freitexte',
    noNames: 'Ohne Namen. Nur für Betreiber sichtbar.',
    options: labels.options,
  }

  it('shows the count per reason and the free texts, without any identity', () => {
    render(
      <VoteReasonAggregateView
        testId="idea-reasons"
        labels={emptyLabels}
        aggregate={{
          down_count: 3,
          counts: { not_needed: 1, too_expensive: 1, worsens_workflow: 0, other: 1 },
          notes: [{ reason: 'too_expensive', note: 'Zu teuer für uns' }],
        }}
      />,
    )

    expect(screen.getByText('3 Dagegen-Stimmen')).toBeInTheDocument()
    expect(screen.getByTestId('idea-reasons-count-other')).toHaveTextContent('1')
    expect(screen.getByText('Zu teuer für uns')).toBeInTheDocument()
    expect(screen.getByText('Ohne Namen. Nur für Betreiber sichtbar.')).toBeInTheDocument()
  })

  it('shows an empty state when no reason exists', () => {
    render(
      <VoteReasonAggregateView
        testId="idea-reasons"
        labels={emptyLabels}
        aggregate={{
          down_count: 0,
          counts: { not_needed: 0, too_expensive: 0, worsens_workflow: 0, other: 0 },
          notes: [],
        }}
      />,
    )

    expect(screen.getByText('Noch keine Begründungen.')).toBeInTheDocument()
  })
})
