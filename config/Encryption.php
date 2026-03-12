<?php
// config/Encryption.php

class Encryption {

    private static string $cipher = 'AES-256-CBC';

    /**
     * Encrypt a plaintext string.
     * Stores as: hex(iv) . ":" . hex(ciphertext)
     * Using hex encoding avoids any binary/base64 IV-splitting issues.
     */
    public static function encrypt(?string $value): ?string {
        if ($value === null || $value === '') return null;

        $key      = self::key();
        $ivLength = openssl_cipher_iv_length(self::$cipher);
        $iv       = random_bytes($ivLength); // always exactly 16 bytes for AES-256-CBC

        $encrypted = openssl_encrypt($value, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Store as hex(iv):hex(ciphertext) — no binary ambiguity, always splits cleanly
        return bin2hex($iv) . ':' . bin2hex($encrypted);
    }

    /**
     * Decrypt a value encrypted with encrypt().
     * Returns null if value is null/empty/invalid.
     */
    public static function decrypt(?string $value): ?string {
        if ($value === null || $value === '') return null;

        // Must contain exactly one colon separator
        if (substr_count($value, ':') !== 1) return null;

        [$ivHex, $encHex] = explode(':', $value, 2);

        // Validate both parts are valid hex strings
        if (!ctype_xdigit($ivHex) || !ctype_xdigit($encHex)) return null;

        $key       = self::key();
        $ivLength  = openssl_cipher_iv_length(self::$cipher);
        $iv        = hex2bin($ivHex);
        $encrypted = hex2bin($encHex);

        // Guard: IV must be exactly the right length before calling openssl
        if (strlen($iv) !== $ivLength) return null;

        $decrypted = openssl_decrypt($encrypted, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted === false ? null : $decrypted;
    }

    /**
     * Mask a decrypted value for safe display.
     *   Phone : 0917*****89
     *   Email : pe***@example.com
     */
    public static function mask(string $type, ?string $value): string {
        if (!$value) return '-';

        if ($type === 'phone') {
            $len = strlen($value);
            if ($len <= 6) return str_repeat('*', $len);
            return substr($value, 0, 4) . str_repeat('*', $len - 6) . substr($value, -2);
        }

        if ($type === 'email') {
            if (!str_contains($value, '@')) return '***';
            [$local, $domain] = explode('@', $value, 2);
            $visible = min(2, strlen($local));
            $masked  = substr($local, 0, $visible) . str_repeat('*', max(0, strlen($local) - $visible));
            return $masked . '@' . $domain;
        }

        return $value;
    }

    // ── Private ──────────────────────────────────────────────────────────

    private static function key(): string {
        require_once __DIR__ . '/Database.php';
        $raw = Database::env('ENCRYPTION_KEY');

        if (empty($raw)) {
            throw new RuntimeException('ENCRYPTION_KEY is not set in .env');
        }

        $key = base64_decode($raw, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'ENCRYPTION_KEY must be a base64-encoded 32-byte key. ' .
                'Generate: python3 -c "import os,base64; print(base64.b64encode(os.urandom(32)).decode())"'
            );
        }

        return $key;
    }
}