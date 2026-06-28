import { Routes, Route } from 'react-router-dom'
import BoardPage from './pages/BoardPage'
import IdeaDetailPage from './pages/IdeaDetailPage'
import SubmitPage from './pages/SubmitPage'
import LoginPage from './pages/LoginPage'
import NotFoundPage from './pages/NotFoundPage'

export default function App() {
  return (
    <Routes>
      <Route path="/:boardSlug" element={<BoardPage />} />
      <Route path="/:boardSlug/idea/:ideaId" element={<IdeaDetailPage />} />
      <Route path="/:boardSlug/submit" element={<SubmitPage />} />
      <Route path="/login" element={<LoginPage />} />
      <Route path="/" element={<BoardPage />} />
      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  )
}
