import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'

/** Minimal component defined inline — no production code imported. */
function Greeting({ name }: { name: string }) {
  return <p>Hello, {name}!</p>
}

describe('smoke', () => {
  it('renders visible text', () => {
    render(<Greeting name="Votepit" />)
    expect(screen.getByText('Hello, Votepit!')).toBeInTheDocument()
  })
})
