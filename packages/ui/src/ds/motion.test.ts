import assert from 'node:assert/strict'
import { test } from 'node:test'
import { prefersReducedMotion, REDUCED_MOTION_QUERY } from './motion.ts'

test('reports reduced motion only when the reduce query matches', () => {
  const asked: string[] = []
  const win = {
    matchMedia: (q: string) => {
      asked.push(q)
      return { matches: true }
    },
  }
  assert.equal(prefersReducedMotion(win), true)
  assert.deepEqual(asked, [REDUCED_MOTION_QUERY])
  assert.equal(prefersReducedMotion({ matchMedia: () => ({ matches: false }) }), false)
})

test('falls back to motion on when the query cannot be read', () => {
  assert.equal(prefersReducedMotion(undefined), false)
  assert.equal(prefersReducedMotion({}), false)
  assert.equal(
    prefersReducedMotion({
      matchMedia: () => {
        throw new Error('unsupported')
      },
    }),
    false,
  )
})
