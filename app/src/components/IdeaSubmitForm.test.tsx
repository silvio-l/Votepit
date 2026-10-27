import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ComponentProps } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CreateIdeaResponse, Tag, User } from '../lib/api'
import * as api from '../lib/api'
import { setFeatures } from '../lib/features'
import { setViewer } from '../lib/viewer'
import { IdeaSubmitForm } from './IdeaSubmitForm'

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  createIdea: vi.fn(),
  searchDuplicates: vi.fn(async () => ({ candidates: [] })),
  suggestTags: vi.fn(async () => ({ suggestions: [], availability: 'available' })),
}))

const member = { id: 1, is_admin: false } as unknown as User

function renderForm(props: Partial<ComponentProps<typeof IdeaSubmitForm>> = {}) {
  const onCreated = vi.fn()
  const view = render(
    <MemoryRouter>
      <IdeaSubmitForm
        boardSlug="demo"
        boardHref="/demo"
        phases={undefined}
        guestSiteKey={null}
        user={member}
        formAt="stamp"
        onCreated={onCreated}
        {...props}
      />
    </MemoryRouter>,
  )
  return { onCreated, container: view.container }
}

describe('IdeaSubmitForm', () => {
  beforeEach(() => {
    vi.mocked(api.createIdea).mockReset()
  })

  it('keeps the submit button disabled until a title is entered', async () => {
    const { container } = renderForm()
    const submit = screen.getByTestId('submit-idea-submit')
    expect(submit).toBeDisabled()
    await userEvent.type(container.querySelector('#submit-title') as HTMLElement, 'Dark mode')
    expect(submit).toBeEnabled()
  })

  it('submits the trimmed title and body and reports the created idea id', async () => {
    vi.mocked(api.createIdea).mockResolvedValue({
      ok: true,
      id: 42,
    } as CreateIdeaResponse)
    const { container, onCreated } = renderForm()
    await userEvent.type(container.querySelector('#submit-title') as HTMLElement, '  Dark mode  ')
    await userEvent.type(container.querySelector('#submit-body') as HTMLElement, ' Please ')
    await userEvent.click(screen.getByTestId('submit-idea-submit'))

    await waitFor(() => expect(onCreated).toHaveBeenCalledWith(42))
    expect(api.createIdea).toHaveBeenCalledWith(
      'demo',
      expect.objectContaining({ title: 'Dark mode', body: 'Please', _form_at: 'stamp' }),
    )
  })

  it('shows per-field errors from a 422 and does not report success', async () => {
    vi.mocked(api.createIdea).mockRejectedValue(
      Object.assign(new Error('invalid'), {
        payload: { fields: { title: 'Title is too short' } },
      }),
    )
    const { container, onCreated } = renderForm()
    await userEvent.type(container.querySelector('#submit-title') as HTMLElement, 'Hi')
    await userEvent.click(screen.getByTestId('submit-idea-submit'))

    expect(await screen.findByText('Title is too short')).toBeInTheDocument()
    expect(onCreated).not.toHaveBeenCalled()
  })

  it('calls onCancel from the cancel button when rendered inside a sheet', async () => {
    const onCancel = vi.fn()
    renderForm({ onCancel })
    await userEvent.click(screen.getByTestId('submit-cancel'))
    expect(onCancel).toHaveBeenCalledTimes(1)
  })
})

