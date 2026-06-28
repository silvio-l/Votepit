<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Symmetrische Verschlüsselung für at-rest-Secrets (z. B. SMTP-Passwort).
 *
 * Algorithmus: XSalsa20-Poly1305 via sodium_crypto_secretbox.
 * Key-Ableitung: HKDF-SHA256 aus app_key → 32-Byte Subkey (Kontext 'smtp').
 * Format: base64(nonce[24] + ciphertext).
 */
final readonly class EncryptionService
{
    private string $key;

    public function __construct(string $appKey)
    {
        // app_key ist 64-stelliger Hex-String = 32 Bytes.
        // HKDF-SHA256 leitet einen dedizierten 32-Byte-Key ab (Key-Separation).
        $raw = hex2bin($appKey);
        if ($raw === false) {
            throw new \InvalidArgumentException('app_key muss ein valider Hex-String sein.');
        }
        $this->key = hash_hkdf('sha256', $raw, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'smtp');
    }

    /**
     * Verschlüsselt einen Plaintext-String. Liefert base64-codierten Blob.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    /**
     * Entschlüsselt einen Blob (aus encrypt()). Liefert null bei Manipulationsversuch.
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
