<?php

declare(strict_types=1);

namespace Noria\Approvals;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use InvalidArgumentException;

final class Schema
{
    public static function primary(Blueprint $table): void
    {
        match (Settings::keyType()) {
            'int' => $table->id(),
            'ulid' => $table->ulid('id')->primary(),
            'uuid' => $table->uuid('id')->primary(),
            default => throw new InvalidArgumentException('Unsupported approvals key type.'),
        };
    }

    public static function approvalReference(Blueprint $table, string $column = 'approval_id'): void
    {
        self::column($table, $column, Settings::keyType());
        $table->foreign($column)->references('id')->on(Settings::table('approvals'))->cascadeOnDelete();
    }

    public static function actor(Blueprint $table, string $column, bool $nullable = true): void
    {
        $actors = Settings::actorColumns();
        $definition = self::column($table, $column, $actors['key']);

        if ($nullable) {
            $definition->nullable();
        }

        if ($actors['constrained']) {
            $foreign = $table->foreign($column)->references($actors['references'])->on($actors['table']);
            $nullable ? $foreign->nullOnDelete() : $foreign->cascadeOnDelete();
        }
    }

    public static function tenant(Blueprint $table): void
    {
        if (! Settings::tenancyEnabled()) {
            return;
        }

        $tenancy = Settings::tenantColumns();
        self::column($table, $tenancy['column'], $tenancy['key'])->nullable()->index();

        if ($tenancy['constrained']) {
            $table->foreign($tenancy['column'])->references($tenancy['references'])->on($tenancy['table'])->cascadeOnDelete();
        }
    }

    public static function subject(Blueprint $table, string $name = 'subject'): void
    {
        $table->string($name.'_type');
        self::column($table, $name.'_id', Settings::subjectKeyType())->nullable();
        $table->index([$name.'_type', $name.'_id']);
    }

    public static function column(Blueprint $table, string $column, string $type): ColumnDefinition
    {
        return match ($type) {
            'int' => $table->unsignedBigInteger($column),
            'ulid' => $table->ulid($column),
            'uuid' => $table->uuid($column),
            'string' => $table->string($column, 191),
            default => throw new InvalidArgumentException("Unsupported key type [{$type}]."),
        };
    }
}
