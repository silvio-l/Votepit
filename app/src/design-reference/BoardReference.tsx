import {
  BrandMark,
  Button,
  DecisionPanel,
  EmptyState,
  IdeaCard,
  type IdeaStatus,
  InlineNotice,
  SearchField,
  StatusChip,
  statusChipFor,
  VoteControl,
  type VoteDirection,
  VoteSummary,
} from '@votepit/ui/ds'
import { useState } from 'react'
import {
  CAT_CARD,
  CAT_DECISION,
  CAT_EMPTY_TITLE,
  CAT_STATUS,
  CAT_SUMMARY,
  CAT_VOTE,
} from './catalogueLabels'
import { SAMPLE_IDEAS, type SampleIdea } from './sampleIdeas'

/** Simulated round trip for sample votes. Not a product rule; there is no backend here. */
const SAMPLE_VOTE_DELAY_MS = 400

type FilterValue = 'all' | IdeaStatus
type SortValue = 'newest' | 'title'

const FILTERS: readonly FilterValue[] = [
  'all',
  'open',
  'in-review',
  'planned',
  'in-progress',
  'done',
]

interface ViewerVote {
  up: boolean
  down: boolean
}

const NO_VOTE: ViewerVote = { up: false, down: false }

/** Sample behaviour: a second click withdraws the vote, the other direction replaces it. */
function nextViewerVote(current: ViewerVote, direction: VoteDirection): ViewerVote {
  if (direction === 'up') return { up: !current.up, down: false }
  return { up: false, down: !current.down }
}

function countsFor(idea: SampleIdea, vote: ViewerVote): { up: number; down: number } {
  return { up: idea.up + (vote.up ? 1 : 0), down: idea.down + (vote.down ? 1 : 0) }
}

