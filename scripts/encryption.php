<?php

declare(strict_types=1);

	// Copyright (C) 2014-2019 Jacob Barkdull
	//
	//	This program is free software: you can redistribute it and/or modify
	//	it under the terms of the GNU Affero General Public License as
	//	published by the Free Software Foundation, either version 3 of the
	//	License, or (at your option) any later version.
	//
	//	This program is distributed in the hope that it will be useful,
	//	but WITHOUT ANY WARRANTY; without even the implied warranty of
	//	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	//	GNU Affero General Public License for more details.
	//
	//	You should have received a copy of the GNU Affero General Public License
	//	along with this program.  If not, see <http://www.gnu.org/licenses/>.


	// Prefix identifying e-mails encrypted with authenticated encryption
	const EMAIL_CIPHER_PREFIX = 'v2:';

	// Legacy XOR obfuscation used by HashOver 1.0.x for e-mails and passwords,
	// only kept to read and upgrade existing comment files
	function legacy_xor(string $string): string
	{
		global $encryption_key;

		$key = str_replace(' ', '', $encryption_key);
		$key_length = min(strlen($key), 32);

		if ($key_length === 0) {
			return $string;
		}

		for ($i = 0, $j = 0, $length = strlen($string); $i < $length; $i++) {
			$char = ord($string[$i]);

			if ($char & 0xE0) {
				$string[$i] = chr($char ^ (ord($key[$j]) & 0x1F));
			}

			$j = ($j + 1) % $key_length;
		}

		return $string;
	}

	// Encrypt an e-mail address for storage
	function encrypt_email(string $email): string
	{
		if ($email === '') {
			return '';
		}

		$key = derived_key('email');

		if (function_exists('sodium_crypto_secretbox')) {
			$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

			return EMAIL_CIPHER_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($email, $nonce, $key));
		}

		$iv = random_bytes(12);
		$ciphertext = openssl_encrypt($email, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

		return 'v2g:' . base64_encode($iv . $tag . $ciphertext);
	}

	// Decrypt a stored e-mail address; returns an empty string on failure
	function decrypt_email(string $stored): string
	{
		if ($stored === '') {
			return '';
		}

		$key = derived_key('email');

		if (str_starts_with($stored, EMAIL_CIPHER_PREFIX)) {
			$data = base64_decode(substr($stored, strlen(EMAIL_CIPHER_PREFIX)), true);

			if ($data === false || strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
				return '';
			}

			$nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$email = sodium_crypto_secretbox_open(substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);

			return $email === false ? '' : $email;
		}

		if (str_starts_with($stored, 'v2g:')) {
			$data = base64_decode(substr($stored, 4), true);

			if ($data === false || strlen($data) <= 28) {
				return '';
			}

			$email = openssl_decrypt(substr($data, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));

			return $email === false ? '' : $email;
		}

		return safe_email(legacy_xor($stored));
	}

	// Whether two e-mail addresses are the same
	function same_email(string $a, string $b): bool
	{
		return $a !== '' && $b !== '' && hash_equals(strtolower(trim($a)), strtolower(trim($b)));
	}

	// Hash a password for storage
	function hash_password(string $password): string
	{
		return $password === '' ? '' : password_hash($password, PASSWORD_DEFAULT);
	}

	// Check a password against a stored hash, including legacy MD5 hashes
	function verify_password(string $password, string $stored): bool
	{
		if ($password === '' || $stored === '') {
			return false;
		}

		if (password_get_info($stored)['algo'] !== null) {
			return password_verify($password, $stored);
		}

		return preg_match('/^[a-f0-9]{32}$/D', $stored) === 1
			&& hash_equals($stored, md5(legacy_xor($password)));
	}

	// Whether a stored password hash should be upgraded
	function password_needs_upgrade(string $stored): bool
	{
		return $stored !== '' && (password_get_info($stored)['algo'] === null || password_needs_rehash($stored, PASSWORD_DEFAULT));
	}
