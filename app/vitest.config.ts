import { fileURLToPath } from 'node:url'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'
import { appExtensionsResolve, extensionsFsAllow } from './vite.extensions.ts'

const here = fileURLToPath(new URL('.', import.meta.url))

export default defineConfig({
  plugins: [react()],
  resolve: appExtensionsResolve(here),
  server: { fs: { allow: extensionsFsAllow(here) } },
  test: {
    environment: 'jsdom',
    setupFiles: [`${here}src/test-setup.ts`],
    // Headroom over test-setup.ts's waitFor timeout (5000ms) so a slow CI
    // runner doesn't kill the test right as waitFor itself would give up.
    testTimeout: 10000,
    pool: 'forks',
  },
})
