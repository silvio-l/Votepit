import { describe, expect, it } from 'vitest'
import { noteLength, VOTE_REASON_NOTE_MAX, voteReasonDraftError } from '../voteReason'

describe('down-vote reason draft (P-03)', () => {
  it('requires one of the fixed reasons before saving', () => {
    expect(voteReasonDraftError(null, '')).toBe('reasonRequired')
    expect(voteReasonDraftError(null, 'text without reason')).toBe('reasonRequired')
  })

  it('accepts a reason with an empty note (the note is optional)', () => {
    expect(voteReasonDraftError('other', '')).toBeNull()
    expect(voteReasonDraftError('not_needed', '   ')).toBeNull()
  })

  it('counts the note in code points, not UTF-16 units or bytes', () => {
    expect(noteLength('ä'.repeat(VOTE_REASON_NOTE_MAX))).toBe(VOTE_REASON_NOTE_MAX)
    expect(noteLength('😀'.repeat(3))).toBe(3)
    expect(voteReasonDraftError('other', 'ä'.repeat(VOTE_REASON_NOTE_MAX))).toBeNull()
    expect(voteReasonDraftError('other', 'ä'.repeat(VOTE_REASON_NOTE_MAX + 1))).toBe('noteTooLong')
  })

  it('does not count surrounding whitespace against the limit', () => {
    expect(voteReasonDraftError('other', `  ${'a'.repeat(VOTE_REASON_NOTE_MAX)}  `)).toBeNull()
  })

  it('refuses control characters, as the server does', () => {
    expect(voteReasonDraftError('other', 'bad\u0007bell')).toBe('noteInvalid')
    expect(voteReasonDraftError('other', 'line\nbreak is fine')).toBeNull()
  })
})
