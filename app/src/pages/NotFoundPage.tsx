import { Link } from 'react-router-dom'

export default function NotFoundPage() {
  return (
    <div className="min-h-screen font-inter flex items-center justify-center">
      <div className="text-center">
        <h1 className="font-archivo font-bold text-4xl text-vp-ink mb-2">404</h1>
        <p className="text-vp-text-secondary mb-6">Page not found.</p>
        <Link
          to="/"
          className="px-4 py-2 bg-vp-ink text-white font-medium text-sm rounded-vp-md hover:opacity-90 transition-opacity"
        >
          Go Home
        </Link>
      </div>
    </div>
  )
}
