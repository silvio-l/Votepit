import { BrowserRouter, Routes, Route } from 'react-router-dom'
import BrandBackdrop from './components/BrandBackdrop'
import LandingEn from './pages/LandingEn'

export default function App() {
  return (
    <BrowserRouter>
      <BrandBackdrop />
      <Routes>
        <Route path="/" element={<LandingEn />} />
        <Route path="/de" element={<LandingEn />} />
      </Routes>
    </BrowserRouter>
  )
}
