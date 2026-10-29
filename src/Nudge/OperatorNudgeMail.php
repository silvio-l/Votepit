<?php

declare(strict_types=1);

namespace Votepit\Nudge;

use Votepit\Mail\InlineImage;
use Votepit\Mail\MailTemplate;
use Votepit\Update\CommunityEdition;

/**
 * Renders the operator nudge mails (de/en) through the shared MailTemplate.
 * Sender is Votepit (no board branding): these mails are about the recipient's
 * own account, not about board content.
 *
 * Copy rules: plain, one concrete next step (one button), no false urgency,
 * no "consensus/majority" claims. Board/account names are customer content;
 * they only reach the HTML part through MailTemplate, which escapes them.
 */
final class OperatorNudgeMail
{
    private const TEXTS = [
        'en' => [
            'onboarding_first_idea' => [
                'subject'     => 'Your board "{board}" is waiting for its first idea',
                'heading'     => 'Share your board',
                'paragraphs'  => [
                    'Your board "{board}" is set up, but nobody has posted an idea on it yet.',
                    'The quickest way to get the first one: send the board link to a few people who use your product, or embed the feedback widget on your website. Ideas start to come in once people can see the board.',
                ],
                'cta'         => 'Open your board',
                'unsubscribe' => 'Stop onboarding tips',
            ],
            'onboarding_first_vote' => [
                'subject'     => 'Has anyone outside your team voted on "{board}" yet?',
                'heading'     => 'Get a vote from outside your team',
                'paragraphs'  => [
                    'So far only your team has voted on "{board}". Votes from people outside your team show what matters to your users.',
                    'Try a different angle: ask one user directly which idea they would pick first, or post the link where your users already talk to you.',
                ],
                'cta'         => 'Open your board',
                'unsubscribe' => 'Stop onboarding tips',
            ],
            'quiet_board' => [
                'subject'     => 'Nothing new on "{board}" for three weeks',
                'heading'     => 'Get your board moving again',
                'paragraphs'  => [
                    'There has been no new idea, vote or comment on "{board}" for 21 days.',
                    'Three ways to get it going again: share the board link once more, place the feedback widget where your users are, or open a new round with one clear question.',
                ],
                'cta'         => 'Open your board',
                'unsubscribe' => 'Stop quiet-board tips',
            ],
            'plan_limit' => [
                'subject'     => '{account} has reached {percent} % of its {metric} limit',
                'heading'     => 'You are close to a plan limit',
                'paragraphs'  => [
                    'Your {plan} plan includes {limit} {metric}. You are using {used} of them ({percent} %).',
                    'Once the limit is reached you cannot add more. Existing boards and ideas stay as they are.',
                ],
                'cta'         => 'Review your plan',
                'unsubscribe' => 'Stop plan-limit heads-ups',
                'metric'      => ['boards' => 'boards', 'members' => 'members'],
            ],
            'update_available' => [
                'subject'     => 'Votepit {version} is available',
                'heading'     => 'A new Votepit Community release is available',
                'paragraphs'  => [
                    'Version {version} has been released. Your installation runs {installed}.',
                    'Read the release notes first. The upgrade guide describes the steps and the backup to take before you start: {docs}',
                ],
                'cta'         => 'Read the release notes',
                'unsubscribe' => 'Stop update notices',
            ],
        ],
        'de' => [
            'onboarding_first_idea' => [
                'subject'     => 'Dein Board „{board}“ wartet auf die erste Idee', // export-ok: comment-language
                'heading'     => 'Teile dein Board',
                'paragraphs'  => [
                    'Dein Board „{board}“ ist eingerichtet, aber bisher hat niemand eine Idee dort eingereicht.', // export-ok: comment-language
                    'Am schnellsten geht es, wenn du den Link an ein paar Leute schickst, die dein Produkt nutzen, oder das Feedback-Widget auf deiner Website einbindest. Ideen kommen erfahrungsgemäß an, sobald das Board sichtbar ist.', // export-ok: comment-language
                ],
                'cta'         => 'Board öffnen', // export-ok: comment-language
                'unsubscribe' => 'Onboarding-Tipps abbestellen', // export-ok: comment-language
            ],
            'onboarding_first_vote' => [
                'subject'     => 'Hat schon jemand außerhalb deines Teams auf „{board}“ abgestimmt?', // export-ok: comment-language
                'heading'     => 'Hol dir eine Stimme von außerhalb deines Teams', // export-ok: comment-language
                'paragraphs'  => [
                    'Bisher hat nur dein Team auf „{board}“ abgestimmt. Stimmen von Leuten außerhalb des Teams zeigen, was deinen Nutzern wichtig ist.', // export-ok: comment-language
                    'Probier einen anderen Weg: Frag einen Nutzer direkt, welche Idee er zuerst wählen würde, oder teile den Link dort, wo deine Nutzer ohnehin mit dir sprechen.', // export-ok: comment-language
                ],
                'cta'         => 'Board öffnen', // export-ok: comment-language
                'unsubscribe' => 'Onboarding-Tipps abbestellen', // export-ok: comment-language
            ],
            'quiet_board' => [
                'subject'     => 'Auf „{board}“ ist seit drei Wochen nichts passiert', // export-ok: comment-language
                'heading'     => 'Bring dein Board wieder in Gang', // export-ok: comment-language
                'paragraphs'  => [
                    'Seit 21 Tagen gab es auf „{board}“ keine neue Idee, Stimme oder Kommentar.', // export-ok: comment-language
                    'Drei Wege, es wieder in Gang zu bringen: den Link noch einmal teilen, das Feedback-Widget dort platzieren, wo deine Nutzer sind, oder mit einer klaren Frage eine neue Runde starten.', // export-ok: comment-language
                ],
                'cta'         => 'Board öffnen', // export-ok: comment-language
                'unsubscribe' => 'Hinweise zu ruhenden Boards abbestellen', // export-ok: comment-language
            ],
            'plan_limit' => [
                'subject'     => '{account} hat {percent} % des Limits für {metric} erreicht', // export-ok: comment-language
                'heading'     => 'Du bist nah an einem Tarif-Limit', // export-ok: comment-language
                'paragraphs'  => [
                    'Dein Tarif {plan} umfasst {limit} {metric}. Du nutzt davon {used} ({percent} %).', // export-ok: comment-language
                    'Ist das Limit erreicht, kannst du keine weiteren hinzufügen. Bestehende Boards und Ideen bleiben unverändert.', // export-ok: comment-language
                ],
                'cta'         => 'Tarif ansehen', // export-ok: comment-language
                'unsubscribe' => 'Hinweise zum Tarif-Limit abbestellen', // export-ok: comment-language
                'metric'      => ['boards' => 'Boards', 'members' => 'Mitglieder'], // export-ok: comment-language
            ],
            'update_available' => [
                'subject'     => 'Votepit {version} ist verfügbar', // export-ok: comment-language
                'heading'     => 'Eine neue Votepit-Community-Version ist verfügbar', // export-ok: comment-language
                'paragraphs'  => [
                    'Version {version} ist erschienen. Auf deiner Installation läuft {installed}.', // export-ok: comment-language
                    'Lies zuerst die Versionshinweise. Der Upgrade-Leitfaden beschreibt die Schritte und das Backup, das du vorher machen solltest: {docs}', // export-ok: comment-language
                ],
                'cta'         => 'Versionshinweise lesen', // export-ok: comment-language
                'unsubscribe' => 'Update-Hinweise abbestellen', // export-ok: comment-language
            ],
        ],
    ];

