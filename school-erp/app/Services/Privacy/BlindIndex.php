<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use RuntimeException;

class BlindIndex
{
    /**
     * Normalize an identifier by removing any non-alphanumeric character and converting to uppercase.
     */
    public static function normalize(string $v): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9]/', '', $v);

        return strtoupper($cleaned ?? '');
    }

    /**
     * Compute HMAC-SHA256 blind index for an identifier scoped by organization.
     *
     * Returns null if the value is null or empty after normalization.
     * Throws RuntimeException if blind_index_key is missing or shorter than 32 characters.
     */
    public static function hash(string $organizationId, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = self::normalize($value);
        if ($normalized === '') {
            return null;
        }

        $key = self::getKey();

        return strtolower(hash_hmac('sha256', $organizationId.'|'.$normalized, $key));
    }

    /**
     * Retrieve the blind index key from configuration at call time and validate length.
     *
     * @throws RuntimeException
     */
    public static function getKey(): string
    {
        $key = config('privacy.blind_index_key');

        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Blind index key is missing or shorter than 32 characters.');
        }

        return $key;
    }
}
