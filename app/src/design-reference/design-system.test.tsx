import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import {
  Badge,
  Button,
  Checkbox,
  ConfirmDialog,
  InlineNotice,
  Section,
  Select,
  StatCard,
  StatusChip,
  statusChipFor,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeaderCell,
  TableRow,
  TextArea,
  TextField,
  VoteControl,
  VoteSummary,
} from '@votepit/ui/ds'
import { createRef } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { CAT_STATUS, CAT_SUMMARY, CAT_VOTE } from './catalogueLabels'
import { DesignReference } from './DesignReference'

describe('VoteControl', () => {
  it('ignores a second activation while the vote is pending', async () => {
    const user = userEvent.setup()
    let settle: () => void = () => undefined
    const onVote = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          settle = resolve
        }),
    )
    render(
      <VoteControl
        labels={CAT_VOTE.labels}
        direction="up"
        count={24}
        ideaTitle="Offline-Modus für unterwegs"
        onVote={onVote}
        testId="vote-up"
      />,
    )
    const button = screen.getByTestId('vote-up')

    await user.click(button)
    await user.click(button)

    expect(onVote).toHaveBeenCalledTimes(1)
    expect(button).toHaveAttribute('aria-busy', 'true')

    await act(async () => {
      settle()
    })
    expect(button).not.toHaveAttribute('aria-busy')
  })

  it('keeps the previous pressed state and announces the error politely when the vote fails', async () => {
    const user = userEvent.setup()
    const onVote = vi.fn(() => Promise.reject(new Error('network down')))
    render(
      <VoteControl
        labels={CAT_VOTE.labels}
        direction="down"
        count={6}
        selected
        ideaTitle="Offline-Modus für unterwegs"
        onVote={onVote}
        testId="vote-down"
      />,
    )
    const button = screen.getByTestId('vote-down')

    await user.click(button)

    expect(await screen.findByText(/nicht gespeichert/)).toBeInTheDocument()
    expect(button).toHaveAttribute('aria-pressed', 'true')
    expect(button).not.toHaveAttribute('aria-busy')
    expect(screen.getByRole('status')).toHaveAttribute('aria-live', 'polite')
  })

  it('exposes aria-pressed and an accessible name with target and count', () => {
    const { rerender } = render(
      <VoteControl
        labels={CAT_VOTE.labels}
        direction="up"
        count={24}
        selected
        ideaTitle="Offline-Modus für unterwegs"
        testId="vote-up"
      />,
    )
    const up = screen.getByRole('button', {
      name: 'Dafür stimmen: Offline-Modus für unterwegs, 24 Stimmen',
    })
    expect(up).toHaveAttribute('aria-pressed', 'true')

    rerender(
      <VoteControl
        labels={CAT_VOTE.labels}
        direction="down"
        count={1}
        ideaTitle="Offline-Modus für unterwegs"
        testId="vote-down"
      />,
    )
    const down = screen.getByRole('button', {
      name: 'Dagegen stimmen: Offline-Modus für unterwegs, 1 Stimme',
    })
    expect(down).toHaveAttribute('aria-pressed', 'false')
  })
})

describe('VoteSummary', () => {
  it('shows the zero state without a percentage or consensus wording', () => {
    const { container } = render(<VoteSummary up={0} down={0} labels={CAT_SUMMARY.labels} />)

    expect(screen.getByText('Noch keine Stimmen')).toBeInTheDocument()
    expect(container.textContent).not.toMatch(/%/)
    expect(container.textContent).not.toMatch(/konsens|consensus/i)
  })

  it('derives net and approval from the counts (24/6 gives +18 and 80 %)', () => {
    render(<VoteSummary up={24} down={6} labels={CAT_SUMMARY.labels} />)

    expect(screen.getByTestId('vote-summary-net')).toHaveTextContent(/^\+18$/)
    expect(screen.getByTestId('vote-summary-approval')).toHaveTextContent(/^80\s%$/)
    expect(screen.getByTestId('vote-summary-up')).toHaveTextContent(/^24$/)
    expect(screen.getByTestId('vote-summary-down')).toHaveTextContent(/^6$/)
  })
})

