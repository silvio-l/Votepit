/**
 * Tests for the profile-avatar-social API client functions: getAccountProfile,
 * uploadAvatar, deleteAvatar, saveSocialLinks. fetch is mocked; no real
 * network calls are made.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, deleteAvatar, getAccountProfile, saveSocialLinks, uploadAvatar } from './api'

function mockFetchOnce(body: object, status = 200) {
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  )
}

describe('api.ts — profile / avatar / social links', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('getAccountProfile() GETs /account/profile', async () => {
    const fetchMock = mockFetchOnce({
      avatar_url: '/avatar/abc123.jpg',
      website_domain: 'example.com',
      x_handle: 'myhandle',
      youtube_handle: 'my-channel',
      github_username: 'octocat',
    })

    const profile = await getAccountProfile()

    expect(fetchMock.mock.calls[0]?.[0]).toBe('/account/profile')
    expect(profile.avatar_url).toBe('/avatar/abc123.jpg')
    expect(profile.website_domain).toBe('example.com')
    expect(profile.x_handle).toBe('myhandle')
    expect(profile.youtube_handle).toBe('my-channel')
    expect(profile.github_username).toBe('octocat')
  })

  it('uploadAvatar() sends the file as multipart/form-data to POST /account/avatar', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ ok: true, avatar_url: '/avatar/newfile.jpg' }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )

    const file = new File(['fake-bytes'], 'photo.jpg', { type: 'image/jpeg' })
    const result = await uploadAvatar(file)

    expect(result).toEqual({ ok: true, avatar_url: '/avatar/newfile.jpg' })
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/account/avatar')
    expect(init.method).toBe('POST')
    expect(init.body).toBeInstanceOf(FormData)
    expect((init.body as FormData).get('avatar')).toBe(file)
  })

  it('uploadAvatar() throws ApiError with the server-provided key on rejection', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ error: { key: 'invalid_image', message: 'Bad image' } }), {
        status: 422,
        headers: { 'Content-Type': 'application/json' },
      }),
    )

    const file = new File(['fake-bytes'], 'evil.svg', { type: 'image/svg+xml' })
    await expect(uploadAvatar(file)).rejects.toMatchObject({
      name: 'ApiError',
      status: 422,
      payload: { key: 'invalid_image' },
    })
  })

  it('deleteAvatar() DELETEs /account/avatar', async () => {
    const fetchMock = mockFetchOnce({ ok: true })

    await deleteAvatar()

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/account/avatar')
    expect(init.method).toBe('DELETE')
  })

  it('saveSocialLinks() PUTs the 4 fixed fields to /account/social-links', async () => {
    const fetchMock = mockFetchOnce({ ok: true })
    const data = {
      website_domain: 'example.com',
      x_handle: 'myhandle',
      youtube_handle: null,
      github_username: null,
    }

    await saveSocialLinks(data)

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/account/social-links')
    expect(init.method).toBe('PUT')
    expect(JSON.parse(init.body as string)).toEqual({
      website_domain: 'example.com',
      x_handle: 'myhandle',
      youtube_handle: '',
      github_username: '',
    })
  })

  it('saveSocialLinks() surfaces a validation ApiError (e.g. a full URL rejected server-side)', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({ error: { key: 'invalid_website_domain', message: 'invalid domain' } }),
        {
          status: 422,
          headers: { 'Content-Type': 'application/json' },
        },
      ),
    )

    await expect(
      saveSocialLinks({
        website_domain: 'https://insecure.example.com',
        x_handle: null,
        youtube_handle: null,
        github_username: null,
      }),
    ).rejects.toBeInstanceOf(ApiError)
  })
})
