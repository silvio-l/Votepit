/**
 * vote.js — Progressives Enhancement + Mikroanimationen für Vote-Controls
 * (Sprint 4, Issue 04 + 05).
 *
 * Fängt den Submit der Vote-Mini-Forms ab und postet via fetch mit
 * Accept: application/json. Die JSON-Antwort { score, my_vote, up_count, down_count }
 * aktualisiert Score, Control-Zustand und Konsens-Balken ohne Seitenneuladen — und
 * gibt zurückhaltendes Feedback wie auf der Landing-Page: Button-Pop, token-gefärbtes
 * Konfetti (Up grün / Down vermillion, Rückzug ohne Konfetti) und ein Score-Tick.
 *
 * Ohne JS funktioniert der Form-POST (PRG) unverändert — keine harte Abhängigkeit.
 * prefers-reduced-motion: alle JS-Animationen werden übersprungen (reine
 * State-Übernahme bleibt), CSS-Transitions sind in base.twig separat gegated.
 *
 * Vorausgesetzte DOM-Struktur (ui.twig vote_form-Macro):
 *   .vp-vote[ enthält zwei .vp-vote-form (je up/down) ]
 *   .vp-vote-score zeigt den Score · .vp-vote-up / .vp-vote-down sind die Buttons.
 * Konsens-Balken (ui.twig consensus_bar) wird, falls in derselben Karte vorhanden,
 * über die data-cons*-Hooks live nachgeführt.
 */

