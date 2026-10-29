<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * Verifier for minisign signatures (https://jedisct1.github.io/minisign/) on
 * top of PHP's sodium extension. Supports both signature algorithms:
 *
 *   "Ed" legacy: Ed25519 over the file bytes;
 *   "ED" prehashed: Ed25519 over the BLAKE2b-512 digest of the file bytes.
 *
 * The trusted comment is verified as well: its global signature covers the
 * file signature and the comment text, so a tampered comment fails too.
 *
 * Fail-secure: every malformed input returns false, never a partial result.
 */
final class Minisign
{
    private const ALGORITHM_LEGACY = 'Ed';
    private const ALGORITHM_PREHASHED = 'ED';
    private const TRUSTED_COMMENT_PREFIX = 'trusted comment: ';

    /** True when $keyLine decodes to a 42-byte minisign public key (Ed algorithm). */
    public static function isWellFormedPublicKey(string $keyLine): bool
    {
        return self::decodePublicKey($keyLine) !== null;
    }

    /**
     * Verifies $signatureText (the full content of a .minisig file) over $message
     * (the signed file's bytes) against one base64 public key line.
     */
    public static function verify(string $message, string $signatureText, string $publicKeyLine): bool
    {
        $key = self::decodePublicKey($publicKeyLine);
        if ($key === null) {
            return false;
        }

        $lines = preg_split('/\r?\n/', trim($signatureText));
        if (!is_array($lines) || count($lines) < 4 || !str_starts_with($lines[2], self::TRUSTED_COMMENT_PREFIX)) {
            return false;
        }

        $sigBytes = self::b64($lines[1]);
        if ($sigBytes === null || strlen($sigBytes) !== 74) {
            return false;
        }

        $algorithm = substr($sigBytes, 0, 2);
        $keyId     = substr($sigBytes, 2, 8);
        $signature = substr($sigBytes, 10, 64);
        if (($algorithm !== self::ALGORITHM_LEGACY && $algorithm !== self::ALGORITHM_PREHASHED) || $keyId !== $key['keyId']) {
            return false;
        }

        $signed = $algorithm === self::ALGORITHM_PREHASHED
            ? sodium_crypto_generichash($message, '', 64)
            : $message;

        if (!self::detachedVerify($signature, $signed, $key['publicKey'])) {
            return false;
        }

        $trustedComment = substr($lines[2], strlen(self::TRUSTED_COMMENT_PREFIX));
        $globalSignature = self::b64($lines[3]);
        if ($globalSignature === null || strlen($globalSignature) !== 64) {
            return false;
        }

        return self::detachedVerify($globalSignature, $signature . $trustedComment, $key['publicKey']);
    }

    /** @return array{keyId: string, publicKey: string}|null */
    private static function decodePublicKey(string $keyLine): ?array
    {
        $lines = preg_split('/\r?\n/', trim($keyLine));
        $line  = is_array($lines) ? trim((string) end($lines)) : '';
        $bytes = self::b64($line);
        if ($bytes === null || strlen($bytes) !== 42 || substr($bytes, 0, 2) !== self::ALGORITHM_LEGACY) {
            return null;
        }

        return ['keyId' => substr($bytes, 2, 8), 'publicKey' => substr($bytes, 10, 32)];
    }

    private static function detachedVerify(string $signature, string $message, string $publicKey): bool
    {
        if ($signature === '' || $publicKey === '') {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    private static function b64(string $value): ?string
    {
        $decoded = base64_decode(trim($value), true);

        return $decoded === false ? null : $decoded;
    }
}
