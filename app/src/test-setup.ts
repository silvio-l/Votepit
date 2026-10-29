import * as matchers from '@testing-library/jest-dom/matchers'
import { cleanup, configure as configureDom } from '@testing-library/react'
import { afterEach, expect, vi } from 'vitest'

expect.extend(matchers)

/**
 * Node 26 defines a global `localStorage` / `sessionStorage` getter that
 * returns undefined unless `--localstorage-file` is set, shadowing jsdom's
 * implementation. When the global storage is not usable, install an
 * in-memory Storage so the suite behaves the same on Node 22 and Node 26.
 * On a working runtime nothing is replaced.
 */
function createMemoryStorage(): Storage {
  const data = new Map<string, string>()
  return {
    get length() {
      return data.size
    },
    clear: () => data.clear(),
    getItem: (key: string) => (data.has(String(key)) ? (data.get(String(key)) as string) : null),
    key: (index: number) => Array.from(data.keys())[index] ?? null,
    removeItem: (key: string) => {
      data.delete(String(key))
    },
    setItem: (key: string, value: string) => {
      data.set(String(key), String(value))
    },
  } as Storage
}

function ensureStorage(name: 'localStorage' | 'sessionStorage'): void {
  let current: unknown
  try {
    current = (globalThis as unknown as Record<string, unknown>)[name]
  } catch {
    current = undefined
  }
  if (current && typeof (current as Storage).clear === 'function') return
  Object.defineProperty(globalThis, name, {
    value: createMemoryStorage(),
    configurable: true,
    writable: true,
  })
}

ensureStorage('localStorage')
ensureStorage('sessionStorage')

/**
 * jsdom doesn't implement IntersectionObserver (used by useInView.ts for
 * board-list lazy-translate) — a controllable stub that starts NOT
 * intersecting (matches real off-screen-until-scrolled behavior) and lets
 * a test trigger intersection deterministically via
 * `triggerIntersection(element)`, rather than every test needing its own
 * mock.
 */
class MockIntersectionObserver implements IntersectionObserver {
  static instances: MockIntersectionObserver[] = []
  /** Elements a test already scrolled into view: an observer that starts watching them later fires at once. */
  static inView = new Set<Element>()
  readonly root = null
  readonly rootMargin = ''
  readonly thresholds: number[] = []
  private callback: IntersectionObserverCallback
  private elements = new Set<Element>()

  constructor(callback: IntersectionObserverCallback) {
    this.callback = callback
    MockIntersectionObserver.instances.push(this)
  }

  observe(el: Element) {
    this.elements.add(el)
    // Under load the effect that observes an element can run after the test's
    // triggerIntersection(); a real browser reports a visible element on observe too.
    if (MockIntersectionObserver.inView.has(el)) queueMicrotask(() => this.trigger(el))
  }

  unobserve(el: Element) {
    this.elements.delete(el)
  }

  disconnect() {
    this.elements.clear()
  }

  takeRecords(): IntersectionObserverEntry[] {
    return []
  }

  trigger(el: Element) {
    if (!this.elements.has(el)) return
    this.callback(
      [{ isIntersecting: true, target: el } as IntersectionObserverEntry],
      this as unknown as IntersectionObserver,
    )
  }
}

// Assigned directly (not via vi.stubGlobal) so it survives this file's own
// `afterEach(() => vi.unstubAllGlobals())` below — that call is for tests
// that stub something themselves, not for this file-wide jsdom polyfill.
globalThis.IntersectionObserver = MockIntersectionObserver as unknown as typeof IntersectionObserver

/** Test helper: simulates `el` scrolling into view for every registered useInView() observer watching it. */
export function triggerIntersection(el: Element): void {
  MockIntersectionObserver.inView.add(el)
  for (const instance of MockIntersectionObserver.instances) instance.trigger(el)
}

afterEach(() => {
  MockIntersectionObserver.instances = []
  MockIntersectionObserver.inView.clear()
})

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
