import { describe, expect, it } from 'vitest'
import type { BoardStatusInfo, IdeaStatus } from './api'
import { findStatus, statusAllows, statusLabel, toComponentStatus } from './boardStatuses'

const DEFAULTS: Record<IdeaStatus, string> = {
  open: 'Open',
  planned: 'Planned',
  in_progress: 'In progress',
  done: 'Done',
  declined: 'Declined',
  archived: 'Archived',
}

function status(overrides: Partial<BoardStatusInfo> & { key: string }): BoardStatusInfo {
  const category = (overrides.category ?? overrides.key) as IdeaStatus
  return {
    category,
    category_name: category,
    is_system: true,
    label_de: null,
    label_en: null,
    is_hidden: false,
    allow_votes: true,
    allow_comments: true,
    idea_count: null,
    ...overrides,
  }
}

describe('boardStatuses', () => {
  it('falls back to the translated default when the payload has no registry', () => {
    expect(statusLabel(undefined, undefined, 'in_progress', 'en', DEFAULTS)).toBe('In progress')
    expect(statusAllows(undefined, 'done', 'votes')).toBe(true)
  })

  it('uses the owner label in the viewer language, otherwise the default translation', () => {
    const statuses = [status({ key: 'planned', label_de: 'Zur Abstimmung' })]
    expect(statusLabel(statuses, 'planned', 'planned', 'de', DEFAULTS)).toBe('Zur Abstimmung')
    expect(statusLabel(statuses, 'planned', 'planned', 'en', DEFAULTS)).toBe('Planned')
    expect(statusLabel(statuses, 'planned', 'planned', 'fr', DEFAULTS)).toBe('Planned')
  })

  it('labels a custom status with any owner label, never the category default', () => {
    const statuses = [
      status({ key: 's1', category: 'done', is_system: false, label_de: 'Beschlossen' }),
    ]
    expect(statusLabel(statuses, 's1', 'done', 'en', DEFAULTS)).toBe('Beschlossen')
    expect(statusLabel(statuses, 's1', 'done', 'de', DEFAULTS)).toBe('Beschlossen')
  })

  it('keeps colour on the category and reports closed phases', () => {
    const statuses = [
      status({ key: 's2', category: 'in_progress', is_system: false, allow_votes: false }),
    ]
    expect(toComponentStatus(findStatus(statuses, 's2')?.category ?? 'open')).toBe('in-progress')
    expect(statusAllows(statuses, 's2', 'votes')).toBe(false)
    expect(statusAllows(statuses, 's2', 'comments')).toBe(true)
    expect(toComponentStatus('bogus')).toBe('open')
  })
})
