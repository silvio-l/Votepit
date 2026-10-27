import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useRef, useState } from 'react'
import { describe, expect, it } from 'vitest'
import { MarkdownToolbar } from './MarkdownToolbar'

function Harness({ initial = '' }: { initial?: string }) {
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const [value, setValue] = useState(initial)
  return (
    <div>
      <MarkdownToolbar textareaRef={textareaRef} value={value} onChange={setValue} />
      <textarea
        ref={textareaRef}
        aria-label="body"
        value={value}
        onChange={(e) => setValue(e.target.value)}
      />
    </div>
  )
}

describe('MarkdownToolbar', () => {
  it('wraps the current selection with ** on bold click', async () => {
    render(<Harness initial="hello world" />)
    const textarea = screen.getByLabelText('body') as HTMLTextAreaElement
    textarea.setSelectionRange(0, 5) // "hello"

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Fett' }))

    expect(textarea.value).toBe('**hello** world')
  })

  it('wraps the current selection with * on italic click', async () => {
    render(<Harness initial="hello world" />)
    const textarea = screen.getByLabelText('body') as HTMLTextAreaElement
    textarea.setSelectionRange(6, 11) // "world"

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Kursiv' }))

    expect(textarea.value).toBe('hello *world*')
  })

  it('wraps the current selection with backticks on code click', async () => {
    render(<Harness initial="hello world" />)
    const textarea = screen.getByLabelText('body') as HTMLTextAreaElement
    textarea.setSelectionRange(0, 11)

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Code' }))

    expect(textarea.value).toBe('`hello world`')
  })

  it('inserts an empty marker pair at the cursor when nothing is selected', async () => {
    render(<Harness initial="hello" />)
    const textarea = screen.getByLabelText('body') as HTMLTextAreaElement
    textarea.setSelectionRange(5, 5)

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Fett' }))

    expect(textarea.value).toBe('hello****')
  })

  it('toolbar buttons are type="button", never submitting a surrounding form', () => {
    render(<Harness initial="x" />)
    for (const name of ['Fett', 'Kursiv', 'Code']) {
      expect(screen.getByRole('button', { name })).toHaveAttribute('type', 'button')
    }
  })
})
