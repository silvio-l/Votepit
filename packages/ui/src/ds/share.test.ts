import assert from 'node:assert/strict'
import { test } from 'node:test'
import { type ShareEnvironment, shareLink } from './share.ts'

const URL_ = 'https://feedback.example.com/acme/ideas'

test('uses the native share sheet when available', async () => {
  const calls: unknown[] = []
  const env: ShareEnvironment = {
    share: async (data) => {
      calls.push(data)
    },
    canShare: () => true,
    writeText: async () => {
      throw new Error('must not copy')
    },
  }
  assert.equal(await shareLink(URL_, 'Acme', env), 'shared')
  assert.deepEqual(calls, [{ title: 'Acme', url: URL_ }])
})

test('falls back to copying when the browser cannot share the data', async () => {
  const copied: string[] = []
  const env: ShareEnvironment = {
    share: async () => {
      throw new Error('must not share')
    },
    canShare: () => false,
    writeText: async (text) => {
      copied.push(text)
    },
  }
  assert.equal(await shareLink(URL_, 'Acme', env), 'copied')
  assert.deepEqual(copied, [URL_])
})

test('a cancelled share sheet is not an error and copies nothing', async () => {
  const abort = Object.assign(new Error('cancelled'), { name: 'AbortError' })
  const env: ShareEnvironment = {
    share: async () => {
      throw abort
    },
    writeText: async () => {
      throw new Error('must not copy')
    },
  }
  assert.equal(await shareLink(URL_, 'Acme', env), 'cancelled')
})

test('other share errors fall back to copying', async () => {
  const copied: string[] = []
  const env: ShareEnvironment = {
    share: async () => {
      throw new TypeError('not allowed')
    },
    writeText: async (text) => {
      copied.push(text)
    },
  }
  assert.equal(await shareLink(URL_, 'Acme', env), 'copied')
  assert.equal(copied.length, 1)
})

test('reports failed when neither sharing nor copying works', async () => {
  assert.equal(await shareLink(URL_, 'Acme', {}), 'failed')
  const env: ShareEnvironment = {
    writeText: async () => {
      throw new Error('denied')
    },
  }
  assert.equal(await shareLink(URL_, 'Acme', env), 'failed')
})
