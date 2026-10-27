import type de from './widgetSettings.de'

const en = {
  heading: 'Embeddable widget',
  intro:
    'Show this board as a feedback widget on your own website. Signed-in users of your app vote and comment right inside the widget.',
  statusLive: 'Live',
  statusOff: 'Off',
  loadFailed: 'The widget settings could not be loaded.',
  planLocked: 'The widget is not included in your current plan. Your settings are kept.',
  planUpgradeLinkLabel: 'View plans',
  privateBoard: 'Private boards cannot be embedded. Set the visibility to public or unlisted.',
  originsLabel: 'Allowed websites',
  originsHint:
    'One address per line, e.g. https://www.example.com (up to {max}). Website addresses only: no sub-pages, no wildcards. Empty = widget off.',
  originsInvalid:
    'At least one entry is not a valid https origin without path or wildcard, or there are more than {max}.',
  originsSave: 'Save origins',
  originsSaved: 'Saved.',
  saveFailed: 'Saving failed.',
  secretHeading: 'Secret key to recognise signed-in users of your app',
  secretConfigured: 'A secret is set. It is never shown again.',
  secretMissing: 'No secret yet. Without one, the widget is read-only for visitors.',
  secretRevealedTitle: 'Copy it now: the secret is shown this one time only',
  secretFieldLabel: 'Secret key (store it only on your server)',
  secretRotate: 'Rotate secret',
  secretGenerate: 'Generate secret',
  rotateFailed: 'The secret could not be generated.',
  rotateConfirmTitle: 'Rotate the secret?',
  rotateConfirmBody:
    'The old secret stops working immediately and every open widget session ends. Your website then needs the new secret.',
  snippetLabel: 'Embed code',
  promptLabel: 'Prompt for your AI coding agent',
  lastSeen: 'Last seen on {origin} at {date}.',
  notSeen: 'The widget has not been loaded on an allowed website yet.',
} satisfies typeof de

export default en
