import { fileURLToPath } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, type Plugin } from 'vite'
import { appExtensionsResolve, extensionsDir, extensionsFsAllow } from './vite.extensions.ts'

const here = fileURLToPath(new URL('.', import.meta.url))

/**
 * Tailwind's automatic source detection only covers this package. When the
 * build pulls in an extension module from elsewhere (VOTEPIT_APP_EXTENSIONS),
 * its directory is registered as an extra `@source` on the CSS entry so the
 * utilities its pages use are generated too. Runs before the Tailwind plugin.
 */
function extensionTailwindSources(dir: string | null): Plugin {
  return {
    name: 'votepit:extension-tailwind-sources',
    enforce: 'pre',
    transform(code, id) {
      if (dir === null || !id.endsWith('/src/index.css')) return null
      return `${code}\n@source "${dir}";\n`
    },
  }
}

export default defineConfig({
  plugins: [extensionTailwindSources(extensionsDir()), react(), tailwindcss()],
  resolve: appExtensionsResolve(here),
  build: {
    rollupOptions: {
      output: {
        // Splits the framework/monitoring vendor code (which barely changes
        // between releases) from the app's own routes, so a redeploy lets
        // returning visitors reuse the cached vendor chunk instead of
        // re-downloading React, Sentry and friends alongside every page's
        // own code every time.
        manualChunks(id: string) {
          if (id.includes('node_modules')) {
            if (id.includes('@sentry')) return 'vendor-sentry'
            if (
              id.includes('react-router') ||
              id.includes('/react/') ||
              id.includes('/react-dom/')
            ) {
              return 'vendor-react'
            }
          }
        },
      },
    },
  },
  server: {
    fs: { allow: extensionsFsAllow(here) },
    // The PHP API and the SPA share paths (e.g. /{board} returns JSON, the
    // same URL renders the board screen in the browser). Split by Accept:
    // JSON requests → PHP API, HTML navigation → Vite SPA. Without this the
    // dev server can't load board data (fetch('/feedback') would hit Vite instead).
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
