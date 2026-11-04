<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRulecraftTables extends Migration
{
    public function up(): void
    {
        $groups = config('rulecraft.tables.rule_groups');
        $rules = config('rulecraft.tables.rules');
        $assignments = config('rulecraft.tables.role_permission_rules');

        Schema::create($groups, function (Blueprint $table) use ($groups) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 191);
            $table->unique('name', $this->indexName($groups, 'name'));
            $table->string('match', 10)->default('all');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index('parent_id', $this->indexName($groups, 'parent_idx'));
            $table->foreign('parent_id', $this->indexName($groups, 'parent_fk'))
                ->references('id')->on($groups)->onDelete('cascade');
        });

        Schema::create($rules, function (Blueprint $table) use ($rules, $groups) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rule_group_id');
            $table->string('name', 191);
            $table->text('expression');
            $table->text('failure_message')->nullable();
            $table->text('success_message')->nullable();
            $table->json('builder_definition')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('action', 191)->nullable();
            $table->json('action_parameters')->nullable();
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->foreign('rule_group_id', $this->indexName($rules, 'group_fk'))
                ->references('id')->on($groups)->onDelete('cascade');
        });

        Schema::create($assignments, function (Blueprint $table) use ($assignments, $groups) {
            $table->bigIncrements('id');
            $table->string('role_type', 191);
            $table->string('role_id', 64);
            $table->string('permission_type', 191);
            $table->string('permission_id', 64);
            $table->unsignedBigInteger('rule_group_id');
            $table->timestamps();
            $table->unique(
                ['role_type', 'role_id', 'permission_type', 'permission_id'],
                $this->indexName($assignments, 'grant')
            );
            $table->foreign('rule_group_id', $this->indexName($assignments, 'group_fk'))
                ->references('id')->on($groups)->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('rulecraft.tables.role_permission_rules'));
        Schema::dropIfExists(config('rulecraft.tables.rules'));
        Schema::dropIfExists(config('rulecraft.tables.rule_groups'));
    }

    private function indexName(string $table, string $suffix): string
    {
        return 'rc_'.substr(sha1($table), 0, 12).'_'.$suffix;
    }
}