describe('IdeaSubmitForm tag picker (P-34)', () => {
  const tags: Tag[] = [
    { id: 11, name: 'Bug', color: '#ef4444' },
    { id: 12, name: 'UX', color: '#22c55e' },
    { id: 13, name: 'Docs', color: '#3b82f6' },
    { id: 14, name: 'Perf', color: '#f59e0b' },
    { id: 15, name: 'Mobile', color: '#8b5cf6' },
    { id: 16, name: 'Search', color: '#14b8a6' },
  ]

  beforeEach(() => {
    vi.mocked(api.createIdea).mockReset()
    vi.mocked(api.createIdea).mockResolvedValue({ ok: true, id: 7 } as CreateIdeaResponse)
  })

  it('renders no picker when the board has no tags', () => {
    renderForm({ tags: [] })
    expect(screen.queryByTestId('submit-tag-picker')).not.toBeInTheDocument()
  })

  it('renders one chip per board tag, unpressed, in a labelled group', () => {
    renderForm({ tags })
    const group = screen.getByTestId('submit-tag-picker')
    expect(group).toHaveAttribute('role', 'group')
    expect(group).toHaveAttribute('aria-labelledby')
    for (const tag of tags) {
      const chip = screen.getByTestId(`submit-tag-${tag.id}`)
      expect(chip).toHaveAttribute('aria-pressed', 'false')
      expect(chip).toHaveTextContent(tag.name)
    }
  })

  it('toggles a chip with a click and sends the selected ids on submit', async () => {
    const { container } = renderForm({ tags })
    await userEvent.click(screen.getByTestId('submit-tag-11'))
    await userEvent.click(screen.getByTestId('submit-tag-13'))
    expect(screen.getByTestId('submit-tag-11')).toHaveAttribute('aria-pressed', 'true')
    await userEvent.click(screen.getByTestId('submit-tag-11'))
    expect(screen.getByTestId('submit-tag-11')).toHaveAttribute('aria-pressed', 'false')

    await userEvent.type(container.querySelector('#submit-title') as HTMLElement, 'Dark mode')
    await userEvent.click(screen.getByTestId('submit-idea-submit'))

    await waitFor(() =>
      expect(api.createIdea).toHaveBeenCalledWith(
        'demo',
        expect.objectContaining({ tag_ids: [13] }),
      ),
    )
  })

  it('operates a chip with the keyboard (Enter toggles, aria-pressed follows)', async () => {
    renderForm({ tags })
    const chip = screen.getByTestId('submit-tag-12')
    chip.focus()
    await userEvent.keyboard('{Enter}')
    expect(chip).toHaveAttribute('aria-pressed', 'true')
    await userEvent.keyboard(' ')
    expect(chip).toHaveAttribute('aria-pressed', 'false')
  })

  it('allows at most five tags: the sixth chip is disabled until one is removed', async () => {
    renderForm({ tags })
    for (const id of [11, 12, 13, 14, 15]) {
      await userEvent.click(screen.getByTestId(`submit-tag-${id}`))
    }
    const sixth = screen.getByTestId('submit-tag-16')
    expect(sixth).toBeDisabled()
    expect(screen.getByTestId('submit-tag-picker-limit')).toBeInTheDocument()

    await userEvent.click(screen.getByTestId('submit-tag-15'))
    expect(sixth).toBeEnabled()
  })

  it('sends no tag_ids when nothing is picked', async () => {
    const { container } = renderForm({ tags })
    await userEvent.type(container.querySelector('#submit-title') as HTMLElement, 'Dark mode')
    await userEvent.click(screen.getByTestId('submit-idea-submit'))
    await waitFor(() => expect(api.createIdea).toHaveBeenCalled())
    expect(vi.mocked(api.createIdea).mock.calls[0]?.[1]).not.toHaveProperty('tag_ids')
  })
})

