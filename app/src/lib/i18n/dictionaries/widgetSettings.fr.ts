// machine-drafted, needs native review
import type de from './widgetSettings.de'

const fr = {
  heading: 'Widget intégrable',
  intro:
    'Affichez ce tableau comme widget de feedback sur votre propre site. Les utilisateurs connectés de votre application votent et commentent directement dans le widget.',
  statusLive: 'Actif',
  statusOff: 'Désactivé',
  loadFailed: "Les paramètres du widget n'ont pas pu être chargés.",
  planLocked:
    "Le widget n'est pas inclus dans votre offre actuelle. Vos paramètres sont conservés.",
  planUpgradeLinkLabel: 'Voir les offres',
  privateBoard:
    'Les tableaux privés ne peuvent pas être intégrés. Réglez la visibilité sur public ou non répertorié.',
  originsLabel: 'Sites autorisés',
  originsHint:
    'Une adresse par ligne, p. ex. https://www.exemple.com (jusqu’à {max}). Adresses de site uniquement : pas de sous-pages, pas de caractères génériques. Vide = widget désactivé.',
  originsInvalid:
    "Au moins une entrée n'est pas une origine https valide sans chemin ni joker, ou il y en a plus de {max}.",
  originsSave: 'Enregistrer les origines',
  originsSaved: 'Enregistré.',
  saveFailed: "L'enregistrement a échoué.",
  secretHeading: 'Clé secrète pour reconnaître les utilisateurs connectés de votre application',
  secretConfigured: 'Un secret est configuré. Il ne sera plus jamais affiché.',
  secretMissing:
    'Pas encore de secret. Sans secret, le widget est en lecture seule pour les visiteurs.',
  secretRevealedTitle: "Copiez-le maintenant : le secret ne s'affiche qu'une seule fois",
  secretFieldLabel: 'Clé secrète (à stocker uniquement côté serveur)',
  secretRotate: 'Renouveler le secret',
  secretGenerate: 'Générer un secret',
  rotateFailed: "Le secret n'a pas pu être généré.",
  rotateConfirmTitle: 'Renouveler le secret ?',
  rotateConfirmBody:
    "L'ancien secret cesse immédiatement de fonctionner et toutes les sessions du widget se terminent. Votre site aura ensuite besoin du nouveau secret.",
  snippetLabel: "Code d'intégration",
  promptLabel: 'Prompt pour votre agent de code IA',
  lastSeen: 'Vu pour la dernière fois sur {origin} le {date}.',
  notSeen: "Le widget n'a encore été chargé sur aucun site autorisé.",
} satisfies typeof de

export default fr
