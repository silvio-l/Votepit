// machine-drafted, needs native review
import type de from './impressumPage.de'

const fr = {
  loading: 'Chargement…',
  errorHeading: 'Impossible de charger cette page',
  errorLoadFailed: 'Veuillez réessayer dans un instant.',
  notFoundTitle: 'Ces mentions légales ne sont pas là pour le moment',
  notFoundDescription:
    "Elles ont peut-être été déplacées ou retirées. La page d'accueil est un bon point de départ.",
  retry: 'Réessayer',
  heading: 'Mentions légales',
  subtitle: 'Mentions légales de {name}, fournies par son exploitant.',
  nameLabel: 'Exploitant',
  addressLabel: 'Adresse',
  emailLabel: 'Contact',
  noticeHeading: 'Informations complémentaires',
  missingTitle: 'Aucune mention légale disponible',
  missingBody: "L'exploitant de ce tableau n'a pas encore fourni ses mentions légales.",
  privacyHeading: 'Protection des données (RGPD)',
  privacyBody:
    "Ce tableau fonctionne avec Votepit. Votepit traite les données pour le compte de l'exploitant mentionné ci-dessus, qui est le responsable du traitement (« Verantwortlicher ») de ce tableau au sens du RGPD. Pour savoir comment Votepit traite lui-même les données en tant que plateforme, consultez",
  privacyLinkLabel: 'la politique de confidentialité de Votepit',
} satisfies typeof de

export default fr
