/**
 * Use-case copy guard (broaden-use-cases issue 08, decision 2026-10-01 "9b"):
 * the board template is only known inside the setup wizard (it is not
 * persisted), so every screen outside the wizard uses neutral wording for all
 * audiences. Only the product-template wizard placeholders, which the user
 * picks explicitly, stay product-specific.
 */

import { describe, expect, it } from 'vitest'
import type { Language } from '../lib/i18n/context'
import boardsAdminDe from '../lib/i18n/dictionaries/boardsAdminPage.de'
import boardsAdminEn from '../lib/i18n/dictionaries/boardsAdminPage.en'
import boardsAdminEs from '../lib/i18n/dictionaries/boardsAdminPage.es'
import boardsAdminFr from '../lib/i18n/dictionaries/boardsAdminPage.fr'
import boardsAdminIt from '../lib/i18n/dictionaries/boardsAdminPage.it'
import boardsAdminNl from '../lib/i18n/dictionaries/boardsAdminPage.nl'
import commonDe from '../lib/i18n/dictionaries/common.de'
import commonEn from '../lib/i18n/dictionaries/common.en'
import commonEs from '../lib/i18n/dictionaries/common.es'
import commonFr from '../lib/i18n/dictionaries/common.fr'
import commonIt from '../lib/i18n/dictionaries/common.it'
import commonNl from '../lib/i18n/dictionaries/common.nl'
import profileDe from '../lib/i18n/dictionaries/publicProfilePage.de'
import profileEn from '../lib/i18n/dictionaries/publicProfilePage.en'
import profileEs from '../lib/i18n/dictionaries/publicProfilePage.es'
import profileFr from '../lib/i18n/dictionaries/publicProfilePage.fr'
import profileIt from '../lib/i18n/dictionaries/publicProfilePage.it'
import profileNl from '../lib/i18n/dictionaries/publicProfilePage.nl'
import roadmapDe from '../lib/i18n/dictionaries/roadmapPage.de'
import roadmapEn from '../lib/i18n/dictionaries/roadmapPage.en'
import roadmapEs from '../lib/i18n/dictionaries/roadmapPage.es'
import roadmapFr from '../lib/i18n/dictionaries/roadmapPage.fr'
import roadmapIt from '../lib/i18n/dictionaries/roadmapPage.it'
import roadmapNl from '../lib/i18n/dictionaries/roadmapPage.nl'
import wizardDe from '../lib/i18n/dictionaries/setupWizard.de'
import wizardEn from '../lib/i18n/dictionaries/setupWizard.en'
import wizardEs from '../lib/i18n/dictionaries/setupWizard.es'
import wizardFr from '../lib/i18n/dictionaries/setupWizard.fr'
import wizardIt from '../lib/i18n/dictionaries/setupWizard.it'
import wizardNl from '../lib/i18n/dictionaries/setupWizard.nl'
import signupDe from '../lib/i18n/dictionaries/signupAccountPage.de'
import signupEn from '../lib/i18n/dictionaries/signupAccountPage.en'
import signupEs from '../lib/i18n/dictionaries/signupAccountPage.es'
import signupFr from '../lib/i18n/dictionaries/signupAccountPage.fr'
import signupIt from '../lib/i18n/dictionaries/signupAccountPage.it'
import signupNl from '../lib/i18n/dictionaries/signupAccountPage.nl'

type Dicts = Record<
  Language,
  {
    common: typeof commonDe
    boardsAdmin: typeof boardsAdminDe
    profile: typeof profileDe
    roadmap: typeof roadmapDe
    wizard: typeof wizardDe
    signup: typeof signupDe
  }
>

