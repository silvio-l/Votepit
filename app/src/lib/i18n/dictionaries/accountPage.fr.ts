// machine-drafted, needs native review
import type de from './accountPage.de'

const fr = {
  loading: 'Chargement…',
  accessDeniedTitle: 'Accès refusé',
  accessDeniedBody: 'Cette page est réservée au propriétaire du compte.',
  loadError: "La page n'a pas pu être chargée.",
  title: 'Compte',
  subtitle: 'Exportation et suppression des données de ce compte.',
  accountHeading: 'Ce compte',
  nameLabel: 'Nom',
  slugLabel: 'Adresse (nom court)',
  slugHint:
    'Partie de l’adresse du compte, p. ex. « monclub » dans …/monclub/… Lettres minuscules, chiffres et tirets. Unique sur tout Votepit.',
  slugChecking: 'Vérification…',
  slugAvailable: 'Cette adresse est disponible.',
  slugTaken: 'Cette adresse est déjà prise.',
  savingEllipsis: 'Enregistrement…',
  renameSubmit: 'Enregistrer le nom et l’adresse',
  renameSaved: 'Enregistré.',
  saveFailedGeneric: "L'enregistrement a échoué. Veuillez réessayer.",
  legalOperatorHeading: "Informations sur l'exploitant du tableau",
  legalOperatorBody:
    "Affichées sur votre page publique de mentions légales (accessible depuis chaque tableau public). Le nom, l'adresse et l'e-mail de contact sont tous obligatoires avant qu'un tableau puisse être rendu public ou non répertorié.",
  legalOperatorNameLabel: "Nom de l'exploitant",
  legalOperatorNameHint:
    "Le nom de votre entreprise, ou votre propre nom si vous l'exploitez seul.",
  legalOperatorNamePlaceholder: 'ex. Acme SAS, ou votre propre nom',
  legalOperatorAddressLabel: 'Adresse',
  legalOperatorAddressPlaceholder: '12 rue de la Paix\n75002 Paris\nFrance',
  legalOperatorEmailLabel: 'E-mail de contact',
  legalOperatorEmailHint:
    'Une adresse joignable pour les demandes juridiques — affichée sur votre page de mentions légales.',
  legalOperatorEmailPlaceholder: 'contact@votre-domaine.com',
  legalOperatorNoticeLabel: 'Mentions légales complémentaires (facultatif)',
  legalOperatorNoticeHint:
    "Tout ce que votre juridiction exige au-delà du nom/de l'adresse/de l'e-mail — p. ex. numéro de TVA, immatriculation au registre du commerce, autorité de contrôle.",
  legalOperatorNoticePlaceholder: 'ex. N° TVA FR12345678901, RCS Paris 123 456 789',
  legalOperatorSubmit: "Enregistrer les informations sur l'exploitant",
  legalOperatorSaved: 'Enregistré.',
  complianceFrozenBannerHeading: 'Tableaux masqués — informations légales manquantes',
  complianceFrozenBannerBody:
    "Ces tableaux sont actuellement masqués aux visiteurs car les informations légales sur l'exploitant sont manquantes ou non confirmées. Renseignez ci-dessous le nom légal de l'exploitant et confirmez la déclaration de responsabilité d'exploitant sur chaque tableau pour les dégeler :",
  deletionScheduledHeading: 'Le compte est en cours de suppression',
  deletionScheduledPart1:
    'Ce compte, y compris tous les tableaux, idées et commentaires, sera définitivement supprimé le',
  deletionScheduledPart2Days: '(',
  deletionScheduledPart3Days: 'jours restants).',
  deletionScheduledPart2Hours: '(',
  deletionScheduledPart3Hours: 'heures restantes).',
  deletionScheduledHint:
    'Tant que le délai de grâce court, vous pouvez annuler la suppression ici.',
  deletionScheduledUndo: 'Annuler la suppression',
  deletionScheduledUndoing: 'Annulation…',
  deletionScheduledUndoFailed: "Impossible d'annuler la suppression.",
  telemetryHeading: 'Aidez à améliorer Votepit',
  telemetryToggleLabel: 'Envoyer des données d’usage à Votepit',
  telemetryStateOn:
    "Activé — uniquement des compteurs d'installation/d'utilisation des fonctionnalités, aucun contenu de tableau ni d'utilisateur.",
  telemetryStateOff: "Désactivé — rien n'est envoyé à Votepit.",
  telemetryHint:
    'Votepit sait seulement quelles pages sont ouvertes et quelles actions sont effectuées, p. ex. « un tableau a été créé ». Aucun contenu de tes tableaux, aucune idée et aucune adresse e-mail. Aucun cookie n’est déposé, et l’adresse IP est raccourcie avant d’être enregistrée. Activé par défaut ; désactivable à tout moment.',
  telemetrySaved: 'Enregistré.',
  telemetrySaveFailed: "Impossible d'enregistrer ce paramètre.",
  exportHeading: 'Exporter mes données',
  exportBody:
    "Téléchargez une copie complète de toutes les données de ce compte (tableaux, idées, votes, commentaires, membres, invitations, etc.) — sous forme de document JSON ou d'archive ZIP contenant un fichier CSV par table.",
  exportFailed: "L'export n'a pas pu être téléchargé.",
  exportingJson: 'Exportation…',
  exportJson: 'Exporter en JSON',
  exportingCsv: 'Exportation…',
  exportCsv: 'Exporter en CSV (ZIP)',
  dangerZoneHeading: 'Zone de danger',
  dangerZoneBody:
    "Supprime définitivement ce compte — tous les tableaux, idées, votes, commentaires et adhésions. L'identifiant global (compte e-mail) est conservé, seul ce compte disparaît. Une fois la suppression demandée, vous disposez de 48 heures pour l'annuler ici.",
  deleteAccountButton: 'Supprimer le compte',
  deleteAccountConfirmLabel: 'Saisissez « {slug} » pour confirmer',
  deleteAccountConfirmPlaceholder: 'Adresse de ton compte',
  deleteAccountConfirmSubmit: 'Supprimer définitivement',
  deleteAccountConfirmCancel: 'Annuler',
  deleteAccountFailed: 'Impossible de demander la suppression.',
  deletingAccount: 'Demande en cours…',
} satisfies typeof de

export default fr
