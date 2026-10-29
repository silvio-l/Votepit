<?php

declare(strict_types=1);

namespace Votepit\Tests\Nudge;

use PHPUnit\Framework\TestCase;
use Votepit\Nudge\OperatorNudgeMail;

/** The fallback line under the button follows the nudge's language. */
final class OperatorNudgeMailTest extends TestCase
{
    public function test_button_fallback_line_follows_the_mail_language(): void
    {
        $de = OperatorNudgeMail::onboardingFirstIdea('de', 'Board', 'https://app.test/acme/board', 'Acme', 'https://app.test/u', 'https://app.test/profile');
        self::assertStringContainsString('Falls der Button nicht funktioniert', $de['html']); // export-ok: comment-language
        self::assertStringNotContainsString('If the button does not work', $de['html']);

        $en = OperatorNudgeMail::onboardingFirstIdea('en', 'Board', 'https://app.test/acme/board', 'Acme', 'https://app.test/u', 'https://app.test/profile');
        self::assertStringContainsString('If the button does not work', $en['html']);
    }
}
