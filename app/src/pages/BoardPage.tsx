import { useParams } from 'react-router-dom'

export default function BoardPage() {
  const { boardSlug } = useParams()

  return (
    <div className="min-h-screen font-inter">
      <header className="sticky top-0 z-50 w-full backdrop-blur-xl bg-white/72 border-b border-vp-border-subtle">
        <div className="max-w-4xl mx-auto px-4 h-14 flex items-center justify-between">
          <h1 className="font-archivo font-extrabold text-lg text-vp-ink">
            Votepit
          </h1>
          <nav className="flex items-center gap-2 text-sm text-vp-text-secondary">
            <button className="px-3 py-1.5 rounded-vp-sm hover:bg-black/5 transition-colors">
              Login
            </button>
          </nav>
        </div>
      </header>

      <main className="max-w-2xl mx-auto px-4 py-8">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h2 className="font-archivo font-bold text-2xl text-vp-ink">
              {boardSlug ?? 'Feature Requests'}
            </h2>
            <p className="text-vp-text-secondary text-sm mt-1">
              Vote on what we should build next.
            </p>
          </div>
          <a
            href={boardSlug ? `/${boardSlug}/submit` : '/submit'}
            className="px-4 py-2 bg-vp-ink text-white font-medium text-sm rounded-vp-md hover:opacity-90 transition-opacity"
          >
            + New Idea
          </a>
        </div>

        <div className="flex gap-2 mb-6">
          {['Top', 'Newest', 'Controversial'].map((tab) => (
            <button
              key={tab}
              className={`px-3 py-1.5 rounded-vp-sm text-sm font-medium transition-colors ${
                tab === 'Top'
                  ? 'bg-vp-ink text-white'
                  : 'text-vp-text-secondary hover:bg-black/5'
              }`}
            >
              {tab}
            </button>
          ))}
        </div>

        <div className="space-y-3">
          <p className="text-vp-text-muted text-sm text-center py-12">
            No ideas yet. Be the first to submit one!
          </p>
        </div>
      </main>
    </div>
  )
}
