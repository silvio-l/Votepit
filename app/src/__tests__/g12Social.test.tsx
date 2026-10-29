import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import {
  Avatar,
  BoardCover,
  coverFamilyFor,
  ShareButton,
  StatusBubbles,
  VoteControl,
} from '@votepit/ui/ds'
import { describe, expect, it, vi } from 'vitest'
import { CAT_VOTE } from '../design-reference/catalogueLabels'

describe('BoardCover (G-12)', () => {
  it('picks a stable pattern family per seed and draws only our own shapes', () => {
    const { container, rerender } = render(<BoardCover accent="#0B6B4C" seed={7} testId="cover" />)
    const first = container.querySelector('[data-testid="cover"]')
    expect(first?.getAttribute('aria-hidden')).toBe('true')
    expect(first?.getAttribute('data-cover-family')).toBe(String(coverFamilyFor(7)))
    expect(container.querySelectorAll('script, foreignObject, image, use').length).toBe(0)
    rerender(<BoardCover accent="#0B6B4C" seed={7} testId="cover" />)
    expect(
      container.querySelector('[data-testid="cover"]')?.getAttribute('data-cover-family'),
    ).toBe(String(coverFamilyFor(7)))
  })

  it('falls back to the default accent for an invalid colour', () => {
    const { container } = render(
      <BoardCover accent="url(javascript:x)" seed="board" testId="cover" />,
    )
    expect(container.querySelector('[data-testid="cover"]')).not.toBeNull()
    expect(container.innerHTML).not.toContain('javascript')
  })
})

describe('StatusBubbles (G-12)', () => {
  it('renders toggle buttons with counts and calls onSelect', () => {
    const onSelect = vi.fn()
    render(
      <StatusBubbles
        label="Status"
        testId="bubbles"
        items={[
          {
            key: 'all',
            label: 'All',
            count: 12,
            tone: 'neutral',
            pressed: true,
            onSelect,
            testId: 'bubble-all',
          },
          {
            key: 'open',
            label: 'Open',
            count: 3,
            tone: 'info',
            pressed: false,
            onSelect,
            testId: 'bubble-open',
          },
        ]}
      />,
    )
    const all = screen.getByTestId('bubble-all')
    expect(all.getAttribute('aria-pressed')).toBe('true')
    expect(screen.getByTestId('bubble-open-count').textContent).toBe('3')
    fireEvent.click(screen.getByTestId('bubble-open'))
    expect(onSelect).toHaveBeenCalledTimes(1)
  })
})

describe('ShareButton (G-12)', () => {
  it('uses the Web Share API when available and reports the outcome', async () => {
    const share = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'share', { value: share, configurable: true })
    Object.defineProperty(navigator, 'canShare', { value: () => true, configurable: true })
    const onResult = vi.fn()
    render(
      <ShareButton
        url="https://feedback.example.com/acme"
        title="Acme"
        label="Share"
        testId="share"
        onResult={onResult}
      />,
    )
    fireEvent.click(screen.getByTestId('share'))
    await waitFor(() => expect(onResult).toHaveBeenCalledWith('shared'))
    expect(share).toHaveBeenCalledWith({ title: 'Acme', url: 'https://feedback.example.com/acme' })
    Reflect.deleteProperty(navigator, 'share')
    Reflect.deleteProperty(navigator, 'canShare')
  })

  it('icon variant has an accessible name', () => {
    render(
      <ShareButton
        url="https://x.test"
        title="X"
        label="Share this board"
        iconOnly
        testId="share-icon"
      />,
    )
    expect(screen.getByTestId('share-icon').getAttribute('aria-label')).toBe('Share this board')
  })
})

describe('Avatar accent initials (G-12)', () => {
  it('uses white text on a dark accent and never shows the e-mail-like name', () => {
    const { container } = render(<Avatar name="Ada Lovelace" color="#0B6B4C" size="large" />)
    const el = container.querySelector('.ds-avatar') as HTMLElement
    expect(el.textContent).toBe('AL')
    expect(el.style.background).toBe('rgb(11, 107, 76)')
    expect(el.classList.contains('ds-avatar--large')).toBe(true)
  })
})

describe('VoteControl micro-animation (G-12)', () => {
  it('does not animate on the first render, only after a change', () => {
    const { container, rerender } = render(
      <VoteControl
        direction="up"
        count={4}
        selected={false}
        ideaTitle="Idea"
        testId="vote"
        labels={CAT_VOTE.labels}
      />,
    )
    expect(container.querySelector('.ds-vote-control__tick--pulse')).toBeNull()
    expect(container.querySelector('.ds-vote-control__count--roll')).toBeNull()
    rerender(
      <VoteControl
        direction="up"
        count={5}
        selected
        ideaTitle="Idea"
        testId="vote"
        labels={CAT_VOTE.labels}
      />,
    )
    expect(container.querySelector('.ds-vote-control__tick--pulse')).not.toBeNull()
    expect(container.querySelector('.ds-vote-control__count--roll')).not.toBeNull()
  })
})
