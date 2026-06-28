import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    // Die PHP-API und die SPA teilen sich Pfade (z. B. /{board} liefert JSON,
    // dieselbe URL rendert im Browser den Board-Screen). Aufteilung per Accept:
    // JSON-Anfragen → PHP-API, HTML-Navigation → Vite-SPA. Ohne das kann der
    // Dev-Server keine Board-Daten laden (fetch('/demo') träfe sonst Vite).
    proxy: {
      '^/': {
        target: 'http://localhost:8080',
        changeOrigin: true,
        bypass(req) {
          const accept = req.headers.accept || ''
          if (accept.includes('application/json')) return undefined
          return req.url
        },
      },
    },
  },
})
