<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\TwoFactor;

use InvalidArgumentException;

/**
 * RFC 4648 §6 base32 codec — used for TOTP secrets.
 *
 * Authenticator apps (Google Authenticator, Authy, 1Password, …) consume secrets in this
 * encoding, so secrets are stored base32 internally and decoded only when computing the
 * HMAC inside {@see TotpProvider}. Decoder is permissive about case and whitespace and
 * accepts both padded (`====`) and stripped forms; encoder always produces upper-case
 * unpadded output to match the convention used by `otpauth://` URIs.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $padded = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::ALPHABET[bindec($padded)];
        }

        return $output;
    }

    public static function decode(string $input): string
    {
        $clean = strtoupper(preg_replace('/[\s\-=]+/', '', $input) ?? '');
        if ($clean === '') {
            return '';
        }

        $bits = '';
        for ($i = 0, $len = strlen($clean); $i < $len; $i++) {
            $position = strpos(self::ALPHABET, $clean[$i]);
            if ($position === false) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- TOTP decode diagnostic, not rendered in HTML.
                throw new InvalidArgumentException(sprintf('Invalid base32 character "%s".', $clean[$i]));
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) !== 8) {
                continue;
            }
            $output .= chr(bindec($chunk));
        }

        return $output;
    }

    /**
     * Generates a cryptographically strong base32 secret of the given byte length.
     *
     * Defaults to 20 bytes (160 bits), which matches the recommendation from RFC 4226
     * §4 R6 for HOTP/TOTP and is the size most authenticator apps optimise for.
     */
    public static function randomSecret(int $bytes = 20): string
    {
        if ($bytes < 10) {
            throw new InvalidArgumentException('Refusing to mint a TOTP secret shorter than 80 bits.');
        }

        return self::encode(random_bytes($bytes));
    }
}
