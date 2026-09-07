<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Symmetric encryption for at-rest secrets (e.g. SMTP password,
 * abuse-report reporter email).
 *
 * Algorithm: XSalsa20-Poly1305 via sodium_crypto_secretbox.
 * Key derivation: HKDF-SHA256 from app_key → 32-byte subkey (context string,
 * default 'smtp' — key separation between different at-rest secrets
 * within the same app_key; a different $context derives a completely
 * different subkey, NO plaintext leak between the use cases).
 * Format: base64(nonce[24] + ciphertext).
 */
final readonly class EncryptionService
{
    private string $key;

    public function __construct(string $appKey, string $context = 'smtp')
    {
        // app_key can be any non-empty string (hex or plaintext).
        // SHA-256 normalizes the entropy to 32 bytes; HKDF derives from it
        // a dedicated subkey (key separation from the session/CSRF key).
        $this->key = hash_hkdf('sha256', hash('sha256', $appKey, true), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $context);
    }

    /**
     * Encrypts a plaintext string. Returns a base64-encoded blob.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    /**
     * Decrypts a blob (from encrypt()). Returns null on tampering attempt.
     */
    public function decrypt(string $encrypted): ?string
    {
        $decoded = sodium_base642bin($encrypted, SODIUM_BASE64_VARIANT_ORIGINAL);
        if (strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce      = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $result     = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        return $result === false ? null : $result;
    }
}