describe('IdeaSubmitForm AI tag suggestions (P-35)', () => {
  const tags: Tag[] = [
    { id: 11, name: 'Bug', color: '#ef4444' },
    { id: 12, name: 'UX', color: '#22c55e' },
    { id: 13, name: 'Docs', color: '#3b82f6' },
  ]

  beforeEach(() => {
    vi.mocked(api.createIdea).mockReset()
    vi.mocked(api.suggestTags).mockReset()
    vi.mocked(api.suggestTags).mockResolvedValue({ suggestions: [11], availability: 'available' })
  })

  it('preselects the suggested tags and marks them as suggested', async () => {
    const { container } = renderForm({ tags, tagSuggestion: 'available' })
    await userEvent.type(
      container.querySelector('#submit-title') as HTMLElement,
      'Dark mode please',
    )

    await waitFor(
      () => expect(screen.getByTestId('submit-tag-11')).toHaveAttribute('aria-pressed', 'true'),
      { timeout: 3000 },
    )
    expect(screen.getByTestId('submit-tag-11-suggested')).toHaveTextContent('Vorgeschlagen')
    expect(screen.getByTestId('submit-tag-12')).toHaveAttribute('aria-pressed', 'false')
    expect(api.suggestTags).toHaveBeenCalledWith(
      'demo',
      expect.objectContaining({ title: 'Dark mode please' }),
    )
  })

  it('never asks for suggestions when they are not available', async () => {
    const { container } = renderForm({ tags, tagSuggestion: 'disabled' })
    await userEvent.type(
      container.querySelector('#submit-title') as HTMLElement,
      'Dark mode please',
    )
    await new Promise((resolve) => setTimeout(resolve, 900))

    expect(api.suggestTags).not.toHaveBeenCalled()
    expect(screen.queryByTestId('submit-tag-11-suggested')).not.toBeInTheDocument()
  })

  it('stays usable without suggestions when the suggester fails', async () => {
    vi.mocked(api.suggestTags).mockRejectedValue(new Error('upstream down'))
    const { container } = renderForm({ tags, tagSuggestion: 'available' })
    await userEvent.type(
      container.querySelector('#submit-title') as HTMLElement,
      'Dark mode please',
    )
    await waitFor(() => expect(api.suggestTags).toHaveBeenCalled(), { timeout: 3000 })

    for (const tag of tags) {
      expect(screen.getByTestId(`submit-tag-${tag.id}`)).toHaveAttribute('aria-pressed', 'false')
    }
    expect(screen.getByTestId('submit-idea-submit')).toBeEnabled()
  })

  it('keeps a suggestion the submitter dropped dropped, even when it is suggested again', async () => {
    const { container } = renderForm({ tags, tagSuggestion: 'available' })
    await userEvent.type(
      container.querySelector('#submit-title') as HTMLElement,
      'Dark mode please',
    )
    await waitFor(
      () => expect(screen.getByTestId('submit-tag-11')).toHaveAttribute('aria-pressed', 'true'),
      { timeout: 3000 },
    )

    await userEvent.click(screen.getByTestId('submit-tag-11'))
    expect(screen.getByTestId('submit-tag-11')).toHaveAttribute('aria-pressed', 'false')

    await userEvent.type(container.querySelector('#submit-title') as HTMLElement, ' again')
    await waitFor(() => expect(vi.mocked(api.suggestTags).mock.calls.length).toBeGreaterThan(1), {
      timeout: 3000,
    })
    await new Promise((resolve) => setTimeout(resolve, 100))
    expect(screen.getByTestId('submit-tag-11')).toHaveAttribute('aria-pressed', 'false')
  })
})

describe('IdeaSubmitForm AI tag upgrade hint (P-35 plan gate)', () => {
  const tags: Tag[] = [{ id: 11, name: 'Bug', color: '#ef4444' }]
  const withRole = (role: 'owner' | 'admin' | 'moderator' | 'member'): User =>
    ({
      id: 7,
      is_admin: false,
      memberships: [{ account_slug: 'demo', role }],
    }) as unknown as User
  const HINT = 'KI-Vorschläge für Tags gehören zum Pro-Tarif.'

  afterEach(() => {
    setFeatures(undefined)
    setViewer(null)
  })

  it.each([
    'owner',
    'admin',
    'moderator',
  ] as const)('shows the Pro hint to the %s with the upgrade path', (role) => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    const user = withRole(role)
    setViewer(user)
    renderForm({ tags, tagSuggestion: 'upgrade_required', user })

    expect(screen.getByTestId('submit-tag-suggestions-upgrade')).toHaveTextContent(HINT)
    if (role === 'owner') {
      expect(screen.getByRole('link', { name: 'Tarif ansehen' })).toHaveAttribute(
        'href',
        '/admin/billing',
      )
    } else {
      expect(screen.queryByRole('link', { name: 'Tarif ansehen' })).toBeNull()
      expect(screen.getByText('Den Tarif kann nur der Konto-Owner ändern.')).toBeInTheDocument()
    }
  })

  it('stays silent for ordinary members', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    const user = withRole('member')
    setViewer(user)
    renderForm({ tags, tagSuggestion: 'upgrade_required', user })

    expect(screen.queryByTestId('submit-tag-suggestions-upgrade')).not.toBeInTheDocument()
  })

  it('stays silent for guests', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    renderForm({ tags, tagSuggestion: 'upgrade_required', user: null })

    expect(screen.queryByTestId('submit-tag-suggestions-upgrade')).not.toBeInTheDocument()
  })

  it('stays silent when suggestions are merely switched off', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    const user = withRole('owner')
    setViewer(user)
    renderForm({ tags, tagSuggestion: 'disabled', user })

    expect(screen.queryByTestId('submit-tag-suggestions-upgrade')).not.toBeInTheDocument()
  })

  it('stays silent when the board has no tags to suggest', () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    const user = withRole('owner')
    setViewer(user)
    renderForm({ tags: [], tagSuggestion: 'upgrade_required', user })

    expect(screen.queryByTestId('submit-tag-suggestions-upgrade')).not.toBeInTheDocument()
  })
})