    /**
     * @return array{subject: string, text: string, html: string, image: InlineImage}
     */
    public static function onboardingFirstIdea(string $language, string $board, string $boardUrl, string $account, string $unsubscribeUrl, string $profileUrl): array
    {
        return self::build($language, 'onboarding_first_idea', ['board' => $board], $boardUrl, $account, $unsubscribeUrl, $profileUrl);
    }

    /**
     * @return array{subject: string, text: string, html: string, image: InlineImage}
     */
    public static function onboardingFirstVote(string $language, string $board, string $boardUrl, string $account, string $unsubscribeUrl, string $profileUrl): array
    {
        return self::build($language, 'onboarding_first_vote', ['board' => $board], $boardUrl, $account, $unsubscribeUrl, $profileUrl);
    }

    /**
     * @return array{subject: string, text: string, html: string, image: InlineImage}
     */
    public static function quietBoard(string $language, string $board, string $boardUrl, string $account, string $unsubscribeUrl, string $profileUrl): array
    {
        return self::build($language, 'quiet_board', ['board' => $board], $boardUrl, $account, $unsubscribeUrl, $profileUrl);
    }

    /**
     * @return array{subject: string, text: string, html: string, image: InlineImage}
     */
    public static function planLimit(
        string $language,
        string $account,
        string $plan,
        string $metric,
        int $limit,
        int $used,
        float $percent,
        string $billingUrl,
        string $unsubscribeUrl,
        string $profileUrl,
    ): array {
        $t        = self::TEXTS[$language] ?? self::TEXTS['en'];
        $metricLabel = $t['plan_limit']['metric'][$metric] ?? $metric;
        $vars = [
            'account' => $account,
            'plan'    => ucfirst($plan),
            'metric'  => $metricLabel,
            'limit'   => (string) $limit,
            'used'    => (string) $used,
            'percent' => (string) (int) floor($percent),
        ];

        return self::build($language, 'plan_limit', $vars, $billingUrl, $account, $unsubscribeUrl, $profileUrl);
    }

