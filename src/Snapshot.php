<?php

declare(strict_types=1);

namespace Noria\Approvals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

final class Snapshot
{
    public const HIDDEN = '[hidden]';

    private const SEAL = 'approvals:sealed:';

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function seal(Model $model, array $attributes): array
    {
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

    public static function same(mixed $left, mixed $right): bool
    {
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

    private static function isSealed(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::SEAL);
    }
}
