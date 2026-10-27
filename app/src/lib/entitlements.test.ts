import { describe, expect, it } from 'vitest'
import {
  type Entitlements,
  FEATURE_KEYS,
  featureEntitlement,
  requiredPlanFor,
} from './entitlements'

/** Every feature allowed except the given ones, which are locked to `required`. */
function entitlementsWithLocked(
  locked: Partial<Record<(typeof FEATURE_KEYS)[number], 'lite' | 'pro'>>,
): Entitlements {
  const features = Object.fromEntries(
    FEATURE_KEYS.map((key) => {
      const required = locked[key]
      return [
        key,
        required === undefined
          ? { allowed: true, required_plan: null }
          : { allowed: false, required_plan: required },
      ]
    }),
  ) as Entitlements['features']
  return { current_plan: 'free', can_change_plan: false, features }
}

describe('entitlements helper', () => {
  it('returns null for every feature when no entitlements are known', () => {
    expect(featureEntitlement(null, 'logo_url')).toBeNull()
    expect(featureEntitlement(undefined, 'logo_url')).toBeNull()
    expect(requiredPlanFor(null, 'logo_url')).toBeNull()
  })

  it('reports an allowed feature without a required plan', () => {
    const entitlements = entitlementsWithLocked({})
    expect(featureEntitlement(entitlements, 'logo_url')).toEqual({
      allowed: true,
      required_plan: null,
    })
    expect(requiredPlanFor(entitlements, 'logo_url')).toBeNull()
  })

  it('names the lowest tier that unlocks a locked feature', () => {
    const entitlements = entitlementsWithLocked({ intro: 'lite', visibility_private: 'pro' })
    expect(requiredPlanFor(entitlements, 'intro')).toBe('lite')
    expect(requiredPlanFor(entitlements, 'visibility_private')).toBe('pro')
  })

  it('treats a feature missing from the payload as unknown, not as allowed', () => {
    const entitlements = entitlementsWithLocked({})
    const partial = {
      ...entitlements,
      features: Object.fromEntries(
        Object.entries(entitlements.features).filter(([key]) => key !== 'agent_api'),
      ) as Entitlements['features'],
    }
    expect(featureEntitlement(partial, 'agent_api')).toBeNull()
    expect(requiredPlanFor(partial, 'agent_api')).toBeNull()
  })
})