    /**
     * @return array{subject: string, text: string, html: string, image: InlineImage}
     */
    public static function updateAvailable(string $language, string $installed, string $latest, string $account, string $unsubscribeUrl, string $profileUrl): array
    {
        return self::build(
            $language,
            'update_available',
            ['installed' => $installed, 'version' => $latest, 'docs' => CommunityEdition::UPGRADE_DOCS_URL],
            sprintf(CommunityEdition::RELEASE_PAGE_URL, 'v' . $latest),
            $account,
            $unsubscribeUrl,
            $profileUrl,
        );
    }

    /**
     * @param array<string, string> $vars
     * @return array{subject: string, text: string, html: string, image: InlineImage}
     */
    private static function build(string $language, string $key, array $vars, string $ctaUrl, string $account, string $unsubscribeUrl, string $profileUrl): array
    {
        $t = self::TEXTS[$language] ?? self::TEXTS['en'];
        $c = $t[$key];

        $replacements = [];
        foreach ($vars as $name => $value) {
            $replacements['{' . $name . '}'] = $value;
        }
        $fill = static fn (string $text): string => strtr($text, $replacements);

        $mail = MailTemplate::render(
            $c['heading'],
            array_map($fill, $c['paragraphs']),
            $ctaUrl,
            $c['cta'],
            [
                self::ownerLine($language, $account),
                ($language === 'de' ? 'Ändern kannst du das in deinem Profil: ' : 'Change it in your profile: ') . $profileUrl,  // export-ok: comment-language
            ],
            ['label' => $c['unsubscribe'], 'url' => $unsubscribeUrl],
            buttonFallback: $language === 'de' ? 'Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:' : null, // export-ok: comment-language
        );

        return ['subject' => $fill($c['subject']), ...$mail];
    }

    private static function ownerLine(string $language, string $account): string
    {
        return $language === 'de'
            ? 'Du erhältst diese Hinweise, weil du den Account „' . $account . '“ auf Votepit besitzt.' // export-ok: comment-language
            : 'You receive these tips because you own the account "' . $account . '" on Votepit.';
    }
}
