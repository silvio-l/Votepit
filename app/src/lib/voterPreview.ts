/**
 * Module-level "sticky" store for the "view as voter" preview mode, mirroring
 * accountContext.ts's pattern: client-side navigation between board-scoped
 * pages (BoardPage -> IdeaDetailPage -> RoadmapPage etc.) does not reload the
 * page, so a plain module variable survives across it without every internal
 * link needing to carry `?view=voter`. Only entry from outside the SPA (a
 * fresh load, or the "View board" link from AdminPage) needs the URL param —
 * see useVoterPreview.ts, which seeds this store from it.
 */

let currentVoterPreview = false

export function setVoterPreview(active: boolean): void {
  currentVoterPreview = active
}

export function getVoterPreview(): boolean {
  return currentVoterPreview
}
