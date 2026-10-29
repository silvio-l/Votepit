// machine-drafted, needs native review
import type de from './publicProfilePage.de'

const fr = {
  title: 'Profil',
  loading: 'Chargement…',
  backToBoard: 'Retour au tableau',
  socialLinksAriaLabel: 'Liens sociaux',
  anonymousHint:
    "Ce membre n'affiche pas de profil public. Sur les idées et les commentaires, il apparaît comme « Votant ».",
  notFoundTitle: "Ce membre n'est pas là pour le moment",
  notFoundDescription:
    "Le profil n'est peut-être plus visible, ou il n'existe plus. La page d'accueil est un bon point de départ.",
  errorTitle: "Le profil n'a pas pu être chargé",
  loadError: "Nous n'avons pas pu charger ce profil pour le moment. Veuillez réessayer.",
  statsIdeasSubmitted: 'Idées proposées',
  statsIdeasShipped: 'Idées réalisées',
  statsVotesCast: 'Votes exprimés',
} satisfies typeof de

export default fr
