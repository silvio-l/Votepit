<?php

declare(strict_types=1);

namespace Votepit\Digest;

use Votepit\Mail\InlineImage;
use Votepit\Mail\MailBlock;
use Votepit\Mail\MailBrand;
use Votepit\Mail\MailTemplate;

/**
 * Renders the participant digest (close-the-loop slice 03/04, re-engagement C)
 * in one of MailLanguage::SUPPORTED, through the shared MailTemplate.
 *
 * Every item states the concrete reason in the subject and the first line:
 * the new status (with the decision reason, if the board gave one), a new
 * comment, or a new reply. No "consensus"/"majority" claims, no urgency. The
 * footer says why the mail arrives and how to change it. Idea titles, status
 * labels and reasons are user content: they only pass through MailTemplate,
 * which HTML-escapes every value.
 *
 * @phpstan-type DigestItem array{event_kind: string, title: string, status: ?string, reason: ?string, excerpt: ?string, link: string}
 */
final class ParticipantDigestMail
{
    public const EVENT_STATUS       = 'status';
    public const EVENT_COMMENT      = 'comment';
    public const EVENT_THREAD_REPLY = 'thread_reply';

    private const TEXTS = [
        'en' => [
            'subject_status'   => 'Update: "{title}" is now {status}',
            'subject_comment'  => 'New comment on "{title}"',
            'subject_reply'    => 'New reply on "{title}"',
            'subject_many'     => '{count} updates on ideas you follow or wrote',
            'heading_many'     => 'Updates on your ideas',
            'status'           => '"{title}" is now {status}.',
            'reason'           => 'Reason: {reason}',
            'comment'          => 'New comment on "{title}".',
            'reply'            => 'New reply in a thread on "{title}".',
            'cta'              => 'Open the idea',
            'cta_many'         => 'Open Votepit',
            'footer_member'    => 'You get this because you wrote, voted on, commented on or follow these ideas.',
            'footer_guest'     => 'You get this because you left a notification e-mail address on an idea on Votepit.',
            'manage'           => 'Change how often you hear about this',
            'unsubscribe'      => 'Unsubscribe from these updates',
            'button_fallback'  => 'If the button does not work, copy this link into your browser:',
        ],
        'de' => [
            'subject_status'   => 'Neuigkeit: „{title}“ ist jetzt {status}', // export-ok: comment-language
            'subject_comment'  => 'Neuer Kommentar zu „{title}“', // export-ok: comment-language
            'subject_reply'    => 'Neue Antwort zu „{title}“', // export-ok: comment-language
            'subject_many'     => '{count} Neuigkeiten zu Ideen, die du verfolgst oder geschrieben hast', // export-ok: comment-language
            'heading_many'     => 'Neuigkeiten zu deinen Ideen', // export-ok: comment-language
            'status'           => '„{title}“ ist jetzt {status}.', // export-ok: comment-language
            'reason'           => 'Begründung: {reason}', // export-ok: comment-language
            'comment'          => 'Neuer Kommentar zu „{title}“.', // export-ok: comment-language
            'reply'            => 'Neue Antwort in einem Thread zu „{title}“.', // export-ok: comment-language
            'cta'              => 'Idee öffnen', // export-ok: comment-language
            'cta_many'         => 'Votepit öffnen', // export-ok: comment-language
            'footer_member'    => 'Du bekommst das, weil du diese Ideen geschrieben, unterstützt, kommentiert oder verfolgst.', // export-ok: comment-language
            'footer_guest'     => 'Du bekommst das, weil du auf Votepit eine Benachrichtigungs-E-Mail-Adresse zu einer Idee hinterlassen hast.', // export-ok: comment-language
            'manage'           => 'Wie oft du davon hörst, ändern', // export-ok: comment-language
            'unsubscribe'      => 'Von diesen Neuigkeiten abmelden', // export-ok: comment-language
            'button_fallback'  => 'Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:', // export-ok: comment-language
        ],
        // machine-drafted, needs native review
        'es' => [
            'subject_status'   => 'Novedad: "{title}" ahora está {status}',
            'subject_comment'  => 'Nuevo comentario en "{title}"',
            'subject_reply'    => 'Nueva respuesta en "{title}"',
            'subject_many'     => '{count} novedades en ideas que sigues o escribiste',
            'heading_many'     => 'Novedades en tus ideas',
            'status'           => '"{title}" ahora está {status}.',
            'reason'           => 'Motivo: {reason}',
            'comment'          => 'Nuevo comentario en "{title}".',
            'reply'            => 'Nueva respuesta en un hilo sobre "{title}".',
            'cta'              => 'Abrir la idea',
            'cta_many'         => 'Abrir Votepit',
            'footer_member'    => 'Recibes esto porque escribiste, votaste, comentaste o sigues estas ideas.',
            'footer_guest'     => 'Recibes esto porque dejaste una dirección de correo para notificaciones en una idea de Votepit.',
            'manage'           => 'Cambia con qué frecuencia recibes esto',
            'unsubscribe'      => 'Darse de baja de estas novedades',
            'button_fallback'  => 'Si el botón no funciona, copia este enlace en tu navegador:',
        ],
        'fr' => [
            'subject_status'   => 'Mise à jour : « {title} » est maintenant {status}',
            'subject_comment'  => 'Nouveau commentaire sur « {title} »',
            'subject_reply'    => 'Nouvelle réponse sur « {title} »',
            'subject_many'     => '{count} mises à jour sur des idées que vous suivez ou avez écrites',
            'heading_many'     => 'Mises à jour sur vos idées',
            'status'           => '« {title} » est maintenant {status}.',
            'reason'           => 'Motif : {reason}',
            'comment'          => 'Nouveau commentaire sur « {title} ».',
            'reply'            => 'Nouvelle réponse dans un fil sur « {title} ».',
            'cta'              => 'Ouvrir l’idée',
            'cta_many'         => 'Ouvrir Votepit',
            'footer_member'    => 'Vous recevez ceci parce que vous avez écrit, voté, commenté ou suivez ces idées.',
            'footer_guest'     => 'Vous recevez ceci parce que vous avez laissé une adresse e-mail de notification sur une idée de Votepit.',
            'manage'           => 'Modifier la fréquence de ces messages',
            'unsubscribe'      => 'Se désabonner de ces mises à jour',
            'button_fallback'  => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',
        ],
        // machine-drafted, needs native review
        'it' => [
            'subject_status'   => 'Novità: "{title}" ora è {status}',
            'subject_comment'  => 'Nuovo commento su "{title}"',
            'subject_reply'    => 'Nuova risposta su "{title}"',
            'subject_many'     => '{count} novità su idee che segui o hai scritto',
            'heading_many'     => 'Novità sulle tue idee',
            'status'           => '"{title}" ora è {status}.',
            'reason'           => 'Motivo: {reason}',
            'comment'          => 'Nuovo commento su "{title}".',
            'reply'            => 'Nuova risposta in un thread su "{title}".',
            'cta'              => 'Apri l’idea',
            'cta_many'         => 'Apri Votepit',
            'footer_member'    => 'Ricevi questa mail perché hai scritto, votato, commentato o segui queste idee.',
            'footer_guest'     => 'Ricevi questa mail perché hai lasciato un indirizzo e-mail per le notifiche su un’idea di Votepit.',
            'manage'           => 'Cambia la frequenza con cui ricevi questi avvisi',
            'unsubscribe'      => 'Annulla l’iscrizione da questi aggiornamenti',
            'button_fallback'  => 'Se il pulsante non funziona, copia questo link nel tuo browser:',
        ],
        // machine-drafted, needs native review
        'nl' => [
            'subject_status'   => 'Update: "{title}" is nu {status}',
            'subject_comment'  => 'Nieuwe reactie op "{title}"',
            'subject_reply'    => 'Nieuw antwoord op "{title}"',
            'subject_many'     => '{count} updates over ideeën die je volgt of hebt geschreven',
            'heading_many'     => 'Updates over je ideeën',
            'status'           => '"{title}" is nu {status}.',
            'reason'           => 'Reden: {reason}',
            'comment'          => 'Nieuwe reactie op "{title}".',
            'reply'            => 'Nieuw antwoord in een draad over "{title}".',
            'cta'              => 'Idee openen',
            'cta_many'         => 'Votepit openen',
            'footer_member'    => 'Je ontvangt dit omdat je deze ideeën hebt geschreven, gestemd, becommentarieerd of volgt.',
            'footer_guest'     => 'Je ontvangt dit omdat je op Votepit een e-mailadres voor meldingen hebt achtergelaten bij een idee.',
            'manage'           => 'Wijzig hoe vaak je dit hoort',
            'unsubscribe'      => 'Afmelden voor deze updates',
            'button_fallback'  => 'Werkt de knop niet, kopieer dan deze link naar je browser:',
        ],
    ];

