import * as matchers from '@testing-library/jest-dom/matchers'
import { cleanup, configure as configureDom } from '@testing-library/react'
import { afterEach, expect, vi } from 'vitest'

expect.extend(matchers)

// Default waitFor timeout (1000ms) is tuned for an idle local machine; under
// CI/CPU contention (parallel test files, shared runners) React effects and
// mocked-fetch chains routinely take longer to settle, causing flaky
// failures that pass on retry. Matched by `testTimeout` in vitest.config.ts.
configureDom({ asyncUtilTimeout: 5000 })

// RTL v16 + Vitest without globals:true requires explicit cleanup
afterEach(cleanup)

// Undoes any vi.stubGlobal() a test made (e.g. navigator.clipboard) so it
// doesn't leak into the next test file.
afterEach(() => vi.unstubAllGlobals())
