<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * jsonb cast that round-trips EXACTLY (an object `{}` stays `{}`, never becomes `[]`).
 *  get: associative array.
 *  set: a string is treated as already-encoded JSON (validated, stored untouched); arrays are encoded.
 * An empty PHP array encodes to `[]`; when an empty OBJECT is needed pass pre-encoded JSON text.
 */
final class JsonDocument implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            json_decode($value, true, 512, JSON_THROW_ON_ERROR); // validate only

            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
