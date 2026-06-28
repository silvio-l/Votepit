import { Route, Routes } from 'react-router-dom'
import BrandBackdrop from './components/BrandBackdrop'
import AdminPage from './pages/AdminPage'
import BoardPage from './pages/BoardPage'
import EditPage from './pages/EditPage'
import IdeaDetailPage from './pages/IdeaDetailPage'
import LoginPage from './pages/LoginPage'
import NotFoundPage from './pages/NotFoundPage'
import SubmitPage from './pages/SubmitPage'
import VerifyPage from './pages/VerifyPage'

export default function App() {
  return (
    <>
      {/* Fixed decorative hex backdrop — aria-hidden, reduced-motion-safe */}
      <BrandBackdrop />

      {/* Page content sits above the backdrop (z-index: 1) */}
      <div style={{ position: 'relative', zIndex: 1 }}>
        <Routes>
          {/* Auth routes — path MUST match what the PHP backend emails:
              $config->appUrl . '/login/verify?token=' . $pair['token'] */}
          <Route path="/login" element={<LoginPage />} />
          <Route path="/login/verify" element={<VerifyPage />} />

          {/* Admin routes — AuthZ enforced both client-side (bootstrap) and
              server-side (AuthZMiddleware::admin). Non-admin → access denied. */}
          <Route path="/admin/boards/:boardSlug" element={<AdminPage />} />

          <Route path="/:boardSlug" element={<BoardPage />} />
          <Route path="/:boardSlug/idea/:ideaId" element={<IdeaDetailPage />} />
          <Route path="/:boardSlug/idea/:ideaId/edit" element={<EditPage />} />
          <Route path="/:boardSlug/submit" element={<SubmitPage />} />
          <Route path="/" element={<BoardPage />} />
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </div>
    </>
  )
}
