<?php

declare(strict_types=1);

namespace HashOver\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Passwords, e-mail encryption and login tokens
 */
final class CryptoTest extends TestCase
{
	public function testEmailEncryptionRoundTripsWithRandomNonce(): void
	{
		$a = encrypt_email('alice@example.org');
		$b = encrypt_email('alice@example.org');

		$this->assertNotSame($a, $b);
		$this->assertSame('alice@example.org', decrypt_email($a));
		$this->assertSame('', encrypt_email(''));
	}

	public function testTamperedCiphertextIsRejected(): void
	{
		$stored = encrypt_email('alice@example.org');
		$data = base64_decode(substr($stored, 3), true);
		$this->assertIsString($data);
		$data[strlen($data) - 1] = chr(ord($data[strlen($data) - 1]) ^ 1);

		$this->assertSame('', decrypt_email('v2:' . base64_encode($data)));
	}

	public function testOpenSslFallbackCanBeDecrypted(): void
	{
		$key = derived_key('email');
		$iv = random_bytes(12);
		$ciphertext = openssl_encrypt('bob@example.org', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

		$this->assertSame('bob@example.org', decrypt_email('v2g:' . base64_encode($iv . $tag . $ciphertext)));
	}

	public function testLegacyEmailsCanBeDecrypted(): void
	{
		$this->assertSame('old@example.org', decrypt_email(legacy_xor('old@example.org')));
		$this->assertSame('', decrypt_email('garbage'));
	}

	public function testPasswordHashing(): void
	{
		$hash = hash_password('secret');

		$this->assertTrue(verify_password('secret', $hash));
		$this->assertFalse(verify_password('wrong', $hash));
		$this->assertFalse(verify_password('', $hash));
		$this->assertFalse(password_needs_upgrade($hash));
		$this->assertSame('', hash_password(''));
	}

	public function testLegacyPasswordHashes(): void
	{
		$legacy = md5(legacy_xor('secret'));

		$this->assertTrue(verify_password('secret', $legacy));
		$this->assertFalse(verify_password('wrong', $legacy));
		$this->assertTrue(password_needs_upgrade($legacy));
		$this->assertFalse(verify_password('secret', ''));
	}

	public function testLoginTokens(): void
	{
		$token = login_token('Alice', 'pw');

		$this->assertSame($token, login_token(' alice ', 'pw'));
		$this->assertNotSame($token, login_token('Alice', 'other'));
		$this->assertNotSame($token, login_token('Bob', 'pw'));
		$this->assertNotSame($token, login_verifier($token));
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', login_verifier($token));
	}

	public function testAdminPassword(): void
	{
		$this->assertTrue(is_admin_password('adminpw'));
		$this->assertFalse(is_admin_password('adminpw '));
		$this->assertFalse(is_admin_password(''));

		$GLOBALS['admin_password'] = password_hash('hashed-admin', PASSWORD_DEFAULT);

		try {
			$this->assertTrue(is_admin_password('hashed-admin'));
			$this->assertFalse(is_admin_password('adminpw'));
		} finally {
			$GLOBALS['admin_password'] = 'adminpw';
		}
	}

	public function testAdminTokenChangesWithCredentials(): void
	{
		$token = admin_token();
		$GLOBALS['admin_password'] = 'changed';

		try {
			$this->assertNotSame($token, admin_token());
		} finally {
			$GLOBALS['admin_password'] = 'adminpw';
		}
	}
}
