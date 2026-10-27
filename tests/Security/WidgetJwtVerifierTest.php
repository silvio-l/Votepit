<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\WidgetJwtVerifier;

/**
 * Identify-JWT verification of the embeddable widget (ADR 0005 §3): HS256
 * only, constant-time signature check, aud = board, exp ≤ 10 min after
 * iat, bounded clock skew. Every negative case must yield null — there is
 * no partial trust.
 */
final class WidgetJwtVerifierTest extends TestCase
{
    private const SECRET = 'board-secret-0123456789abcdef0123456789abcdef';
    private const NOW    = 1_800_000_000;

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $header
     */
    private static function sign(array $payload, array $header = ['alg' => 'HS256', 'typ' => 'JWT'], string $secret = self::SECRET): string
    {
        $h = self::b64((string) json_encode($header));
        $p = self::b64((string) json_encode($payload));

        return $h . '.' . $p . '.' . self::b64(hash_hmac('sha256', $h . '.' . $p, $secret, true));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function claims(array $overrides = []): array
    {
        return array_merge([
            'email' => 'jane@customer.example',
            'name'  => 'Jane',
            'sub'   => 'cust-42',
            'aud'   => WidgetJwtVerifier::audienceFor(7),
            'iat'   => self::NOW,
            'exp'   => self::NOW + 300,
        ], $overrides);
    }

    /** @return array{email: string, name: string|null, sub: string|null}|null */
    private function verify(string $jwt): ?array
    {
        return (new WidgetJwtVerifier())->verify($jwt, self::SECRET, WidgetJwtVerifier::audienceFor(7), self::NOW);
    }

    public function test_valid_token_yields_the_identity(): void
    {
        self::assertSame(
            ['email' => 'jane@customer.example', 'name' => 'Jane', 'sub' => 'cust-42'],
            $this->verify(self::sign(self::claims())),
        );
    }

    public function test_audience_array_containing_the_board_is_accepted(): void
    {
        self::assertNotNull($this->verify(self::sign(self::claims(['aud' => ['other', WidgetJwtVerifier::audienceFor(7)]]))));
    }

    public function test_name_and_sub_are_optional_and_control_characters_are_stripped(): void
    {
        $result = $this->verify(self::sign(self::claims(['name' => "Ja\x00ne\n", 'sub' => null])));
        self::assertSame('Jane', $result['name'] ?? null);
        self::assertNull($result['sub'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function forgedTokens(): iterable
    {
        $valid = self::claims();
        $h     = self::b64((string) json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $p     = self::b64((string) json_encode($valid));

        yield 'alg none, empty signature'        => [$h . '.' . $p . '.'];
        yield 'alg none, garbage signature'      => [$h . '.' . $p . '.abc'];
        yield 'alg HS512'                        => [self::sign($valid, ['alg' => 'HS512'])];
        yield 'alg RS256'                        => [self::sign($valid, ['alg' => 'RS256'])];
        yield 'alg lowercase hs256'              => [self::sign($valid, ['alg' => 'hs256'])];
        yield 'no alg'                           => [self::sign($valid, ['typ' => 'JWT'])];
        yield 'wrong secret'                     => [self::sign($valid, secret: 'another-secret')];
        yield 'two segments'                     => [implode('.', array_slice(explode('.', self::sign($valid)), 0, 2))];
        yield 'four segments'                    => [self::sign($valid) . '.x'];
        yield 'padded base64 signature'          => [self::sign($valid) . '='];
        yield 'trailing newline'                 => [self::sign($valid) . "\n"];
        yield 'empty'                            => [''];
        yield 'oversized'                        => [str_repeat('a', 5000)];
    }

    #[DataProvider('forgedTokens')]
    public function test_forged_or_malformed_tokens_are_rejected(string $jwt): void
    {
        self::assertNull($this->verify($jwt));
    }

    public function test_tampered_payload_is_rejected(): void
    {
        [$h, , $s] = explode('.', self::sign(self::claims()));
        $p         = self::b64((string) json_encode(self::claims(['email' => 'attacker@evil.example'])));

        self::assertNull($this->verify($h . '.' . $p . '.' . $s));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidClaims(): iterable
    {
        yield 'expired beyond skew'           => [self::claims(['iat' => self::NOW - 500, 'exp' => self::NOW - 61])];
        yield 'lifetime over 10 minutes'      => [self::claims(['exp' => self::NOW + 601])];
        yield 'iat in the future beyond skew' => [self::claims(['iat' => self::NOW + 61, 'exp' => self::NOW + 300])];
        yield 'exp before iat'                => [self::claims(['iat' => self::NOW, 'exp' => self::NOW - 1])];
        yield 'missing exp'                   => [array_diff_key(self::claims(), ['exp' => true])];
        yield 'missing iat'                   => [array_diff_key(self::claims(), ['iat' => true])];
        yield 'string exp'                    => [self::claims(['exp' => (string) (self::NOW + 300)])];
        yield 'wrong audience (other board)'  => [self::claims(['aud' => WidgetJwtVerifier::audienceFor(8)])];
        yield 'missing audience'              => [array_diff_key(self::claims(), ['aud' => true])];
        yield 'audience prefix only'          => [self::claims(['aud' => 'votepit:board:'])];
        yield 'missing email'                 => [array_diff_key(self::claims(), ['email' => true])];
        yield 'invalid email'                 => [self::claims(['email' => 'not-an-email'])];
        yield 'email not a string'            => [self::claims(['email' => ['a@b.example']])];
        yield 'name too long'                 => [self::claims(['name' => str_repeat('x', 101)])];
        yield 'name not a string'             => [self::claims(['name' => 42])];
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('invalidClaims')]
    public function test_invalid_claims_are_rejected(array $claims): void
    {
        self::assertNull($this->verify(self::sign($claims)));
    }

    public function test_exactly_ten_minutes_lifetime_is_accepted(): void
    {
        self::assertNotNull($this->verify(self::sign(self::claims(['exp' => self::NOW + 600]))));
    }

    public function test_empty_secret_never_verifies(): void
    {
        $jwt = self::sign(self::claims(), secret: '');
        self::assertNull((new WidgetJwtVerifier())->verify($jwt, '', WidgetJwtVerifier::audienceFor(7), self::NOW));
    }
}