function wait(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/** Board reference: header, search/filter/sort, idea list and detail panel (desktop and mobile layout). */
export function BoardReference() {
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState<FilterValue>('all')
  const [sort, setSort] = useState<SortValue>('newest')
  const [selectedId, setSelectedId] = useState<string>(SAMPLE_IDEAS[0]?.id ?? '')
  const [votes, setVotes] = useState<Record<string, ViewerVote>>({})
  const [following, setFollowing] = useState(false)

  const normalizedQuery = query.trim().toLocaleLowerCase('de-DE')
  const visible = SAMPLE_IDEAS.filter((idea) => {
    if (filter !== 'all' && idea.status !== filter) return false
    return normalizedQuery === '' || idea.title.toLocaleLowerCase('de-DE').includes(normalizedQuery)
  }).toSorted((a, b) => (sort === 'title' ? a.title.localeCompare(b.title, 'de') : 0))

  const selected = SAMPLE_IDEAS.find((idea) => idea.id === selectedId) ?? SAMPLE_IDEAS[0]
  const selectedVote = selected ? (votes[selected.id] ?? NO_VOTE) : NO_VOTE
  const selectedCounts = selected ? countsFor(selected, selectedVote) : { up: 0, down: 0 }
  const selectedChip = statusChipFor(
    selected?.status ?? 'open',
    CAT_STATUS[selected?.status ?? 'open'],
  )

  const castVote = async (ideaId: string, direction: VoteDirection) => {
    await wait(SAMPLE_VOTE_DELAY_MS)
    setVotes((previous) => {
      const current = previous[ideaId] ?? NO_VOTE
      return { ...previous, [ideaId]: nextViewerVote(current, direction) }
    })
  }

  return (
    <section
      className="ref-section"
      aria-labelledby="board-reference-title"
      data-testid="board-reference"
    >
      <InlineNotice
        tone="warning"
        title="Beispieldaten — keine echten Nutzerstimmen"
        testId="board-sample-banner"
      >
        Ideen, Zahlen und Stimmen auf dieser Ansicht sind frei erfunden und dienen nur der
        Gestaltung.
      </InlineNotice>

      <header className="ds-board-header" data-testid="board-header">
        <BrandMark edition="cloud" onInk />
        <h2 id="board-reference-title" className="ds-board-header__title">
          Produktfeedback
        </h2>
        <p className="ds-board-header__lead">
          Beispielboard für Produktideen einer App. Demo-Inhalte, keine echten Ideen.
        </p>
      </header>

      <div className="ref-board-toolbar">
        <SearchField
          label="Ideen durchsuchen"
          value={query}
          placeholder="Titel suchen"
          onChange={(event) => setQuery(event.target.value)}
          testId="board-search"
        />
        <div className="ref-group" role="group" aria-label="Status filtern">
          {FILTERS.map((value) => {
            const label = value === 'all' ? 'Alle' : statusChipFor(value, CAT_STATUS[value]).label
            return (
              <Button
                key={value}
                variant="secondary"
                aria-pressed={filter === value}
                testId={`board-filter-${value}`}
                onClick={() => setFilter(value)}
              >
                {label}
              </Button>
            )
          })}
        </div>
        <div className="ref-group" role="group" aria-label="Sortierung">
          <Button
            variant="secondary"
            aria-pressed={sort === 'newest'}
            testId="board-sort-newest"
            onClick={() => setSort('newest')}
          >
            Neueste
          </Button>
          <Button
            variant="secondary"
            aria-pressed={sort === 'title'}
            testId="board-sort-title"
            onClick={() => setSort('title')}
          >
            Titel A–Z
          </Button>
        </div>
      </div>

      <div className="ref-board">
        <div className="ref-stack">
          {visible.length === 0 ? (
            <EmptyState
              kind="no-results"
              title={CAT_EMPTY_TITLE['no-results']}
              description="Für diese Suche und diesen Filter gibt es keine Ideen."
              action={
                <Button
                  variant="secondary"
                  testId="board-reset-filters"
                  onClick={() => {
                    setQuery('')
                    setFilter('all')
                  }}
                >
                  Filter zurücksetzen
                </Button>
              }
            />
          ) : (
            <ul className="ref-list" aria-label="Ideen">
              {visible.map((idea) => {
                const vote = votes[idea.id] ?? NO_VOTE
                const counts = countsFor(idea, vote)
                return (
                  <li key={idea.id}>
                    <IdeaCard
                      {...CAT_CARD}
                      testId={`board-idea-${idea.id}`}
                      title={idea.title}
                      problem={idea.problem}
                      status={idea.status}
                      up={counts.up}
                      down={counts.down}
                      commentCount={idea.comments}
                      updatedLabel={idea.updated}
                      href={`#${idea.id}`}
                      onOpen={() => setSelectedId(idea.id)}
                      state={idea.id === selectedId ? 'selected' : 'default'}
                      votedUp={vote.up}
                      votedDown={vote.down}
                      onVote={(direction) => castVote(idea.id, direction)}
                    />
                  </li>
                )
              })}
            </ul>
          )}
        </div>

        {selected && (
          <article
            className="ref-detail"
            aria-labelledby="board-detail-title"
            data-testid="board-detail"
          >
            <h2 id="board-detail-title" className="ref-detail__title">
              {selected.title}
            </h2>
            <div className="ref-row">
              <StatusChip tone={selectedChip.tone} icon={selectedChip.icon}>
                {selectedChip.label}
              </StatusChip>
            </div>
            <p className="ref-detail__problem">{selected.problem}</p>

            <VoteSummary
              {...CAT_SUMMARY}
              up={selectedCounts.up}
              down={selectedCounts.down}
              testId="board-detail-summary"
            />

            <div className="ref-row" data-testid="board-detail-votes">
              <VoteControl
                {...CAT_VOTE}
                direction="up"
                count={selectedCounts.up}
                selected={selectedVote.up}
                ideaTitle={selected.title}
                onVote={() => castVote(selected.id, 'up')}
                testId="board-detail-vote-up"
              />
              <VoteControl
                {...CAT_VOTE}
                direction="down"
                count={selectedCounts.down}
                selected={selectedVote.down}
                ideaTitle={selected.title}
                onVote={() => castVote(selected.id, 'down')}
                testId="board-detail-vote-down"
              />
            </div>

            {selected.decision ? (
              <DecisionPanel
                {...CAT_DECISION}
                outcome="planned"
                rationale={selected.decision.rationale}
                nextStep={selected.decision.nextStep}
                author="Produktteam (Beispiel)"
                date="2026-10-14"
                canEdit={false}
                testId="board-detail-decision"
              />
            ) : (
              <p className="ref-placeholder">Noch keine Entscheidung. Die Idee wird geprüft.</p>
            )}

            <section aria-labelledby="board-detail-discussion">
              <h3 id="board-detail-discussion" className="ref-detail__section-title">
                Diskussion
              </h3>
              <p className="ref-placeholder">
                Kommentare erscheinen hier. Dieser Bereich ist nur ein Platzhalter (
                {selected.comments} Kommentare im Beispiel).
              </p>
            </section>

            <div className="ref-row">
              <Button
                variant="secondary"
                icon="bell"
                aria-pressed={following}
                testId="board-detail-follow"
                onClick={() => setFollowing((value) => !value)}
              >
                Updates verfolgen
              </Button>
            </div>
            {following && (
              <p className="ref-placeholder" role="status">
                Beispiel: Die Schaltfläche zeigt den Zustand, es wird noch keine Benachrichtigung
                versendet.
              </p>
            )}
          </article>
        )}
      </div>
    </section>
  )
}
