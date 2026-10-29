/**
 * Plain-language guard for the onboarding copy (signup, setup wizard,
 * checklist). The audience is clubs, teams and normal users, not developers:
 * software jargon such as "Slug", "AUP" or "Magic link" must not appear in the
 * German or English strings. Legal wording stays intact and is not checked here.
 */

import { describe, expect, it } from 'vitest'
import activationDe from '../lib/i18n/dictionaries/activationChecklist.de'
import activationEn from '../lib/i18n/dictionaries/activationChecklist.en'
import setupDe from '../lib/i18n/dictionaries/setupWizard.de'
import setupEn from '../lib/i18n/dictionaries/setupWizard.en'
import signupDe from '../lib/i18n/dictionaries/signupAccountPage.de'
import signupEn from '../lib/i18n/dictionaries/signupAccountPage.en'

const BANNED = [/\bslug\b/i, /\baup\b/i, /magic[\s-]?link/i]

const DICTIONARIES: Array<[string, Record<string, unknown>]> = [
  ['setupWizard.de', setupDe],
  ['setupWizard.en', setupEn],
  ['activationChecklist.de', activationDe],
  ['activationChecklist.en', activationEn],
  ['signupAccountPage.de', signupDe],
  ['signupAccountPage.en', signupEn],
]

describe('onboarding copy is plain language', () => {
  for (const [name, dict] of DICTIONARIES) {
    it(`${name} uses no software jargon`, () => {
      for (const [key, value] of Object.entries(dict)) {
        if (typeof value !== 'string') continue
        for (const pattern of BANNED) {
          expect(value, `${name}.${key}`).not.toMatch(pattern)
        }
      }
    })
  }
})
