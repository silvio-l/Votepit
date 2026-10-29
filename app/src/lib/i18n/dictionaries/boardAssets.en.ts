import type de from './boardAssets.de'

const en = {
  heading: 'Images and links',
  subtitle:
    'Cover, icon and link preview image, plus a back link and footer note for your board page.',
  coverHeading: 'Cover image',
  coverHint:
    'JPEG, PNG or WebP, up to 4 MB. Recommended: 2400 × 400 px (6:1). Keep important content in the middle: on wide screens only the central part of the image shows.',
  faviconHeading: 'Browser tab icon',
  faviconHint: 'Square, at least 180 × 180 px. Used for the browser tab and the home-screen icon.',
  ogHeading: 'Share preview image',
  ogHint: 'Shown when the board is shared. Cropped to 1200 × 630 px.',
  chooseFile: 'Choose image',
  remove: 'Remove',
  uploading: 'Uploading…',
  dropChoose: 'Choose an image',
  dropReplace: 'Choose a different image',
  dropOr: 'or drag it here – saved right away',
  dropRelease: 'Release to upload',
  uploaded: 'Image saved.',
  removed: 'Image removed.',
  notSet: 'No image set yet.',
  previewAlt: 'Preview of the image',
  lockedImages: 'Custom images are not included in your current plan.',
  uploadFailed: 'The image could not be saved.',
  invalidUpload: 'This is not a valid image. JPEG, PNG and WebP are allowed.',
  fileTooLarge: 'The file is too large.',
  imageTooSmall: 'The image is too small. At least {size} × {size} px.',
  removeFailed: 'The image could not be removed.',
  linkHeading: 'Back link and footer',
  linkUrlLabel: 'Link to your website',
  linkUrlHint: 'Where the back link leads, e.g. https://example.org. Start with https://.',
  linkLabelLabel: 'Text of the back link',
  linkLabelHint:
    'How the link appears at the bottom of your board, e.g. "Back to example.org". 1 to 40 characters.',
  footerLabel: 'Footer note',
  footerHint:
    'Short text at the very bottom of your board, e.g. "Example Club e. V.". Plain text, up to 280 characters.',
  linkSave: 'Save',
  linkSaved: 'Back link and footer saved.',
  linkSaveFailed: 'The details could not be saved.',
  linkLocked: 'Back link and footer are not included in your current plan.',
  planUpgradeLinkLabel: 'View plans',
  logoUnsupported: 'JPEG, PNG and WebP are allowed. SVG files are not allowed for logos.',
  logoTooSmall: 'The logo is too small. At least 200 × 200 px.',
  logoTooLarge: 'The image dimensions are too large.',
  logoHint:
    'JPEG, PNG or WebP, at least 200 × 200 px. Fitted as a square: profile picture and browser-tab icon. Transparency is kept.',
  logoEmpty: 'No app symbol yet. Without one the profile picture shows the board initials.',
  wordmarkHint:
    'JPEG, PNG or WebP, at least 200 px on each side, e.g. 1200 × 300 px. A wide logo for the top bar; transparency is kept.',
  wordmarkEmpty: 'No wordmark yet. The top bar then shows the app symbol or the board initials.',
  platedTip:
    'On your top bar colour the logo would be hard to see, so a light plate sits behind it automatically. For dark top bars, upload a light variant below.',
  wordmarkLightHint:
    'For dark top bars. Used only where it reads clearly on the bar; otherwise the wordmark above stays.',
  wordmarkLightEmpty: 'No light variant yet. On dark top bars the normal wordmark applies.',
  logoPendingAlt: 'Preview of the selected logo',
  logoLegacyHeading: 'Previous logo (link)',
  logoLegacyHint:
    'This link is only shown while no logo is uploaded. Upload an image to replace it.',
  logoLegacyRemove: 'Remove link',
  patternHeading: 'Cover pattern',
  patternHint: 'Shown while no own cover image is uploaded.',
  patternDefault: 'Default',
  pattern_waves: 'Waves',
  pattern_dots: 'Dots',
  pattern_stripes: 'Stripes',
  pattern_bokeh: 'Bokeh',
  pattern_grid: 'Grid',
  uploadTermsTitle: 'Terms for your own images',
  uploadTermsIntro:
    'Before you upload an image for your board, please read these points. You confirm them once for your account; we only ask again when the terms change.',
  uploadTermsPoint1:
    'You may only upload images for which you hold all the necessary rights: your own images or images you are licensed to use. Third-party trademarks and logos only with the permission of the rights holder.',
  uploadTermsPoint2:
    'Unlawful content is not allowed. Recognisable people may only be shown with their consent, and personal data of others only where a legal basis exists.',
  uploadTermsPoint3:
    'We check the format and size, re-encode the image and remove extra data such as location information. PNG, JPEG and WebP are accepted.',
  uploadTermsPoint4:
    "The image is shown publicly on your board's page, including in previews when someone shares the link. If you replace or remove it, the old file is deleted. Deleting the board or the account deletes the images too.",
  uploadTermsPoint5:
    'You are responsible for your images and release Votepit from third-party claims based on them. If an image is clearly unlawful or a justified report comes in, we may remove it and tell you.',
  uploadTermsPoint6:
    'As evidence we store only the time, the version of these terms, your account and your pseudonymous user ID. No e-mail address and no IP address.',
  uploadTermsCheckbox: 'I have read the terms for my own images and accept them.',
  uploadTermsAccept: 'Accept and upload',
  uploadTermsCancel: 'Cancel',
  uploadTermsRequired: 'Please accept the terms for your own images first.',
  uploadTermsFailed: 'Your acceptance could not be saved. Please try again.',
} satisfies typeof de

export default en