describe('StatusChip', () => {
  it('never uses an error colour class', () => {
    for (const tone of ['neutral', 'info', 'progress', 'success'] as const) {
      const { unmount } = render(<StatusChip tone={tone}>Label</StatusChip>)
      const chip = screen.getByTestId('status-chip')
      expect(chip.className).not.toMatch(/error|negative|danger/)
      expect(chip).toHaveAttribute('data-tone', tone)
      unmount()
    }
  })

  it('maps declined and not-implemented to neutral with an explaining icon', () => {
    expect(statusChipFor('declined', CAT_STATUS.declined)).toMatchObject({
      tone: 'neutral',
      icon: 'minus-circle',
    })
    expect(statusChipFor('not-implemented', CAT_STATUS['not-implemented'])).toMatchObject({
      tone: 'neutral',
      icon: 'minus-circle',
    })
    expect(statusChipFor('in-progress', CAT_STATUS['in-progress'])).toMatchObject({
      tone: 'progress',
      label: CAT_STATUS['in-progress'],
    })
  })
})

describe('TextField', () => {
  it('wires an invalid field to its hint and error', () => {
    render(
      <TextField
        label="Titel"
        hint="Kurz und konkret."
        error="Bitte gib einen Titel ein."
        testId="title-field"
      />,
    )
    const input = screen.getByLabelText('Titel')

    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription('Kurz und konkret. Bitte gib einen Titel ein.')
  })
})

describe('TextArea', () => {
  it('forwards its ref to the textarea and renders the label-row element', () => {
    const ref = createRef<HTMLTextAreaElement>()
    render(
      <TextArea
        ref={ref}
        label="Beschreibung"
        labelEnd={<button type="button">Fett</button>}
        testId="body-field"
      />,
    )
    const textarea = screen.getByLabelText('Beschreibung')

    expect(ref.current).toBe(textarea)
    expect(textarea.tagName).toBe('TEXTAREA')
    expect(screen.getByRole('button', { name: 'Fett' })).toBeInTheDocument()
  })
})

describe('InlineNotice', () => {
  it('uses role=alert for errors and role=status otherwise, and accepts a class name', () => {
    const { rerender } = render(
      <InlineNotice tone="error" testId="notice" className="mt-3">
        Speichern fehlgeschlagen.
      </InlineNotice>,
    )
    expect(screen.getByTestId('notice')).toHaveAttribute('role', 'alert')
    expect(screen.getByTestId('notice')).toHaveClass('ds-notice', 'ds-notice--error', 'mt-3')

    rerender(
      <InlineNotice tone="info" testId="notice">
        Hinweis.
      </InlineNotice>,
    )
    expect(screen.getByTestId('notice')).toHaveAttribute('role', 'status')
  })
})

describe('Section', () => {
  it('renders a labelled landmark with head, body and foot', () => {
    render(
      <Section
        testId="form-section"
        title="Neue Idee"
        description="Kurz beschreiben."
        footer={<button type="button">Senden</button>}
      >
        <p>Inhalt</p>
      </Section>,
    )
    const section = screen.getByTestId('form-section')

    expect(section).toHaveAccessibleName('Neue Idee')
    expect(section).toHaveClass('ds-section', 'ds-section--none')
    expect(screen.getByText('Kurz beschreiben.')).toBeInTheDocument()
    expect(screen.getByText('Inhalt')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Senden' })).toBeInTheDocument()
  })
})

describe('Badge', () => {
  it('renders the tone as a class and data attribute, with dot and test id', () => {
    render(
      <Badge tone="warning" dot testId="role-badge">
        Moderator
      </Badge>,
    )
    const badge = screen.getByTestId('role-badge')
    expect(badge).toHaveClass('ds-badge', 'ds-badge--warning')
    expect(badge).toHaveAttribute('data-tone', 'warning')
    expect(badge).toHaveTextContent('Moderator')
  })
})

describe('Select', () => {
  it('passes the chosen value to onChange and keeps its label wired', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <Select label="Role" value="member" onChange={onChange} testId="member-role">
        <option value="member">Member</option>
        <option value="moderator">Moderator</option>
      </Select>,
    )
    await user.selectOptions(screen.getByLabelText('Role'), 'moderator')
    expect(onChange).toHaveBeenCalledWith('moderator')
    expect(screen.getByTestId('member-role')).toHaveClass('ds-select__control')
  })
})

