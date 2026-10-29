// machine-drafted, needs native review
import type de from './inboxPage.de'

const fr = {
  loading: 'Chargement…',
  loadError: 'Impossible de charger la boîte de réception.',
  title: 'Boîte de réception',
  subtitle: "Réponses à vos demandes d'assistance et actualités de Votepit.",

  notificationsHeading: 'Notifications ({count})',
  noNotifications: 'Aucune notification pour le moment.',
  unreadBadge: 'Nouveau',
  dismiss: 'Retirer',
  dismissAriaLabel: 'Retirer « {title} » de la boîte de réception',

  machineTranslatedLabel: 'Traduction automatique',
  showOriginalLink: "Afficher l'original",
  showTranslationLink: 'Afficher la traduction',
  translationUnavailableHint: 'La traduction est actuellement indisponible.',
} satisfies typeof de

export default fr
