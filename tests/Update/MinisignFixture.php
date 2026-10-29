<?php

declare(strict_types=1);

namespace Votepit\Tests\Update;

/**
 * Throwaway minisign keys and signature files for tests. Real release keys are
 * never generated in the repository or in test code.
 */
final class MinisignFixture
{
    /** @return array{publicKeyLine: string, secretKey: string} */
    public static function keyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'publicKeyLine' => base64_encode('Ed' . random_bytes(8) . sodium_crypto_sign_publickey($pair)),
            'secretKey'     => sodium_crypto_sign_secretkey($pair),
        ];
    }

    /**
     * Signature file text over $message, in the prehashed ("ED") or the legacy ("Ed") form.
     *
     * @param array{publicKeyLine: string, secretKey: string} $pair
     */
    public static function sign(string $message, array $pair, bool $prehashed = true): string
    {
        // The key ID sits in the public key line (bytes 2..10); recover it so the signature carries it.
        $publicKeyBytes = base64_decode($pair['publicKeyLine'], true);
        $secret         = $pair['secretKey'];
        if ($publicKeyBytes === false || $secret === '') {
            throw new \LogicException('fixture key is malformed');
        }
        $keyId = substr($publicKeyBytes, 2, 8);

        $signed    = $prehashed ? sodium_crypto_generichash($message, '', 64) : $message;
        $signature = sodium_crypto_sign_detached($signed, $secret);
        $comment   = 'timestamp:0 file:votepit-community';
        $global    = sodium_crypto_sign_detached($signature . $comment, $secret);

        return "untrusted comment: signature from the test key\n"
            . base64_encode(($prehashed ? 'ED' : 'Ed') . $keyId . $signature) . "\n"
            . 'trusted comment: ' . $comment . "\n"
            . base64_encode($global) . "\n";
    }
}
