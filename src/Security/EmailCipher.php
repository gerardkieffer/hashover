<?php

declare(strict_types=1);

namespace HashOver\Security;

/**
 * Authenticated encryption of stored e-mail addresses (XSalsa20-Poly1305).
 */
final readonly class EmailCipher
{
    public function __construct(
        private Keys $keys,
    ) {}

    public function encrypt(string $email): ?string
    {
        if ($email === '') {
            return null;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return sodium_bin2base64($nonce . sodium_crypto_secretbox($email, $nonce, $this->keys->for('email')), SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    /**
     * Keyed hash of an address, to recognize it without decrypting stored
     * addresses; null for no address
     */
    public function fingerprint(string $email): ?string
    {
        $email = strtolower(trim($email));

        return $email === '' ? null : $this->keys->mac('email-fingerprint', $email);
    }

    /** The decrypted address, or an empty string if it is missing or was tampered with */
    public function decrypt(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        try {
            $data = sodium_base642bin($stored, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            return '';
        }

        if (strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $email = sodium_crypto_secretbox_open(
            substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->keys->for('email'),
        );

        return $email === false ? '' : $email;
    }
}
