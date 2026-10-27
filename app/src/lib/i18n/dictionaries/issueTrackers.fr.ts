// machine-drafted, needs native review
import type de from './issueTrackers.de'

const fr = {
  heading: 'Linear & GitHub',
  intro:
    "Connectez Linear ou GitHub pour transformer une idée en ticket en un clic. Quand le ticket y est terminé, l'idée passe automatiquement à « Terminé ».",
  planUpgradeHint: 'Les tickets Linear et GitHub ne sont pas inclus dans votre offre actuelle.',
  planUpgradeLinkLabel: 'Voir les offres',
  loadError: "Les connexions n'ont pas pu être chargées.",
  providerLinear: 'Linear',
  providerGithub: 'GitHub',
  linearTokenLabel: 'Clé API Linear',
  linearTokenHint: 'Clé API personnelle depuis Linear → Settings → Security & access.',
  githubTokenLabel: 'Jeton GitHub',
  githubTokenHint:
    "Jeton d'accès personnel « fine-grained » avec l'autorisation « Issues: Read and write » pour ce dépôt.",
  tokenKeepHint: 'Laissez vide pour conserver le jeton enregistré ({hint}).',
  teamKeyLabel: "Clé d'équipe",
  teamKeyHint: 'Par exemple ENG.',
  repositoryLabel: 'Dépôt',
  repositoryHint: 'Au format owner/repo.',
  webhookSecretLabel: 'Clé secrète du webhook',
  webhookSecretHint:
    "Dans Linear (Settings → API → Webhooks), créez un webhook pour « Issues » avec l'URL du webhook et collez ici son secret de signature. Laissez vide pour conserver celui enregistré.",
  connect: 'Connecter',
  save: 'Enregistrer',
  disconnect: 'Déconnecter',
  connectedTo: 'Connecté à {target}, jeton {hint}',
  syncOn: 'Synchronisation du statut active',
  syncOff: 'Synchronisation du statut désactivée',
  webhookUrlLabel: 'URL du webhook',
  copy: 'Copier',
  copied: 'Copié',
  githubWebhookSetup:
    "Dans GitHub (dépôt → Settings → Webhooks), ajoutez un webhook avec cette URL : content type application/json, le secret ci-dessous et uniquement l'événement « Issues ».",
  githubSecretOnceLabel: 'Secret du webhook (affiché uniquement maintenant)',
  errorInvalidCredentials: 'Le jeton a été refusé.',
  errorTargetNotFound: "Équipe ou dépôt introuvable, ou le jeton n'y a pas accès.",
  errorUnavailable: 'Le service est momentanément injoignable. Réessayez plus tard.',
  errorValidation: 'Vérifiez vos saisies.',
  errorGeneric: "Cela n'a pas fonctionné. Réessayez.",
  panelLabel: 'Gestionnaire de tickets',
  createIssue: 'Créer un ticket {provider}',
  unlink: 'Délier',
  unlinkAriaLabel: 'Supprimer le lien vers {key}',
  errorLinkExists: 'Un ticket existe déjà pour cette idée.',
  errorNotConnected: "Le gestionnaire de tickets n'est plus connecté.",
  errorRejected: 'Le gestionnaire de tickets a refusé le ticket.',
} satisfies typeof de

export default fr
