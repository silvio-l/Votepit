// machine-drafted, needs native review
import type de from './supportPage.de'

const fr = {
  loading: 'Chargement…',
  loadError: "Impossible de charger l'assistance.",
  title: 'Assistance',
  subtitle:
    'Contactez notre équipe directement depuis le tableau de bord — nous vous répondons dès que possible.',
  accessDeniedTitle: 'Accès refusé',
  accessDeniedBody: "Vous n'êtes pas membre de ce compte.",

  formHeading: 'Nouvelle demande',
  categoryLabel: 'Catégorie',
  subjectLabel: 'Objet',
  messageLabel: 'Message',
  submit: 'Envoyer',
  submitting: 'Envoi…',
  submitSuccess:
    'Merci ! Nous avons bien reçu votre demande. Notre réponse apparaîtra dans votre boîte de réception.',
  submitFailed: "La demande n'a pas pu être envoyée. Veuillez réessayer.",

  faqDeflectionHeading: 'Ceci pourrait déjà vous aider',

  ticketsHeading: 'Vos demandes ({count})',
  noTickets: "Vous n'avez encore envoyé aucune demande d'assistance.",
  subjectColumn: 'Objet',
  categoryColumn: 'Catégorie',
  statusColumn: 'Statut',
  updatedColumn: 'Dernière activité',
  ticketNotFoundTitle: 'Ticket introuvable',
  ticketNotFoundBody:
    "Cette demande d'assistance n'existe pas (ou plus) ou n'appartient pas à ce compte.",
  threadHeading: 'Conversation',
  threadLoading: 'Chargement de la conversation…',
  threadLoadError: 'Impossible de charger la conversation.',
  fromYou: 'Vous',
  fromSupport: 'Assistance',
  replyLabel: 'Votre réponse',
  replyPlaceholder: 'Écrivez un message…',
  replySubmit: 'Envoyer',
  replySubmitting: 'Envoi…',
  replyFailed: "Le message n'a pas pu être envoyé. Veuillez réessayer.",

  machineTranslatedLabel: 'Traduction automatique',
  showOriginalLink: "Afficher l'original",
  showTranslationLink: 'Afficher la traduction',
  translationUnavailableHint: 'La traduction est actuellement indisponible.',

  'category.billing': 'Facturation',
  'category.technical': 'Problème technique',
  'category.account': 'Compte et adhésion',
  'category.feature_request': 'Demande de fonctionnalité',
  'category.privacy': 'Confidentialité',
  'category.other': 'Autre',

  'status.open': 'Ouvert',
  'status.answered': 'Répondu',
  'status.closed': 'Fermé',
} satisfies typeof de

export default fr
