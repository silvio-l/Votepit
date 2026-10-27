/*!
 * Votepit embeddable widget loader (ADR 0005) — MIT, part of Votepit Community.
 *
 * Usage on the host page:
 *   <script src="https://<votepit-host>/widget.js" data-board="<account>/<board>" async></script>
 *   <script>
 *     window.Votepit = window.Votepit || function () { (window.Votepit.q = window.Votepit.q || []).push(arguments) }
 *     Votepit('identify', '<short-lived HS256 JWT minted by your server>')   // optional
 *     Votepit('identify', () => fetch('/votepit-jwt').then(r => r.text()))  // or: mint on first open
 *     Votepit('logout'); Votepit('open'); Votepit('close')                   // optional
 *   </script>
 *
 * Security: the board runs in a sandboxed cross-origin iframe. Messages are
 * exchanged with an explicit targetOrigin (never '*') and only accepted from
 * that iframe's window on the Votepit origin. No cookies are involved; the
 * JWT is handed to the iframe in memory and never stored by this script.
 * The host page only writes element styles through the CSSOM, so a strict
 * host CSP (no 'unsafe-inline') keeps working.
 */
(function () {
  'use strict'

  if (window.__votepitWidgetLoaded) return
  window.__votepitWidgetLoaded = true

  var script =
    document.currentScript || document.querySelector('script[data-board][src$="/widget.js"]')
  if (!script) return

  var boardPath = script.getAttribute('data-board') || ''
  if (!/^[a-z0-9-]+(?:\/[a-z0-9-]+)?$/.test(boardPath)) {
    if (window.console) window.console.warn('[votepit] invalid data-board attribute')
    return
  }
  var origin = new URL(script.src, window.location.href).origin
  var frameUrl = origin + '/' + boardPath + '/widget'
  var label = (script.getAttribute('data-label') || 'Feedback').slice(0, 40)

  var iframe = null
  var panel = null
  var frameReady = false
  // A JWT string, or a function returning one (or a Promise of one) — the
  // function form lets the host mint a fresh token only when the panel is
  // first opened, since identify JWTs live at most 10 minutes.
  var pendingJwt = null
  var identified = false

  function css(el, styles) {
    for (var key in styles) {
      if (Object.prototype.hasOwnProperty.call(styles, key)) el.style[key] = styles[key]
    }
  }

  function post(message) {
    if (iframe && frameReady && iframe.contentWindow) {
      iframe.contentWindow.postMessage(message, origin)
    }
  }

  function sendIdentify(source) {
    var value = typeof source === 'function' ? source() : source
    Promise.resolve(value).then(
      function (jwt) {
        if (identified && typeof jwt === 'string' && jwt !== '') {
          post({ type: 'votepit:identify', jwt: jwt })
        }
      },
      function () {
        /* the host failed to mint a token: the widget stays read-only */
      },
    )
  }

  function flush() {
    post({ type: 'votepit:init' })
    if (pendingJwt !== null) {
      sendIdentify(pendingJwt)
      pendingJwt = null
    }
  }

  function ensurePanel() {
    if (panel) return
    panel = document.createElement('div')
    css(panel, {
      position: 'fixed',
      right: '16px',
      bottom: '76px',
      width: 'min(380px, calc(100vw - 32px))',
      height: 'min(600px, calc(100vh - 100px))',
      zIndex: '2147483646',
      borderRadius: '12px',
      overflow: 'hidden',
      boxShadow: '0 12px 40px rgba(0,0,0,0.18)',
      background: '#fff',
      display: 'none',
    })
    iframe = document.createElement('iframe')
    iframe.src = frameUrl
    iframe.title = label
    iframe.setAttribute(
      'sandbox',
      'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox',
    )
    iframe.setAttribute('referrerpolicy', 'strict-origin')
    css(iframe, { border: '0', width: '100%', height: '100%', display: 'block' })
    iframe.addEventListener('load', function () {
      frameReady = true
      flush()
    })
    panel.appendChild(iframe)
    document.body.appendChild(panel)
  }

  function open() {
    ensurePanel()
    panel.style.display = 'block'
    launcher.setAttribute('aria-expanded', 'true')
  }

  function close() {
    if (panel) panel.style.display = 'none'
    launcher.setAttribute('aria-expanded', 'false')
  }

  var launcher = document.createElement('button')
  launcher.type = 'button'
  launcher.textContent = label
  launcher.setAttribute('aria-expanded', 'false')
  css(launcher, {
    position: 'fixed',
    right: '16px',
    bottom: '16px',
    zIndex: '2147483647',
    padding: '10px 16px',
    border: '0',
    borderRadius: '999px',
    background: '#111',
    color: '#fff',
    font: '600 14px/1.2 system-ui, sans-serif',
    cursor: 'pointer',
    boxShadow: '0 4px 16px rgba(0,0,0,0.2)',
  })
  launcher.addEventListener('click', function () {
    if (panel && panel.style.display !== 'none') close()
    else open()
  })

  window.addEventListener('message', function (event) {
    // Only our iframe, on the Votepit origin — every other message is ignored.
    if (!iframe || event.origin !== origin || event.source !== iframe.contentWindow) return
    var data = event.data
    if (data && data.type === 'votepit:close') close()
  })

  function command(name, arg) {
    if (name === 'identify' && ((typeof arg === 'string' && arg !== '') || typeof arg === 'function')) {
      identified = true
      if (frameReady) sendIdentify(arg)
      else pendingJwt = arg
    } else if (name === 'logout') {
      identified = false
      pendingJwt = null
      post({ type: 'votepit:logout' })
    } else if (name === 'open') {
      open()
    } else if (name === 'close') {
      close()
    }
  }

  var queued = (window.Votepit && window.Votepit.q) || []
  window.Votepit = function () {
    command(arguments[0], arguments[1])
  }

  function mount() {
    document.body.appendChild(launcher)
    for (var i = 0; i < queued.length; i++) command(queued[i][0], queued[i][1])
  }

  if (document.body) mount()
  else document.addEventListener('DOMContentLoaded', mount)
})()
