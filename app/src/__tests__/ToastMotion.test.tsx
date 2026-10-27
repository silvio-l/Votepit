/**
 * Toast motion: the exit plays on the DS fast token before onClose unmounts the
 * toast, and prefers-reduced-motion closes it at once with no exit.
 */

import { act, fireEvent, render, screen } from '@testing-library/react'
import { Toast } from '@votepit/ui/ds'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// Mirrors --vpds-motion-fast (120ms, semantic.motion.fast in tokens.json).
const EXIT_MS = 120

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

function renderToast(onClose: () => void, duration = 5000) {
  return render(
    <Toast
      message="Vote failed"
      type="error"
      onClose={onClose}
      duration={duration}
      closeAriaLabel="Close"
      testId="test-toast"
    />,
  )
}

describe('Toast motion', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('under prefers-reduced-motion closes at once on close and on auto-dismiss, with no exit state', () => {
    setReducedMotion(true)
    const onClose = vi.fn()
    const { container } = renderToast(onClose)

    fireEvent.click(screen.getByTestId('test-toast-close'))
    expect(onClose).toHaveBeenCalledTimes(1)
    expect(container.querySelector('.ds-toast--leaving')).toBeNull()

    onClose.mockClear()
    const auto = vi.fn()
    renderToast(auto, 1000)
    act(() => {
      vi.advanceTimersByTime(1000)
    })
    expect(auto).toHaveBeenCalledTimes(1)
    expect(onClose).not.toHaveBeenCalled()
  })

  it('with motion on, close plays the exit first and calls onClose after the fast token', () => {
    setReducedMotion(false)
    const onClose = vi.fn()
    const { container } = renderToast(onClose)

    fireEvent.click(screen.getByTestId('test-toast-close'))
    expect(container.querySelector('.ds-toast--leaving')).not.toBeNull()
    expect(onClose).not.toHaveBeenCalled()

    act(() => {
      vi.advanceTimersByTime(EXIT_MS - 1)
    })
    expect(onClose).not.toHaveBeenCalled()

    act(() => {
      vi.advanceTimersByTime(1)
    })
    expect(onClose).toHaveBeenCalledTimes(1)
  })
})
