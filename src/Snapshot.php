<?php

declare(strict_types=1);

namespace Noria\Approvals;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

final class Snapshot
{
    public const HIDDEN = '[hidden]';

    private const SEAL = 'approvals:sealed:';

    private const STRUCTURED_CASTS = ['array', 'json', 'object', 'collection', AsArrayObject::class, AsCollection::class];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function seal(Model $model, array $attributes): array
    {
        $attributes = self::structured($model, $attributes);

        foreach (self::encrypted($model) as $field) {
            $value = $attributes[$field] ?? null;

            if (is_scalar($value) && ! self::isSealed($value)) {
                $attributes[$field] = self::SEAL.Crypt::encryptString((string) $value);
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function open(array $attributes): array
    {
        foreach ($attributes as $field => $value) {
            if (is_string($value) && self::isSealed($value)) {
                $attributes[$field] = Crypt::decryptString(substr($value, strlen(self::SEAL)));
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>|null  $new
     * @param  list<string>  $hidden
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    public static function changes(?array $current, ?array $new, array $hidden = []): array
    {
        $current ??= [];
        $fields = $new === null ? array_keys($current) : array_keys($new);
        $changes = [];

        foreach ($fields as $field) {
            $from = $current[$field] ?? null;
            $to = $new === null ? null : ($new[$field] ?? null);

            if ($new !== null && array_key_exists($field, $current) && self::same($from, $to)) {
                continue;
            }

            $conceal = in_array($field, $hidden, true) || (is_string($from) && self::isSealed($from)) || (is_string($to) && self::isSealed($to));

            $changes[] = [
                'field' => $field,
                'from' => $conceal && $from !== null ? self::HIDDEN : $from,
                'to' => $conceal && $to !== null ? self::HIDDEN : $to,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function raw(array $attributes): array
    {
        foreach ($attributes as $field => $value) {
            if (is_array($value)) {
                $attributes[$field] = json_encode($value, JSON_THROW_ON_ERROR);
            }
        }

        return $attributes;
    }

    public static function same(mixed $left, mixed $right): bool
    {
        $left = self::decodedJson($left);
        $right = self::decodedJson($right);

        if (is_array($left) && is_array($right)) {
            return self::canonical($left) === self::canonical($right);
        }

        if ($left === null || $right === null) {
            return $left === $right;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return (string) $left === (string) $right || (float) $left === (float) $right;
        }

        if (is_bool($left) || is_bool($right)) {
            return (bool) $left === (bool) $right;
        }

        if (is_scalar($left) && is_scalar($right)) {
            return (string) $left === (string) $right;
        }

        return json_encode($left) === json_encode($right);
    }

    /** @return list<string> */
    public static function hidden(Model $model): array
    {
        $secretCasts = [];

        foreach ($model->getCasts() as $field => $cast) {
            if (str_contains(strtolower($cast), 'encrypt') || strtolower($cast) === 'hashed') {
                $secretCasts[] = $field;
            }
        }

        return array_values(array_unique([...$model->getHidden(), ...$secretCasts, ...self::encrypted($model), ...self::listed($model, 'approvalHidden')]));
    }

    /** @return list<string> */
    public static function encrypted(Model $model): array
    {
        return self::listed($model, 'approvalEncrypted');
    }

    /** @return list<string> */
    public static function listed(Model $model, string $property): array
    {
        if (! property_exists($model, $property)) {
            return [];
        }

        $value = (new \ReflectionProperty($model, $property))->getValue($model);

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function structured(Model $model, array $attributes): array
    {
        $casts = $model->getCasts();

        foreach ($attributes as $field => $value) {
            $cast = is_string($casts[$field] ?? null) ? explode(':', $casts[$field])[0] : null;

            if (is_string($value) && $cast !== null && in_array(strtolower($cast), array_map(strtolower(...), self::STRUCTURED_CASTS), true)) {
                $attributes[$field] = self::decodedJson($value);
            }
        }

        return $attributes;
    }

    private static function decodedJson(mixed $value): mixed
    {
        if (! is_string($value) || ! in_array(ltrim($value)[0] ?? '', ['[', '{'], true)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $value;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => is_array($item) ? self::canonical($item) : $item, $value);
    }

    private static function isSealed(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::SEAL);
    }
}
