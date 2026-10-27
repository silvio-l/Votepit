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
 * Format: "v1:" + base64(nonce[24] + ciphertext).
 *
 * The "v1:" tag (OWASP/BSI audit remediation issue 09 — crypto-output
 * versioning) lets a future algorithm change add a "v2:"-tagged branch to
 * decrypt() while v1 ciphertexts keep decrypting unchanged, instead of a
 * big-bang re-encryption migration. A blob with NO recognized tag is
 * treated as pre-versioning v1 data (every ciphertext ever written by this
 * class before the tag existed) — decrypt() falls back to the v1 path for
 * it. An unrecognized tag (e.g. a downgrade after a future v2 rollout)
 * fails closed (null) rather than guessing.
 */
final readonly class EncryptionService
{
    private const V1_TAG = 'v1:';

    private string $key;

    public function __construct(string $appKey, string $context = 'smtp')
    {
        // app_key can be any non-empty string (hex or plaintext).
        // SHA-256 normalizes the entropy to 32 bytes; HKDF derives from it
        // a dedicated subkey (key separation from the session/CSRF key).
        $this->key = hash_hkdf('sha256', hash('sha256', $appKey, true), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $context);
    }

    /**
     * Encrypts a plaintext string. Returns a "v1:"-tagged, base64-encoded blob.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return self::V1_TAG . sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    /**
     * Decrypts a blob (from encrypt(), tagged or legacy untagged). Returns
     * null on tampering, a malformed blob, or an unrecognized version tag.
     */
    public function decrypt(string $encrypted): ?string
    {
        if (str_starts_with($encrypted, self::V1_TAG)) {
            return $this->decryptV1(substr($encrypted, strlen(self::V1_TAG)));
        }

        // No recognized tag: could be a colon-free legacy blob (pre-issue-09
        // data) OR a tag from a version this build doesn't know — base64
        // (SODIUM_BASE64_VARIANT_ORIGINAL) never contains ':', so any other
        // tag would show up as a literal "vN:" prefix here. Fail closed
        // instead of guessing for anything that looks like an unknown tag.
        if (str_contains($encrypted, ':')) {
            return null;
        }

        return $this->decryptV1($encrypted);
    }

    private function decryptV1(string $base64): ?string
    {
        try {
            $decoded = sodium_base642bin($base64, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            // Malformed base64 — pre-existing edge case surfaced while
            // adding the version-tag dispatch above: sodium_base642bin()
            // throws rather than returning false, which decrypt()'s own
            // "returns null" contract never accounted for.
            return null;
        }
        if (strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce      = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $result     = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        return $result === false ? null : $result;
    }
}
