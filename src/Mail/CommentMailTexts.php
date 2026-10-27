<?php

declare(strict_types=1);

namespace Votepit\Mail;

/**
 * Copy of the instant comment / thread-reply e-mail (subject, first line,
 * button, unsubscribe link) per language. The in-app notification keeps its
 * own English text; only the mail is localised, in the recipient's language.
 *
 * @phpstan-type CommentNotice array{title: string, body: string}
 */
final class CommentMailTexts
{
    private const TEXTS = [
        'en' => [
            'idea_comment_title' => 'New comment on your idea',
            'idea_comment_body'  => 'Someone commented on "{title}".',
            'thread_reply_title' => 'New reply in a thread you commented on',
            'thread_reply_body'  => 'There\'s a new comment on "{title}".',
            'cta'                => 'View on Votepit',
            'unsubscribe'        => 'Unsubscribe from these updates',
            'button_fallback'    => 'If the button does not work, copy this link into your browser:',
        ],
        'de' => [
            'idea_comment_title' => 'Neuer Kommentar zu deiner Idee', // export-ok: comment-language
            'idea_comment_body'  => 'Jemand hat zu „{title}“ kommentiert.', // export-ok: comment-language
            'thread_reply_title' => 'Neue Antwort in einem Thread, in dem du kommentiert hast', // export-ok: comment-language
            'thread_reply_body'  => 'Es gibt einen neuen Kommentar zu „{title}“.', // export-ok: comment-language
            'cta'                => 'Auf Votepit ansehen', // export-ok: comment-language
            'unsubscribe'        => 'Von diesen Neuigkeiten abmelden', // export-ok: comment-language
            'button_fallback'    => 'Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:', // export-ok: comment-language
        ],
        // machine-drafted, needs native review
        'es' => [
            'idea_comment_title' => 'Nuevo comentario en tu idea',
            'idea_comment_body'  => 'Alguien comentó en "{title}".',
            'thread_reply_title' => 'Nueva respuesta en un hilo en el que comentaste',
            'thread_reply_body'  => 'Hay un nuevo comentario en "{title}".',
            'cta'                => 'Ver en Votepit',
            'unsubscribe'        => 'Darse de baja de estas novedades',
            'button_fallback'    => 'Si el botón no funciona, copia este enlace en tu navegador:',
        ],
        // machine-drafted, needs native review
        'fr' => [
            'idea_comment_title' => 'Nouveau commentaire sur votre idée',
            'idea_comment_body'  => 'Quelqu’un a commenté « {title} ».',
            'thread_reply_title' => 'Nouvelle réponse dans un fil où vous avez commenté',
            'thread_reply_body'  => 'Un nouveau commentaire a été ajouté sur « {title} ».',
            'cta'                => 'Voir sur Votepit',
            'unsubscribe'        => 'Se désabonner de ces mises à jour',
            'button_fallback'    => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',
        ],
        // machine-drafted, needs native review
        'it' => [
            'idea_comment_title' => 'Nuovo commento sulla tua idea',
            'idea_comment_body'  => 'Qualcuno ha commentato "{title}".',
            'thread_reply_title' => 'Nuova risposta in un thread in cui hai commentato',
            'thread_reply_body'  => 'C’è un nuovo commento su "{title}".',
            'cta'                => 'Vedi su Votepit',
            'unsubscribe'        => 'Annulla l’iscrizione da questi aggiornamenti',
            'button_fallback'    => 'Se il pulsante non funziona, copia questo link nel tuo browser:',
        ],
        // machine-drafted, needs native review
        'nl' => [
            'idea_comment_title' => 'Nieuwe reactie op je idee',
            'idea_comment_body'  => 'Iemand heeft gereageerd op "{title}".',
            'thread_reply_title' => 'Nieuw antwoord in een draad waarin je hebt gereageerd',
            'thread_reply_body'  => 'Er is een nieuwe reactie op "{title}".',
            'cta'                => 'Bekijken op Votepit',
            'unsubscribe'        => 'Afmelden voor deze updates',
            'button_fallback'    => 'Werkt de knop niet, kopieer dan deze link naar je browser:',
        ],
    ];

    /** @return CommentNotice */
    public static function ideaComment(string $language, string $ideaTitle): array
    {
        $t = self::texts($language);

        return [
            'title' => $t['idea_comment_title'],
            'body'  => strtr($t['idea_comment_body'], ['{title}' => $ideaTitle]),
        ];
    }

    /** @return CommentNotice */
    public static function threadReply(string $language, string $ideaTitle): array
    {
        $t = self::texts($language);

        return [
            'title' => $t['thread_reply_title'],
            'body'  => strtr($t['thread_reply_body'], ['{title}' => $ideaTitle]),
        ];
    }

    public static function cta(string $language): string
    {
        return self::texts($language)['cta'];
    }

    public static function unsubscribeLabel(string $language): string
    {
        return self::texts($language)['unsubscribe'];
    }

    public static function buttonFallback(string $language): string
    {
        return self::texts($language)['button_fallback'];
    }

    /** @return array<string, string> */
    private static function texts(string $language): array
    {
        return self::TEXTS[MailLanguage::resolve($language)];
    }
}
