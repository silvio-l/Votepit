import { BoardReference } from './BoardReference'
import { StatesCatalogue } from './StatesCatalogue'

/** Dev-only design reference (E-01/E-02). Not in the production build; see design-reference.html. */
export function DesignReference() {
  return (
    <main className="ref-page">
      <header className="ref-topbar">
        <h1 className="ref-topbar__title">VotePit Designreferenz</h1>
        <p className="ref-topbar__note">Entwicklungsansicht · nicht produktiv · Beispieldaten</p>
      </header>
      <BoardReference />
      <StatesCatalogue />
    </main>
  )
}
