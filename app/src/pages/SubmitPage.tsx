import { Link, useParams } from 'react-router-dom'

export default function SubmitPage() {
  const { boardSlug } = useParams()

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

      <main className="max-w-xl mx-auto px-4 py-8">
        <Link
          to={`/${boardSlug ?? ''}`}
          className="text-vp-text-secondary text-sm hover:text-vp-ink transition-colors"
        >
          &larr; Back to board
        </Link>

        <h1 className="font-archivo font-bold text-2xl text-vp-ink mt-4 mb-6">Submit an Idea</h1>

        <form className="space-y-4" onSubmit={(e) => e.preventDefault()}>
          <div>
            <label className="block text-sm font-medium text-vp-ink mb-1">Title</label>
            <input
              type="text"
              placeholder="Short, descriptive title"
              className="w-full px-3 py-2 bg-white/72 backdrop-blur border border-vp-border-subtle rounded-vp-md text-vp-ink placeholder:text-vp-text-muted focus:outline-none focus:ring-2 focus:ring-vp-accent/30"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-vp-ink mb-1">Description</label>
            <textarea
              rows={4}
              placeholder="Describe the idea in detail..."
              className="w-full px-3 py-2 bg-white/72 backdrop-blur border border-vp-border-subtle rounded-vp-md text-vp-ink placeholder:text-vp-text-muted resize-none focus:outline-none focus:ring-2 focus:ring-vp-accent/30"
            />
          </div>
          <button
            type="submit"
            className="w-full py-2.5 bg-vp-ink text-white font-medium text-sm rounded-vp-md hover:opacity-90 transition-opacity"
          >
            Submit Idea
          </button>
        </form>
      </main>
    </div>
  )
}
