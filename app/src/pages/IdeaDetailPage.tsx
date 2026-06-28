import { useParams, Link } from 'react-router-dom'

export default function IdeaDetailPage() {
  const { boardSlug, ideaId } = useParams()

  return (
    <div className="min-h-screen font-inter">
      <header className="sticky top-0 z-50 w-full backdrop-blur-xl bg-white/72 border-b border-vp-border-subtle">
        <div className="max-w-4xl mx-auto px-4 h-14 flex items-center justify-between">
          <Link
            to={`/${boardSlug ?? ''}`}
            className="font-archivo font-extrabold text-lg text-vp-ink"
          >
            Votepit
          </Link>
        </div>
      </header>

      <main className="max-w-2xl mx-auto px-4 py-8">
        <Link
          to={`/${boardSlug ?? ''}`}
          className="text-vp-text-secondary text-sm hover:text-vp-ink transition-colors"
        >
          &larr; Back to board
        </Link>

        <div className="mt-6 p-6 bg-vp-surface backdrop-blur-xl rounded-vp-xl border border-vp-border-subtle">
          <h1 className="font-archivo font-bold text-xl text-vp-ink">
            Idea #{ideaId}
          </h1>
          <p className="text-vp-text-muted text-sm mt-2">
            Loading idea details...
          </p>
        </div>
      </main>
    </div>
  )
}
