/**
 * vote.js — Progressives Enhancement für Vote-Controls (Issue 04, Sprint 4).
 *
 * Fängt den Submit der Vote-Mini-Forms ab und postet via fetch mit
 * Accept: application/json. Die JSON-Antwort { score, my_vote, up_count, down_count }
 * aktualisiert Score + Control-Zustand ohne Seitenneuladen.
 *
 * Ohne JS funktioniert der Form-POST (PRG) unverändert — keine harte Abhängigkeit.
 * prefers-reduced-motion: keine erzwungenen Animationen; CSS-Transitions
 * sind bereits in der Seite per @media (prefers-reduced-motion: reduce) abgesichert.
 *
 * Vorausgesetzte DOM-Struktur (ui.twig vote_form-Macro):
 *   .vp-vote[data-idea-id] enthält zwei .vp-vote-form (je up/down).
 *   .vp-vote-score zeigt den Score.
 *   .vp-vote-up / .vp-vote-down sind die Submit-Buttons.
 */

(function () {
    'use strict';

    /**
     * Aktualisiert den visuellen Zustand eines Vote-Widgets.
     *
     * @param {HTMLElement} widget   - .vp-vote-Element
     * @param {number}      score    - neuer Score
     * @param {string}      myVote   - 'up' | 'down' | 'none'
     */
    function applyState(widget, score, myVote) {
        var scoreEl = widget.querySelector('.vp-vote-score');
        if (scoreEl) {
            scoreEl.textContent = String(score);
            scoreEl.classList.toggle('vp-vote-score--neg', score < 0);
        }

        widget.classList.remove('vp-vote--up', 'vp-vote--down');
        if (myVote === 'up') {
            widget.classList.add('vp-vote--up');
        } else if (myVote === 'down') {
            widget.classList.add('vp-vote--down');
        }
    }

    /**
     * Hängt den fetch-Handler an alle Vote-Forms innerhalb eines Widgets.
     *
     * @param {HTMLElement} widget - .vp-vote-Element
     */
    function initWidget(widget) {
        var forms = widget.querySelectorAll('.vp-vote-form');

        forms.forEach(function (form) {
            form.addEventListener('submit', function (evt) {
                evt.preventDefault();

                var formData = new FormData(form);
                var body = new URLSearchParams();
                formData.forEach(function (val, key) {
                    body.append(key, String(val));
                });

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: body.toString(),
                })
                    .then(function (res) {
                        if (!res.ok) {
                            // Nicht-200: Fallback — Seite neu laden (z. B. nach Session-Ablauf).
                            window.location.reload();
                            return null;
                        }
                        return res.json();
                    })
                    .then(function (data) {
                        if (data && typeof data.score === 'number' && typeof data.my_vote === 'string') {
                            applyState(widget, data.score, data.my_vote);
                        }
                    })
                    .catch(function () {
                        // Netzwerkfehler: still ignorieren; der User kann manuell neu laden.
                    });
            });
        });
    }

    // Alle .vp-vote-Elemente initialisieren, die mindestens eine .vp-vote-form enthalten.
    document.querySelectorAll('.vp-vote').forEach(function (widget) {
        if (widget.querySelector('.vp-vote-form')) {
            initWidget(widget);
        }
    });
}());
