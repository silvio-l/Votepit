export default function LoginPage() {
  return (
    <div className="min-h-screen font-inter flex items-center justify-center">
      <div className="w-full max-w-sm mx-4 p-6 bg-vp-surface backdrop-blur-xl rounded-vp-xl border border-vp-border-subtle text-center">
        <h1 className="font-archivo font-bold text-xl text-vp-ink mb-2">
          Sign in to Votepit
        </h1>
        <p className="text-vp-text-secondary text-sm mb-6">
          We'll send you a magic link to your email.
        </p>

        <form onSubmit={(e) => e.preventDefault()} className="space-y-3">
          <input
            type="email"
            placeholder="your@email.com"
            className="w-full px-3 py-2 bg-white/72 border border-vp-border-subtle rounded-vp-md text-vp-ink placeholder:text-vp-text-muted text-center focus:outline-none focus:ring-2 focus:ring-vp-accent/30"
          />
          <button
            type="submit"
            className="w-full py-2.5 bg-vp-ink text-white font-medium text-sm rounded-vp-md hover:opacity-90 transition-opacity"
          >
            Send Magic Link
          </button>
        </form>
      </div>
    </div>
  )
}
