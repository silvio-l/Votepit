import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SortTabs } from '../components/SortTabs'

describe('SortTabs', () => {
  it('renders all 3 tabs', () => {
    render(<SortTabs value="top" onChange={() => {}} />)
    expect(screen.getByRole('tab', { name: 'Top' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Neu' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Umstritten' })).toBeInTheDocument()
  })

  it('calls onChange with "newest" when Neu tab is clicked', async () => {
    const onChange = vi.fn()
    render(<SortTabs value="top" onChange={onChange} />)
    await userEvent.click(screen.getByRole('tab', { name: 'Neu' }))
    expect(onChange).toHaveBeenCalledWith('newest')
  })

  it('calls onChange with "controversial" when Umstritten tab is clicked', async () => {
    const onChange = vi.fn()
    render(<SortTabs value="top" onChange={onChange} />)
    await userEvent.click(screen.getByRole('tab', { name: 'Umstritten' }))
    expect(onChange).toHaveBeenCalledWith('controversial')
  })

  it('active tab has aria-pressed="true"', () => {
    render(<SortTabs value="newest" onChange={() => {}} />)
    const neuTab = screen.getByRole('tab', { name: 'Neu' })
    expect(neuTab).toHaveAttribute('aria-pressed', 'true')
  })
})
