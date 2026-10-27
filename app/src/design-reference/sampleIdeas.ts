import type { IdeaStatus } from '@votepit/ui/ds'

// Sample data for the dev reference only. The concept's standard example board;
// every view that uses it carries the "Beispieldaten" banner.

interface SampleDecision {
  rationale: string
  nextStep: string
}

export interface SampleIdea {
  id: string
  title: string
  problem: string
  status: IdeaStatus
  /** Votes without the viewer's own vote; the viewer's vote is added on top. */
  up: number
  down: number
  comments: number
  updated: string
  decision?: SampleDecision
}

export const SAMPLE_IDEAS: readonly SampleIdea[] = [
  {
    id: 'offline-mode',
    title: 'Offline-Modus für unterwegs',
    problem: 'Unterwegs ohne Netz lassen sich Ideen weder lesen noch bewerten.',
    status: 'in-progress',
    up: 24,
    down: 6,
    comments: 7,
    updated: 'vor 2 Tagen',
    decision: {
      rationale:
        'Der Bedarf kommt aus mehreren Rückmeldungen von Reisenden. Die Umsetzung ist begonnen, aber noch nicht terminiert.',
      nextStep: 'Technische Prüfung der Synchronisation, danach Rückmeldung im Ideenverlauf.',
    },
  },
  {
    id: 'shortcuts',
    title: 'Tastenkürzel selbst festlegen',
    problem: 'Wiederkehrende Aktionen sollen mit eigenen Tastenkürzeln erreichbar sein.',
    status: 'in-review',
    up: 18,
    down: 12,
    comments: 14,
    updated: 'vor 5 Tagen',
  },
  {
    id: 'markdown-export',
    title: 'Export als Markdown',
    problem: 'Ergebnisse sollen als Markdown-Datei für Dokumentation oder Wiki vorliegen.',
    status: 'planned',
    up: 21,
    down: 3,
    comments: 4,
    updated: 'vor 1 Woche',
    decision: {
      rationale: 'Geplant für die nächste Ausbaustufe der Exportfunktionen.',
      nextStep: 'Format festlegen; eine Liefergarantie gibt es noch nicht.',
    },
  },
]
