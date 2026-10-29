// machine-drafted, needs native review
import type de from './apiTokensPage.de'

const fr = {
  loading: 'Chargement…',
  accessDeniedTitle: 'Accès refusé',
  accessDeniedBody: 'Cette page est réservée aux administrateurs du tableau.',
  loadError: "La page n'a pas pu être chargée.",
  title: 'Jetons API',
  subtitle:
    'Clés d’accès pour les bots et les agents IA — donnez-leur accès à un ou plusieurs de vos tableaux.',
  createFailed: 'La création a échoué. Veuillez réessayer.',
  revokeFailed: 'La révocation a échoué.',
  confirmRevoke:
    "Vraiment révoquer ce jeton API ? Toute intégration qui l'utilise cessera de fonctionner immédiatement et définitivement.",
  createHeading: 'Créer un nouveau jeton',
  createBody:
    'Le jeton ne s’affiche qu’une seule fois, juste après sa création. Copiez-le tout de suite : il ne sera plus consultable.',
  labelField: 'Libellé',
  labelPlaceholder: 'ex. Bot CI',
  scopeField: 'Accès',
  scopeNone: 'Aucun accès',
  scopeRead: 'Lecture seule',
  scopeWrite: 'Lecture et écriture',
  scopeModerate: 'Lecture, écriture et modération',
  scopeModerateHint:
    '« Modération » permet en plus au jeton de modifier statut, tags et fusions et de lire les idées en attente de validation – uniquement tant que vous modérez ce compte.',
  scopeManage: 'Gérer (identité visuelle & images)',
  scopeManageHint:
    '« Gérer » permet en plus au jeton de modifier l’identité visuelle du board et d’ajouter ou supprimer ses images – uniquement tant que la personne qui a créé le jeton reste propriétaire ou administrateur du compte. Les niveaux inférieurs ne l’incluent jamais.',
  boardsField: 'Tableaux',
  boardScopeAriaLabel: 'Accès pour {board}',
  noBoardsYet: "Aucun tableau pour le moment — créez d'abord un tableau.",
  creating: 'Création…',
  createSubmit: 'Créer le jeton',
  revealedTokenNotice: 'Jeton « {label} » — copiez-le maintenant, il ne sera plus affiché :',
  revealedTokenFieldLabel: 'Jeton',
  tokensHeading: 'Jetons ({count})',
  tokensAriaLabel: 'Jetons API',
  noTokensYet: 'Aucun jeton créé pour le moment.',
  createdOn: 'créé le {date}',
  lastUsedOn: ', dernière utilisation le {date}',
  revokedSuffix: 'révoqué',
  oauthBadge: 'OAuth',
  revokeAriaLabel: 'Révoquer le jeton « {label} »',
  revoke: 'Révoquer',
  docsIntro: 'En savoir plus :',
  docsApiReference: "Référence de l'API",
  docsMcpServer: 'Serveur MCP',
  setupHeading: 'Configurer un agent',
  setupIntro:
    "Choisissez une cible ci-dessous pour obtenir un prompt prêt à coller pour un agent de codage IA — il contient déjà ce jeton et l'URL de votre tableau, afin que l'agent puisse se configurer lui-même et vérifier la connexion.",
  setupTargetLabel: 'Cible',
  setupTargetClaudeCode: 'Claude Code (MCP)',
  setupTargetClaudeCodeCli: 'Claude Code (CLI + skill, sans MCP)',
  setupTargetClaudeDesktop: 'Claude Desktop',
  setupTargetCursorVscode: 'Cursor / VS Code',
  setupTargetGenericCli: 'CLI générique (@votepit/cli)',
  setupPromptFieldLabel: 'Prompt pour votre agent IA',
  planLimitAgentApi: "L'API pour agents n'est pas incluse dans votre offre actuelle.",
} satisfies typeof de

export default fr
