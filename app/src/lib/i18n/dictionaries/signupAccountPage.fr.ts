// machine-drafted, needs native review
import type de from './signupAccountPage.de'

const fr = {
  loading: 'Chargement…',
  loadError: 'La page n’a pas pu être chargée.',
  alreadyHasAccountTitle: 'Vous avez déjà un compte',
  alreadyHasAccountBody:
    'Un seul compte est prévu par identifiant. Vous êtes déjà connecté — accédez à votre compte ci-dessous.',
  doneTitle: 'Votre compte est prêt',
  doneBodyBeforePath: 'Votre premier tableau est en ligne à',
  doneBodyAfterPath: '',
  manageAccountCta: 'Accéder à la gestion',
  heading: 'Configurer votre compte',
  subheading:
    'Un nom et une adresse pour votre compte, plus votre premier tableau. Vous en devenez automatiquement le propriétaire.',
  accountLegend: 'Compte',
  nameLabel: 'Nom',
  accountNamePlaceholder: 'ex. Acme SAS',
  slugLabel: 'Adresse',
  accountSlugPlaceholder: 'ex. acme',
  accountSlugHint: 'Votre adresse : {url}',
  legalOperatorLegend: 'Qui exploite le tableau ?',
  legalOperatorLegendHint:
    'Votre tableau est public, il lui faut donc un contact affiché dans les mentions légales. Les visiteurs savent ainsi qui se cache derrière.',
  legalOperatorNameLabel: 'Nom de l’exploitant',
  legalOperatorNamePlaceholder: 'ex. Acme SAS, ou votre propre nom',
  legalOperatorAddressLabel: 'Adresse postale',
  legalOperatorAddressPlaceholder: '1 rue de la Paix\n75001 Paris\nFrance',
  legalOperatorEmailLabel: 'E-mail de contact',
  legalOperatorEmailPlaceholder: 'contact@votre-domaine.fr',
  firstBoardLegend: 'Votre premier tableau',
  boardNamePlaceholder: "ex. Nos idées ou Idées pour la fête d'été",
  boardSlugPlaceholder: 'ex. nos-idees',
  submitSubmitting: 'Création en cours…',
  submit: 'Créer le compte',
  termsCheckboxLabel:
    'J’accepte les Conditions d’utilisation, la Politique d’utilisation acceptable et la Politique de confidentialité.',
  termsLinkAgb: 'Conditions d’utilisation',
  termsLinkAup: 'Politique d’utilisation acceptable',
  termsLinkPrivacy: 'Politique de confidentialité',
  stepsAriaLabel: 'Progression de la configuration',
  stepEmail: 'E-mail confirmé',
  stepAccount: 'Compte et tableau',
  stepReady: 'Terminé',
} satisfies typeof de

export default fr