describe('ConfirmDialog', () => {
  it('starts focus on cancel, cancels on Escape and confirms on the confirm button', async () => {
    const user = userEvent.setup()
    const onConfirm = vi.fn()
    const onCancel = vi.fn()
    render(
      <ConfirmDialog
        open
        title="Remove member?"
        confirmLabel="Remove"
        cancelLabel="Cancel"
        tone="danger"
        onConfirm={onConfirm}
        onCancel={onCancel}
        testId="member-remove-dialog"
      />,
    )
    expect(screen.getByRole('alertdialog', { name: 'Remove member?' })).toBeInTheDocument()
    expect(screen.getByTestId('member-remove-dialog-cancel')).toHaveFocus()
    await user.keyboard('{Escape}')
    expect(onCancel).toHaveBeenCalledTimes(1)
    await user.click(screen.getByTestId('member-remove-dialog-confirm'))
    expect(onConfirm).toHaveBeenCalledTimes(1)
  })
})

describe('Checkbox, StatCard, TextField prefix and Button block', () => {
  it('toggles a checkbox, renders a stat value, prefixes a slug field and makes a block button', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <>
        <Checkbox
          label="I accept the operator terms"
          checked={false}
          onChange={onChange}
          testId="ack"
        />
        <StatCard label="Boards" value={3} testId="stat-boards" />
        <TextField label="Slug" value="demo" onChange={() => undefined} prefix="/" testId="slug" />
        <Button testId="save-block" block>
          Save
        </Button>
      </>,
    )
    await user.click(screen.getByLabelText('I accept the operator terms'))
    expect(onChange).toHaveBeenCalledWith(true)
    expect(screen.getByTestId('stat-boards')).toHaveTextContent('3')
    expect(screen.getByTestId('slug').parentElement).toHaveTextContent('/')
    expect(screen.getByTestId('save-block')).toHaveClass('ds-button--block')
  })
})

describe('Table', () => {
  it('renders a labelled table with a stable wrapper id and numeric cells', () => {
    render(
      <Table caption="Appeals" testId="appeals-table">
        <TableHead>
          <TableRow>
            <TableHeaderCell>Content</TableHeaderCell>
            <TableHeaderCell numeric>Actions</TableHeaderCell>
          </TableRow>
        </TableHead>
        <TableBody>
          <TableRow testId="appeals-row-1">
            <TableCell>Post</TableCell>
            <TableCell numeric>3</TableCell>
          </TableRow>
        </TableBody>
      </Table>,
    )
    expect(screen.getByTestId('appeals-table')).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Appeals' })).toBeInTheDocument()
    expect(screen.getByTestId('appeals-row-1')).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Actions' })).toHaveClass(
      'ds-table__cell--numeric',
    )
    expect(screen.getByRole('cell', { name: '3' })).toHaveClass('ds-table__cell--numeric')
  })
})

describe('Button', () => {
  it('keeps its label and sets aria-busy while loading, and swallows clicks', async () => {
    const user = userEvent.setup()
    const onClick = vi.fn()
    render(
      <Button loading onClick={onClick} testId="save-button">
        Speichern
      </Button>,
    )
    const button = screen.getByTestId('save-button')

    expect(button).toHaveAttribute('aria-busy', 'true')
    expect(button).toHaveTextContent('Speichern')

    await user.click(button)
    expect(onClick).not.toHaveBeenCalled()
  })
})

describe('DesignReference', () => {
  it('shows the sample-data banner on every view and the sample board', () => {
    render(<DesignReference />)

    expect(screen.getAllByText('Beispieldaten — keine echten Nutzerstimmen')).toHaveLength(2)
    expect(screen.getAllByText('Offline-Modus für unterwegs').length).toBeGreaterThan(0)
  })
})
