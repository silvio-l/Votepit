import type { VoteReasonChoice } from './api/ideas'

/** Plain-text limit of the optional note (server: VoteReason::NOTE_MAX_LENGTH, code points). */
export const VOTE_REASON_NOTE_MAX = 200

/** Length in Unicode code points, the same count the server uses (mb_strlen). */
export function noteLength(note: string): number {
  return [...note].length
}

/** Trimmed note as sent to the server; empty stays empty (the server stores NULL). */
export function normalizeNoteDraft(note: string): string {
  return note.trim()
}

export type VoteReasonDraftError = 'reasonRequired' | 'noteTooLong' | 'noteInvalid'

/** Same character class as the server check: C0 controls except tab/newline/CR, plus DEL. */
function hasControlCharacter(text: string): boolean {
  for (const char of text) {
    const code = char.codePointAt(0) ?? 0
    if (
      code <= 0x08 ||
      code === 0x0b ||
      code === 0x0c ||
      (code >= 0x0e && code <= 0x1f) ||
      code === 0x7f
    ) {
      return true
    }
  }
  return false
}

/**
 * Client-side checks of a reason draft. The server repeats them (fail-secure);
 * this only keeps the save button honest. Control characters are refused
 * exactly like the server does.
 */
export function voteReasonDraftError(
  reason: VoteReasonChoice | null,
  note: string,
): VoteReasonDraftError | null {
  if (reason === null) return 'reasonRequired'
  if (hasControlCharacter(note)) return 'noteInvalid'
  if (noteLength(normalizeNoteDraft(note)) > VOTE_REASON_NOTE_MAX) return 'noteTooLong'
  return null
}

/** Translator as the app's `useT(namespace)` returns it. */
type Translate = (key: string) => string

/** Labels for the DS follow-up panel, from the `voteReasons` dictionary. */
export function buildVoteReasonPanelLabels(t: Translate) {
  return {
    question: t('question'),
    options: {
      not_needed: t('optNotNeeded'),
      too_expensive: t('optTooExpensive'),
      worsens_workflow: t('optWorsensWorkflow'),
      other: t('optOther'),
    },
    noteLabel: t('noteLabel'),
    // Language-neutral "count/max" format, so it needs no dictionary entry.
    counter: (count: number, max: number) => `${count}/${max}`,
    save: t('save'),
    skip: t('skip'),
    privacy: t('privacy'),
    saved: t('saved'),
    nearLimit: t('nearLimit'),
    limitReached: t('limitReached'),
  }
}

/** Labels for the operator aggregate view. */
export function buildVoteReasonAggregateLabels(t: Translate) {
  return {
    title: t('operatorTitle'),
    empty: t('operatorEmpty'),
    downCount: (count: number) => t('operatorDownCount').replace('{count}', String(count)),
    textsTitle: t('operatorTextsTitle'),
    noNames: t('operatorNoNames'),
    options: buildVoteReasonPanelLabels(t).options,
  }
}
