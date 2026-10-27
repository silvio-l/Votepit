<?php

declare(strict_types=1);

namespace Votepit\Digest;

use Votepit\Mail\MailBrand;
use Votepit\Mail\MailTemplate;

/**
 * Renders the opt-in weekly "new ideas" digest (close-the-loop re-engagement F)
 * in one of MailLanguage::SUPPORTED, through the shared MailTemplate.
 *
 * Covers only public boards the recipient has participated in. Idea titles and
 * board names are user content: MailTemplate HTML-escapes every value.
 *
 * @phpstan-type IdeaSection array{board_name: string, board_url: string, ideas: list<array{id: int, title: string}>}
 */
final class NewIdeasDigestMail
{
    private const TEXTS = [
        'en' => [
            'subject_one'   => 'New ideas on "{board}"',
            'subject_many'  => 'New ideas on your boards',
            'heading'       => 'New ideas this week',
            'board_line'    => '{board}: {count} new',
            'idea_line'     => '- {title}',
            'cta_one'       => 'Open the board',
            'cta_many'      => 'Open Votepit',
            'footer'        => 'You get this weekly because you asked for new ideas on boards you took part in.',
            'manage'        => 'Change how often you hear about this: {url}',
            'unsubscribe'   => 'Unsubscribe from new-ideas mails',
            'button_fallback' => 'If the button does not work, copy this link into your browser:',
        ],
        'de' => [
            'subject_one'   => 'Neue Ideen auf „{board}“', // export-ok: comment-language
            'subject_many'  => 'Neue Ideen auf deinen Boards', // export-ok: comment-language
            'heading'       => 'Neue Ideen diese Woche', // export-ok: comment-language
            'board_line'    => '{board}: {count} neu', // export-ok: comment-language
            'idea_line'     => '- {title}', // export-ok: comment-language
            'cta_one'       => 'Board öffnen', // export-ok: comment-language
            'cta_many'      => 'Votepit öffnen', // export-ok: comment-language
            'footer'        => 'Du bekommst das wöchentlich, weil du neue Ideen auf Boards angefordert hast, an denen du teilnimmst.', // export-ok: comment-language
            'manage'        => 'Wie oft du davon hörst, ändern: {url}', // export-ok: comment-language
            'unsubscribe'   => 'Von Mails zu neuen Ideen abmelden', // export-ok: comment-language
            'button_fallback' => 'Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:', // export-ok: comment-language
        ],
        // machine-drafted, needs native review
        'es' => [
            'subject_one'   => 'Nuevas ideas en "{board}"',
            'subject_many'  => 'Nuevas ideas en tus boards',
            'heading'       => 'Nuevas ideas esta semana',
            'board_line'    => '{board}: {count} nuevas',
            'idea_line'     => '- {title}',
            'cta_one'       => 'Abrir el board',
            'cta_many'      => 'Abrir Votepit',
            'footer'        => 'Recibes esto cada semana porque pediste nuevas ideas en boards en los que participas.',
            'manage'        => 'Cambia la frecuencia con la que recibes esto: {url}',
            'unsubscribe'   => 'Darse de baja de los correos de nuevas ideas',
            'button_fallback' => 'Si el botón no funciona, copia este enlace en tu navegador:',
        ],
        'fr' => [
            'subject_one'   => 'Nouvelles idées sur « {board} »',
            'subject_many'  => 'Nouvelles idées sur vos boards',
            'heading'       => 'Nouvelles idées cette semaine',
            'board_line'    => '{board} : {count} nouvelles',
            'idea_line'     => '- {title}',
            'cta_one'       => 'Ouvrir le board',
            'cta_many'      => 'Ouvrir Votepit',
            'footer'        => 'Vous recevez ceci chaque semaine parce que vous avez demandé les nouvelles idées des boards auxquels vous participez.',
            'manage'        => 'Modifier la fréquence de ces messages : {url}',
            'unsubscribe'   => 'Se désabonner des e-mails sur les nouvelles idées',
            'button_fallback' => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',
        ],
        // machine-drafted, needs native review
        'it' => [
            'subject_one'   => 'Nuove idee su "{board}"',
            'subject_many'  => 'Nuove idee sui tuoi board',
            'heading'       => 'Nuove idee questa settimana',
            'board_line'    => '{board}: {count} nuove',
            'idea_line'     => '- {title}',
            'cta_one'       => 'Apri il board',
            'cta_many'      => 'Apri Votepit',
            'footer'        => 'Ricevi questa mail ogni settimana perché hai chiesto nuove idee sui board a cui partecipi.',
            'manage'        => 'Cambia la frequenza con cui ricevi questi avvisi: {url}',
            'unsubscribe'   => 'Annulla l’iscrizione alle mail sulle nuove idee',
            'button_fallback' => 'Se il pulsante non funziona, copia questo link nel tuo browser:',
        ],
        // machine-drafted, needs native review
        'nl' => [
            'subject_one'   => 'Nieuwe ideeën op "{board}"',
            'subject_many'  => 'Nieuwe ideeën op je boards',
            'heading'       => 'Nieuwe ideeën deze week',
            'board_line'    => '{board}: {count} nieuw',
            'idea_line'     => '- {title}',
            'cta_one'       => 'Board openen',
            'cta_many'      => 'Votepit openen',
            'footer'        => 'Je ontvangt dit wekelijks omdat je om nieuwe ideeën hebt gevraagd op boards waaraan je deelneemt.',
            'manage'        => 'Wijzig hoe vaak je dit hoort: {url}',
            'unsubscribe'   => 'Afmelden voor mails over nieuwe ideeën',
            'button_fallback' => 'Werkt de knop niet, kopieer dan deze link naar je browser:',
        ],
    ];

    /**
     * @param list<IdeaSection> $sections at least one section
     * @return array{subject: string, text: string, html: string, image: \Votepit\Mail\InlineImage}
     */
    public static function render(
        string $language,
        array $sections,
        string $appUrl,
        string $prefsUrl,
        string $unsubscribeUrl,
        ?MailBrand $brand,
    ): array {
        $t     = self::TEXTS[$language] ?? self::TEXTS['en'];
        $one   = count($sections) === 1;
        $first = $sections[0];

        $paragraphs = [];
        foreach ($sections as $section) {
            $paragraphs[] = strtr($t['board_line'], [
                '{board}' => $section['board_name'],
                '{count}' => (string) count($section['ideas']),
            ]);
            foreach ($section['ideas'] as $idea) {
                $paragraphs[] = strtr($t['idea_line'], ['{title}' => $idea['title']]);
            }
        }

        $footer = [$t['footer'], strtr($t['manage'], ['{url}' => $prefsUrl])];

        $mail = MailTemplate::render(
            $t['heading'],
            $paragraphs,
            $one ? $first['board_url'] : $appUrl . '/',
            $one ? $t['cta_one'] : $t['cta_many'],
            $footer,
            ['label' => $t['unsubscribe'], 'url' => $unsubscribeUrl],
            $brand,
            buttonFallback: $t['button_fallback'],
        );

        return [
            'subject' => $one ? strtr($t['subject_one'], ['{board}' => $first['board_name']]) : $t['subject_many'],
            ...$mail,
        ];
    }
}