const DICTS: Dicts = {
  de: {
    common: commonDe,
    boardsAdmin: boardsAdminDe,
    profile: profileDe,
    roadmap: roadmapDe,
    wizard: wizardDe,
    signup: signupDe,
  },
  en: {
    common: commonEn,
    boardsAdmin: boardsAdminEn,
    profile: profileEn,
    roadmap: roadmapEn,
    wizard: wizardEn,
    signup: signupEn,
  },
  fr: {
    common: commonFr,
    boardsAdmin: boardsAdminFr,
    profile: profileFr,
    roadmap: roadmapFr,
    wizard: wizardFr,
    signup: signupFr,
  },
  es: {
    common: commonEs,
    boardsAdmin: boardsAdminEs,
    profile: profileEs,
    roadmap: roadmapEs,
    wizard: wizardEs,
    signup: signupEs,
  },
  nl: {
    common: commonNl,
    boardsAdmin: boardsAdminNl,
    profile: profileNl,
    roadmap: roadmapNl,
    wizard: wizardNl,
    signup: signupNl,
  },
  it: {
    common: commonIt,
    boardsAdmin: boardsAdminIt,
    profile: profileIt,
    roadmap: roadmapIt,
    wizard: wizardIt,
    signup: signupIt,
  },
}

// Software vocabulary across all six languages (product, feedback, shipped,
// built, delivered, launched, implemented, roadmap).
const SOFTWARE_WORDS =
  /produ|feedback|retours|ship|built|livr|lanza|implement|opgeleverd|gebouwd|roadmap|feuille de route|hoja de ruta/i

function neutralStrings(d: Dicts[Language]) {
  return {
    loginPoint: d.common['auth.point3'],
    adminNamePlaceholder: d.boardsAdmin.namePlaceholder,
    adminSlugPlaceholder: d.boardsAdmin.slugPlaceholder,
    profileDone: d.profile.statsIdeasShipped,
    roadmapEmptyDoneTitle: d.roadmap.emptyDoneTitle,
    roadmapEmptyDoneDescription: d.roadmap.emptyDoneDescription,
    signupName: d.signup.boardNamePlaceholder,
    signupSlug: d.signup.boardSlugPlaceholder,
  }
}

describe('use-case copy: board-less default strings are audience-neutral', () => {
  it('uses the neutral wording in every language', () => {
    const actual = Object.fromEntries(
      (Object.keys(DICTS) as Language[]).map((lang) => [lang, neutralStrings(DICTS[lang])]),
    )
    expect(actual).toEqual({
      de: {
        loginPoint: 'Klare Prioritäten, direkt aus den Stimmen',
        adminNamePlaceholder: 'z. B. Unsere Ideen',
        adminSlugPlaceholder: 'z. B. unsere-ideen',
        profileDone: 'Umgesetzte Ideen',
        roadmapEmptyDoneTitle: 'Noch nichts erledigt',
        roadmapEmptyDoneDescription: 'Umgesetzte Ideen sammeln sich hier.',
        signupName: 'z. B. Unsere Ideen oder Ideen fürs Sommerfest',
        signupSlug: 'z. B. unsere-ideen',
      },
      en: {
        loginPoint: 'Clear priorities, straight from the votes',
        adminNamePlaceholder: 'e.g. Our ideas',
        adminSlugPlaceholder: 'e.g. our-ideas',
        profileDone: 'Ideas completed',
        roadmapEmptyDoneTitle: 'Nothing done yet',
        roadmapEmptyDoneDescription: 'Completed ideas collect here.',
        signupName: 'e.g. Our Ideas or Summer Party Ideas',
        signupSlug: 'e.g. our-ideas',
      },
      fr: {
        loginPoint: 'Des priorités claires, issues directement des votes',
        adminNamePlaceholder: 'ex. Nos idées',
        adminSlugPlaceholder: 'ex. nos-idees',
        profileDone: 'Idées réalisées',
        roadmapEmptyDoneTitle: 'Rien de terminé pour le moment',
        roadmapEmptyDoneDescription: 'Les idées réalisées sont regroupées ici.',
        signupName: "ex. Nos idées ou Idées pour la fête d'été",
        signupSlug: 'ex. nos-idees',
      },
      es: {
        loginPoint: 'Prioridades claras, directamente de los votos',
        adminNamePlaceholder: 'p. ej. Nuestras ideas',
        adminSlugPlaceholder: 'p. ej. nuestras-ideas',
        profileDone: 'Ideas realizadas',
        roadmapEmptyDoneTitle: 'Aún no hay nada hecho',
        roadmapEmptyDoneDescription: 'Aquí se reúnen las ideas que ya se han hecho realidad.',
        signupName: 'p. ej. Nuestras ideas o Ideas para la fiesta de verano',
        signupSlug: 'p. ej. nuestras-ideas',
      },
      nl: {
        loginPoint: 'Duidelijke prioriteiten, rechtstreeks uit de stemmen',
        adminNamePlaceholder: 'bijv. Onze ideeën',
        adminSlugPlaceholder: 'bijv. onze-ideeen',
        profileDone: 'Gerealiseerde ideeën',
        roadmapEmptyDoneTitle: 'Nog niets afgerond',
        roadmapEmptyDoneDescription: 'Gerealiseerde ideeën verzamelen zich hier.',
        signupName: 'bijv. Onze ideeën of Ideeën voor het zomerfeest',
        signupSlug: 'bijv. onze-ideeen',
      },
      it: {
        loginPoint: 'Priorità chiare, direttamente dai voti',
        adminNamePlaceholder: 'es. Le nostre idee',
        adminSlugPlaceholder: 'es. nostre-idee',
        profileDone: 'Idee realizzate',
        roadmapEmptyDoneTitle: 'Ancora niente di realizzato',
        roadmapEmptyDoneDescription: 'Le idee realizzate si raccolgono qui.',
        signupName: 'es. Le nostre idee o Idee per la festa d’estate',
        signupSlug: 'es. nostre-idee',
      },
    })
  })

  it.each(Object.keys(DICTS) as Language[])('%s contains no software words', (lang) => {
    for (const value of Object.values(neutralStrings(DICTS[lang]))) {
      expect(value).not.toMatch(SOFTWARE_WORDS)
    }
  })

  it.each(Object.keys(DICTS) as Language[])('%s slug placeholders are valid slugs', (lang) => {
    const { adminSlugPlaceholder, signupSlug } = neutralStrings(DICTS[lang])
    for (const value of [adminSlugPlaceholder, signupSlug]) {
      expect(value.split(' ').pop()).toMatch(/^[a-z0-9]+(-[a-z0-9]+)*$/)
    }
  })
})

