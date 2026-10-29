// Dev catalogue only (not in the production build): fixed German texts for the
// design-system states, so every component gets its required labels without
// an i18n provider.
import type { IdeaStatus, VoteControlLabels, VoteSummaryLabels } from '@votepit/ui/ds'

export const CAT_VOTE = {
  labels: {
    up: { verb: 'Dafür stimmen', label: 'Dafür' },
    down: { verb: 'Dagegen stimmen', label: 'Dagegen' },
    votesSingular: 'Stimme',
    votesPlural: 'Stimmen',
    error: 'Stimme konnte nicht gespeichert werden. Der vorherige Stand bleibt erhalten.',
    readonly: 'Abstimmen ist hier nicht möglich.',
  } satisfies VoteControlLabels,
  locale: 'de-DE',
}

export const CAT_SUMMARY = {
  labels: {
    up: 'Dafür',
    down: 'Dagegen',
    net: 'Netto',
    approval: 'Zustimmung',
    empty: 'Noch keine Stimmen',
    explanation: 'Zustimmung unter den Dafür-/Dagegen-Stimmen',
    fewVotes: 'Noch wenige Stimmen – das Ergebnis kann sich stark ändern',
  } satisfies VoteSummaryLabels,
  locale: 'de-DE',
}

export const CAT_STATUS: Record<IdeaStatus, string> = {
  open: 'Offen',
  'in-review': 'In Prüfung',
  planned: 'Geplant',
  'in-progress': 'In Arbeit',
  done: 'Umgesetzt',
  declined: 'Nicht umgesetzt',
  'not-implemented': 'Nicht umgesetzt',
  archived: 'Archiviert',
}

export const CAT_CARD = {
  voteLabels: CAT_VOTE.labels,
  summaryLabels: CAT_SUMMARY.labels,
  locale: 'de-DE',
  commentLabel: (count: number) => (count === 1 ? '1 Kommentar' : `${count} Kommentare`),
  pendingLabel: 'Wird gespeichert …',
  unreadLabel: 'Neu',
  statusLabel: 'Offen',
}

export const CAT_DECISION = {
  nextStepLabel: 'Nächster Schritt:',
  editLabel: 'Entscheidung bearbeiten',
  headings: { planned: 'Geplant', declined: 'Nicht umgesetzt', done: 'Umgesetzt' },
}

export const CAT_EMPTY_TITLE: Record<'no-ideas' | 'no-results' | 'no-access', string> = {
  'no-ideas': 'Noch keine Ideen',
  'no-results': 'Keine Treffer',
  'no-access': 'Kein Zugriff',
}
