import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SortTabs } from '@votepit/ui'
import { describe, expect, it, vi } from 'vitest'

const labels = { top: 'Top', newest: 'Newest', controversial: 'Controversial' }
const ariaLabel = 'Sort ideas'

describe('SortTabs', () => {
  it('renders all 3 tabs', () => {
    render(<SortTabs value="top" onChange={() => {}} ariaLabel={ariaLabel} labels={labels} />)
    expect(screen.getByRole('tab', { name: 'Top' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Newest' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Controversial' })).toBeInTheDocument()
  })

  it('calls onChange with "newest" when Newest tab is clicked', async () => {
    const onChange = vi.fn()
    render(<SortTabs value="top" onChange={onChange} ariaLabel={ariaLabel} labels={labels} />)
    await userEvent.click(screen.getByRole('tab', { name: 'Newest' }))
    expect(onChange).toHaveBeenCalledWith('newest')
  })

  it('calls onChange with "controversial" when Controversial tab is clicked', async () => {
    const onChange = vi.fn()
    render(<SortTabs value="top" onChange={onChange} ariaLabel={ariaLabel} labels={labels} />)
    await userEvent.click(screen.getByRole('tab', { name: 'Controversial' }))
    expect(onChange).toHaveBeenCalledWith('controversial')
  })

  it('active tab has aria-selected="true"', () => {
    render(<SortTabs value="newest" onChange={() => {}} ariaLabel={ariaLabel} labels={labels} />)
    const newestTab = screen.getByRole('tab', { name: 'Newest' })
    expect(newestTab).toHaveAttribute('aria-selected', 'true')
  })
})
