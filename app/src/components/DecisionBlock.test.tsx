/**
 * RTL tests for the public decision block (P-01): renders plaintext reason,
 * next step and role label; hides itself without a decision; the history is
 * collapsible and never shows an acting user.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { StatusDecision } from '../lib/api/model'
import { DecisionBlock } from './DecisionBlock'

const statusLabel = (key: string) => (key === 'declined' ? 'Abgelehnt' : key)

const latest: StatusDecision = {
  id: 2,
  status_from: 'open',
  status_to: 'declined',
  reason: 'Passt nicht zu unserem Ziel. <b>nicht</b> HTML',
  next_step: 'Wir prüfen es im Frühjahr.',
  responsible_role: 'team',
  created_at: '2026-10-20 09:30:00',
}

const older: StatusDecision = {
  id: 1,
  status_from: 'open',
  status_to: 'planned',
  reason: null,
  next_step: null,
  responsible_role: null,
  created_at: '2026-10-01 08:00:00',
}

describe('DecisionBlock', () => {
  it('renders nothing without a decision', () => {
    const { container } = render(
      <DecisionBlock decision={null} history={[]} statusLabel={statusLabel} />,
    )
    expect(container).toBeEmptyDOMElement()
  })

  it('shows status, reason as escaped text, next step and role label', () => {
    render(<DecisionBlock decision={latest} history={[latest]} statusLabel={statusLabel} />)

    expect(screen.getByTestId('idea-decision-status')).toHaveTextContent('Abgelehnt')
    expect(screen.getByTestId('idea-decision-reason-text')).toHaveTextContent(
      'Passt nicht zu unserem Ziel. <b>nicht</b> HTML',
    )
    // Plaintext: no element is created from the markup inside the reason.
    expect(screen.getByTestId('idea-decision-reason-text').querySelector('b')).toBeNull()
    expect(screen.getByTestId('idea-decision-next-step-text')).toHaveTextContent(
      'Wir prüfen es im Frühjahr.',
    )
    expect(screen.getByTestId('idea-decision-role-text')).toHaveTextContent('Team')
  })

  it('keeps the history collapsed and lists entries newest first without actor data', async () => {
    render(<DecisionBlock decision={latest} history={[latest, older]} statusLabel={statusLabel} />)

    const details = screen.getByTestId('idea-decision-history') as HTMLDetailsElement
    expect(details.open).toBe(false)
    await userEvent.click(screen.getByTestId('idea-decision-history-toggle'))
    expect(details.open).toBe(true)

    const items = screen.getByTestId('idea-decision-history').querySelectorAll('li')
    expect(items).toHaveLength(2)
    expect(items[0]).toHaveAttribute('data-testid', 'idea-decision-history-2')
    expect(items[1]).toHaveTextContent('Ohne Begründung')
    expect(details.textContent ?? '').not.toMatch(/actor|@/i)
  })
})