    /**
     * @param list<DigestItem> $items   at least one item
     * @return array{subject: string, text: string, html: string, image: InlineImage}
     */
    public static function render(
        string $language,
        array $items,
        string $prefsUrl,
        string $unsubscribeUrl,
        string $appUrl,
        bool $guest,
        ?MailBrand $brand,
    ): array {
        $t    = self::TEXTS[$language] ?? self::TEXTS['en'];
        $many = count($items) > 1;

        $paragraphs = [];
        foreach ($items as $item) {
            $reason = $item['reason'] !== null && $item['reason'] !== '' && $item['event_kind'] === self::EVENT_STATUS
                ? strtr($t['reason'], ['{reason}' => $item['reason']])
                : null;
            if ($many) {
                // One linked block per item instead of a bare URL line (mail clients
                // show bare URLs as default-blue autolinks).
                $paragraphs[] = MailBlock::idea(self::sentence($item, $t), $reason ?? '', $item['link']);
                continue;
            }
            $paragraphs[] = self::sentence($item, $t);
            if ($reason !== null) {
                $paragraphs[] = $reason;
            }
        }

        $footer      = [$guest ? $t['footer_guest'] : $t['footer_member']];
        $footerLinks = $guest ? [] : [['label' => $t['manage'], 'url' => $prefsUrl]];

        $single = $items[0];
        $mail   = MailTemplate::render(
            $many ? $t['heading_many'] : self::subjectFor($single, $t),
            $paragraphs,
            $many ? $appUrl . '/' : $single['link'],
            $many ? $t['cta_many'] : $t['cta'],
            $footer,
            ['label' => $t['unsubscribe'], 'url' => $unsubscribeUrl],
            $brand,
            footerLinks: $footerLinks,
            buttonFallback: $t['button_fallback'],
        );

        return [
            'subject' => $many ? strtr($t['subject_many'], ['{count}' => (string) count($items)]) : self::subjectFor($single, $t),
            ...$mail,
        ];
    }

    /**
     * @param DigestItem $item
     * @param array<string, string> $t
     */
    private static function subjectFor(array $item, array $t): string
    {
        return match ($item['event_kind']) {
            self::EVENT_STATUS => strtr($t['subject_status'], ['{title}' => $item['title'], '{status}' => (string) $item['status']]),
            self::EVENT_COMMENT => strtr($t['subject_comment'], ['{title}' => $item['title']]),
            default => strtr($t['subject_reply'], ['{title}' => $item['title']]),
        };
    }

    /**
     * @param DigestItem $item
     * @param array<string, string> $t
     */
    private static function sentence(array $item, array $t): string
    {
        return match ($item['event_kind']) {
            self::EVENT_STATUS => strtr($t['status'], ['{title}' => $item['title'], '{status}' => (string) $item['status']]),
            self::EVENT_COMMENT => strtr($t['comment'], ['{title}' => $item['title']]),
            default => strtr($t['reply'], ['{title}' => $item['title']]),
        };
    }
}
