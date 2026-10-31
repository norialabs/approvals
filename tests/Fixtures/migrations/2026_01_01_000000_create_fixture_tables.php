<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Approvals\Settings;

return new class extends Migration
{
    public function up(): void
    {
        $tenancy = Settings::tenantColumns();
        $actors = Settings::actorColumns();
        $key = fn (Blueprint $table, string $type) => match ($type) {
            'ulid' => $table->ulid('id')->primary(),
            'uuid' => $table->uuid('id')->primary(),
            default => $table->id(),
        };
        $tenant = function (Blueprint $table) use ($tenancy): void {
            if (Settings::tenancyEnabled()) {
                Noria\Approvals\Schema::column($table, $tenancy['column'], $tenancy['key'])->nullable();
            }
        };

        if (Settings::tenancyEnabled()) {
            Schema::create($tenancy['table'], function (Blueprint $table) use ($key, $tenancy): void {
                $key($table, $tenancy['key']);
                $table->string('name');
                $table->timestamps();
            });
        }

        Schema::create($actors['table'], function (Blueprint $table) use ($key, $actors, $tenant): void {
            $key($table, $actors['key']);
            $tenant($table);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) use ($tenant): void {
            $table->id();
            $tenant($table);
            $table->string('number');
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->string('secret')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('documents', function (Blueprint $table) use ($tenant): void {
            $table->ulid('id')->primary();
            $tenant($table);
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) use ($tenant): void {
            $table->uuid('id')->primary();
            $tenant($table);
            $table->string('title');
            $table->decimal('value', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('memos', function (Blueprint $table): void {
            $table->id();
            $table->string('body');
            $table->text('pin')->nullable();
            $table->timestamp('written_on')->nullable();
            $table->timestamp('revised_on')->nullable();
            $table->timestamp('removed_on')->nullable();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('invoice_tag', function (Blueprint $table): void {
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->primary(['invoice_id', 'tag_id']);
        });
    }
};