describe('use-case copy: product-template wizard placeholders stay product-specific', () => {
  it('keeps the product placeholders in every language', () => {
    const actual = Object.fromEntries(
      (Object.keys(DICTS) as Language[]).map((lang) => [
        lang,
        [DICTS[lang].wizard.namePlaceholderProduct, DICTS[lang].wizard.slugPlaceholder],
      ]),
    )
    expect(actual).toEqual({
      de: ['z. B. Produkt-Feedback', 'z. B. produkt-feedback'],
      en: ['e.g. Product feedback', 'e.g. product-feedback'],
      fr: ['ex. Retours produit', 'ex. retours-produit'],
      es: ['p. ej. Comentarios sobre el producto', 'p. ej. comentarios-producto'],
      nl: ['bijv. Productfeedback', 'bijv. productfeedback'],
      it: ['es. Feedback sul prodotto', 'es. feedback-prodotto'],
    })
  })
})

describe('use-case copy: event/club/team wizard placeholders avoid software words', () => {
  it.each(Object.keys(DICTS) as Language[])('%s', (lang) => {
    const w = DICTS[lang].wizard
    for (const value of [
      w.namePlaceholderEvent,
      w.namePlaceholderClub,
      w.namePlaceholderTeam,
      w.slugPlaceholderEvent,
      w.slugPlaceholderClub,
      w.slugPlaceholderTeam,
    ]) {
      expect(value).not.toMatch(SOFTWARE_WORDS)
    }
  })

  it.each(Object.keys(DICTS) as Language[])('%s slug placeholders are valid slugs', (lang) => {
    const w = DICTS[lang].wizard
    for (const value of [w.slugPlaceholderEvent, w.slugPlaceholderClub, w.slugPlaceholderTeam]) {
      expect(value.split(' ').pop()).toMatch(/^[a-z0-9]+(-[a-z0-9]+)*$/)
    }
  })
})