(function () {
    'use strict';

    var REDUCE = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Token-Farben aus dem CSS lesen — keine Hex-Werte im JS hartkodieren.
    function token(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }
    var UP_COLOR = token('--vp-vote-up', '#0E9466');
    var DOWN_COLOR = token('--vp-vote-down', '#D8503C');

    /** Kurzer Scale-Pop auf dem geklickten Button (Web Animations API). */
    function pop(el) {
        if (REDUCE || !el || !el.animate) { return; }
        el.animate(
            [{ transform: 'scale(.82)' }, { transform: 'scale(1.14)' }, { transform: 'scale(1)' }],
            { duration: 280, easing: 'cubic-bezier(.2,.8,.3,1.2)' }
        );
    }

    /**
     * Token-gefärbtes Konfetti aus dem geklickten Button heraus.
     * Up = grün (gelegentlich ein dezentes Emoji), Down = vermillion (nur Partikel).
     */
    function burst(widget, btn, color, festive) {
        if (REDUCE || !widget || !btn || !widget.animate) { return; }
        var wr = widget.getBoundingClientRect();
        var br = btn.getBoundingClientRect();
        var cx = br.left - wr.left + br.width / 2;
        var cy = br.top - wr.top + br.height / 2;
        var emojis = ['🎉', '✨'];
        var n = festive ? 12 : 8;
        for (var i = 0; i < n; i++) {
            var p = document.createElement('span');
            p.className = 'vp-particle';
            if (festive && Math.random() < 0.3) {
                p.textContent = emojis[Math.floor(Math.random() * emojis.length)];
            } else {
                p.style.background = color;
                p.style.borderRadius = Math.random() < 0.5 ? '50%' : '2px';
            }
            p.style.left = cx + 'px';
            p.style.top = cy + 'px';
            widget.appendChild(p);
            var dx = (Math.random() * 2 - 1) * (festive ? 64 : 46);
            var dy = -(22 + Math.random() * (festive ? 78 : 54));
            var rot = Math.random() * 320 - 160;
            p.animate(
                [
                    { transform: 'translate(0,0) scale(1) rotate(0)', opacity: 1 },
                    { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.5) rotate(' + rot + 'deg)', opacity: 0 }
                ],
                { duration: 620 + Math.random() * 380, easing: 'cubic-bezier(.15,.7,.3,1)' }
            ).onfinish = (function (node) { return function () { node.remove(); }; }(p));
        }
    }

    /** Score animiert von alt → neu hochzählen (oder bei reduced-motion/rapid snappen). */
    function setScore(scoreEl, from, to, animate) {
        scoreEl.classList.toggle('vp-vote-score--neg', to < 0);
        if (REDUCE || !animate || from === to) {
            scoreEl.textContent = String(to);
            scoreEl.classList.remove('is-ticking');
            return;
        }
        scoreEl.classList.add('is-ticking');
        var diff = to - from;
        var dur = Math.min(300, 110 + 35 * Math.abs(diff));
        var t0 = performance.now();
        (function step(now) {
            var prog = Math.min(1, (now - t0) / dur);
            var e = 1 - Math.pow(1 - prog, 3);
            scoreEl.textContent = String(Math.round(from + diff * e));
            if (prog < 1) {
                requestAnimationFrame(step);
            } else {
                scoreEl.textContent = String(to);
                scoreEl.classList.remove('is-ticking');
            }
        }(t0));
    }

    /** Konsens-Balken in derselben Karte aus up/down nachführen (Breite, Farbe, Label). */
    function updateConsensus(widget, upCount, downCount) {
        var card = widget.closest('.vp-row, .vp-feat-card, .vp-detail-card');
        var cons = card && card.querySelector('[data-cons]');
        if (!cons) { return; }
        var total = upCount + downCount;
        if (total <= 0) { return; }
        var pct = Math.round((upCount / total) * 100);
        var low = pct < 50;
        var fill = cons.querySelector('[data-cons-fill]');
        var pctEl = cons.querySelector('[data-cons-pct]');
        var labelEl = cons.querySelector('[data-cons-label]');
        if (fill) { fill.style.width = pct + '%'; }
        if (pctEl) { pctEl.textContent = pct + '%'; }
        if (labelEl) { labelEl.textContent = low ? 'Umstritten' : 'Konsens'; }
        cons.classList.toggle('vp-cons--low', low);
    }

    /**
     * Übernimmt die Server-Antwort: Klassen-Zustand + animierter Score + Feedback.
     *
     * @param {HTMLElement} widget   - .vp-vote-Element
     * @param {object}      data     - { score, my_vote, up_count, down_count }
     * @param {string}      clicked  - 'up' | 'down' (welcher Button gesendet hat)
     * @param {boolean}     animate  - false bei schnellem Klick-Streak (snap)
     */
    function applyState(widget, data, clicked, animate) {
        var scoreEl = widget.querySelector('.vp-vote-score');
        var from = parseInt((scoreEl && scoreEl.textContent) || '0', 10);
        if (isNaN(from)) { from = 0; }
        if (scoreEl) { setScore(scoreEl, from, data.score, animate); }

        widget.classList.remove('vp-vote--up', 'vp-vote--down');
        if (data.my_vote === 'up') {
            widget.classList.add('vp-vote--up');
        } else if (data.my_vote === 'down') {
            widget.classList.add('vp-vote--down');
        }

        var btn = widget.querySelector(clicked === 'up' ? '.vp-vote-up' : '.vp-vote-down');
        pop(btn);
        // Konfetti nur bei aktiver Stimme — ein Rückzug (my_vote 'none') feiert nicht.
        if (data.my_vote === 'up') {
            burst(widget, btn, UP_COLOR, true);
        } else if (data.my_vote === 'down') {
            burst(widget, btn, DOWN_COLOR, false);
        }

        if (typeof data.up_count === 'number' && typeof data.down_count === 'number') {
            updateConsensus(widget, data.up_count, data.down_count);
        }
    }

    /** Hängt den fetch-Handler an alle Vote-Forms innerhalb eines Widgets. */
    function initWidget(widget) {
        var forms = widget.querySelectorAll('.vp-vote-form');
        var lastClickT = -1e9;

        forms.forEach(function (form) {
            form.addEventListener('submit', function (evt) {
                evt.preventDefault();

                var valueField = form.querySelector('input[name="value"]');
                var clicked = (valueField && valueField.value) === 'down' ? 'down' : 'up';

                var now = (window.performance && performance.now) ? performance.now() : 0;
                var animate = (now - lastClickT) >= 220; // Streak → snap, bewusster Klick → animieren
                lastClickT = now;

                var formData = new FormData(form);
                var body = new URLSearchParams();
                formData.forEach(function (val, key) {
                    body.append(key, String(val));
                });

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: body.toString()
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
                            applyState(widget, data, clicked, animate);
                        }
                    })
                    .catch(function () {
                        // Netzwerkfehler: still ignorieren; der User kann manuell neu laden.
                    });
            });
        });
    }

    /**
     * Zahl beim Laden von 0 auf ihren Zielwert hochzählen — die „Daten als
     * Signatur"-Geste der Landing, jetzt auf den Voting-Kernzahlen (Score,
     * Konsens-%, Bento-Aggregate). Vorzeichen/Suffix (z. B. „%") bleiben erhalten.
     */
    function countUp(el, delay) {
        var raw = el.textContent.trim();
        var m = raw.match(/^(-?)(\d+)(\D*)$/);
        if (!m) { return; }
        var to = parseInt(m[1] + m[2], 10);
        var suffix = m[3] || '';
        var dur = 700;
        el.textContent = '0' + suffix;
        window.setTimeout(function () {
            el.classList.add('is-ticking');
            var t0 = performance.now();
            (function step(now) {
                var prog = Math.min(1, (now - t0) / dur);
                var e = 1 - Math.pow(1 - prog, 3);
                el.textContent = String(Math.round(to * e)) + suffix;
                if (prog < 1) {
                    requestAnimationFrame(step);
                } else {
                    el.textContent = raw;
                    el.classList.remove('is-ticking');
                }
            }(t0));
        }, delay);
    }

    /** Start-Pass: zählt alle Voting-Kernzahlen beim Laden hoch (gestaffelt). */
    function startReveal() {
        if (REDUCE) { return; } // reduced-motion: SSR-Werte stehen lassen
        var scores = document.querySelectorAll('.vp-vote-score');
        scores.forEach(function (el, i) { countUp(el, 80 + i * 55); });
        var pcts = document.querySelectorAll('[data-cons-pct]');
        pcts.forEach(function (el, i) { countUp(el, 140 + i * 55); });
        var bento = document.querySelectorAll('.vp-bento-num');
        bento.forEach(function (el, i) { countUp(el, 120 + i * 70); });
    }

    // Alle .vp-vote-Elemente initialisieren, die mindestens eine .vp-vote-form enthalten.
    document.querySelectorAll('.vp-vote').forEach(function (widget) {
        if (widget.querySelector('.vp-vote-form')) {
            initWidget(widget);
        }
    });

    // Voting-Kernzahlen beim Laden lebendig starten — auch für ausgeloggte Besucher.
    startReveal();
}());
