<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as Builder;
use Noria\Approvals\Schema;
use Noria\Approvals\Settings;

return new class extends Migration
{
    public function up(): void
    {
        Builder::create(Settings::table('approvals'), function (Blueprint $table): void {
            Schema::primary($table);
            Schema::tenant($table);
            Schema::subject($table);
            $table->string('operation', 32);
            $table->string('status', 32)->default('pending');
            Schema::actor($table, 'maker_id');
            $table->jsonb('requirement');
            $table->unsignedInteger('step')->default(0);
            $table->unsignedInteger('stage')->default(1);
            $table->jsonb('current_state')->nullable();
            $table->jsonb('new_state')->nullable();
            $table->jsonb('payload')->nullable();
            $table->text('maker_notes')->nullable();
            Schema::actor($table, 'closer_id');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('escalates_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'escalates_at']);
        });

        Builder::create(Settings::table('events'), function (Blueprint $table): void {
            Schema::primary($table);
            Schema::tenant($table);
            Schema::approvalReference($table);
            $table->string('action', 32);
            $table->unsignedInteger('stage')->nullable();
            $table->string('step')->nullable();
            Schema::actor($table, 'actor_id');
            Schema::actor($table, 'principal_id');
            $table->text('notes')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['approval_id', 'stage', 'action']);
        });

        Builder::create(Settings::table('delegations'), function (Blueprint $table): void {
            Schema::primary($table);
            Schema::tenant($table);
            Schema::actor($table, 'delegator_id', nullable: false);
            Schema::actor($table, 'delegate_id', nullable: false);
            $table->string('subject_type')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['delegate_id', 'revoked_at']);
        });

        Builder::create(Settings::table('policies'), function (Blueprint $table): void {
            Schema::primary($table);
            Schema::tenant($table);
            $table->string('subject_type');
            $table->jsonb('definition');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['subject_type', 'active']);
        });
    }

    public function down(): void
    {
        Builder::dropIfExists(Settings::table('policies'));
        Builder::dropIfExists(Settings::table('delegations'));
        Builder::dropIfExists(Settings::table('events'));
        Builder::dropIfExists(Settings::table('approvals'));
    }
};
