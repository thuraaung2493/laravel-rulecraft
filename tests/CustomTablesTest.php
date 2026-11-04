<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Thuraaung\Rulecraft\Builder\RuleGroupBuilder;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\RolePermissionRule;
use Thuraaung\Rulecraft\Tests\Fixtures\BuilderData;
use Thuraaung\Rulecraft\Tests\Fixtures\User;

class CustomTablesTest extends TestCase
{
    /** @param Application $app */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('rulecraft.tables', [
            'rules' => 'business_conditions',
            'rule_groups' => 'lending_policies',
            'role_permission_rules' => 'conditional_grants',
        ]);
    }

    public function test_every_custom_table_supports_relationships_permissions_and_rollback(): void
    {
        $this->installPermissions();
        DB::enableQueryLog();
        $group = $this->group();
        $child = $group->groups()->create(['name' => 'child', 'match' => 'any']);
        $child->rules()->create(['name' => 'nested-condition', 'expression' => 'true']);
        $user = User::create(['name' => 'Manager']);
        $role = Role::create(['name' => 'branch-manager', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'loan-approval', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $mapping = RolePermissionRule::assign($role, $permission, $group);

        $this->assertTrue($group->rules()->first()->group->is($group));
        $this->assertTrue($mapping->group->is($group));
        $this->assertTrue($child->parent->is($group));
        $this->assertTrue(
            Rulecraft::group('approval')->evaluate(['amount' => 100, 'limit' => 200])->passed()
        );
        $this->assertTrue(
            Rulecraft::for($user)
                ->permission('loan-approval')
                ->evaluate(['amount' => 100, 'limit' => 200])
                ->passed()
        );

        $group->delete();
        $this->assertNull($child->fresh());
        $this->assertSame(0, RolePermissionRule::count());

        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('rulecraft_', $query['query']);
        }

        foreach ([
            'rulecraft_rules',
            'rulecraft_rule_groups',
            'rulecraft_role_permission_rules',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
        foreach (config('rulecraft.tables') as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_custom_schema_rebuilds_with_hierarchy_and_enabled_defaults(): void
    {
        $group = $this->group('approval', ['true']);
        $rule = $group->rules()->first();
        $rule->update(['enabled' => false]);
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->failed());
        $group->update(['enabled' => false]);
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->disabled());

        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
        foreach (config('rulecraft.tables') as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('business_conditions', 'enabled'));
        $this->assertTrue(Schema::hasColumn('lending_policies', 'enabled'));
        $this->assertTrue(Schema::hasColumn('lending_policies', 'parent_id'));
        $group = $this->group('approval', ['true']);
        $child = $group->groups()->create(['name' => 'child', 'match' => 'all']);
        $child->rules()->create(['name' => 'nested', 'expression' => 'true']);
        $this->assertTrue($group->fresh()->enabled);
        $this->assertTrue($group->rules()->first()->enabled);
        $this->assertTrue($child->parent->is($group));
        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->passed());
        $group->delete();
        $this->assertNull($child->fresh());
    }

    public function test_policy_transfer_uses_configured_tables(): void
    {
        $group = $this->group();
        $child = $group->groups()->create(['name' => 'child', 'match' => 'any']);
        $child->rules()->create(['name' => 'nested', 'expression' => 'true']);
        DB::enableQueryLog();
        $json = Rulecraft::export('approval');
        $group->delete();
        $imported = Rulecraft::import($json);
        $this->assertSame('lending_policies', $imported->getTable());
        $this->assertSame('business_conditions', $imported->rules()->first()->getTable());
        $this->assertSame($json, Rulecraft::export('approval'));
        $this->assertTrue(
            Rulecraft::group('approval')->evaluate(['amount' => 100, 'limit' => 200])->passed()
        );
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('rulecraft_', $query['query']);
        }
    }

    public function test_builder_metadata_and_saving_use_the_custom_schema(): void
    {
        config(['rulecraft.builder.fields' => BuilderData::fields()]);
        $builder = $this->app->make(RuleGroupBuilder::class);
        $draft = BuilderData::group();
        $draft->rules[0]->builder_definition = BuilderData::definition('amount', '<=', 200);
        $draft->rules[0]->failure_message = 'Over limit.';
        $draft->rules[0]->success_message = 'Within limit.';
        DB::enableQueryLog();
        $root = $builder->save($draft);
        $loaded = $builder->load('approval');
        $loaded['group']->rules[0]->priority = 9;
        $builder->save($loaded['group'], $loaded['revision']);
        $this->assertSame('lending_policies', $root->getTable());
        $this->assertNotNull($root->rules()->first()->builder_definition);
        $this->assertSame(9, $root->rules()->first()->priority);
        $this->assertSame('Over limit.', $root->rules()->first()->failure_message);
        $this->assertSame('Within limit.', $root->rules()->first()->success_message);
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('rulecraft_', $query['query']);
        }
    }

    public function test_configuration_can_be_cached_with_custom_table_names(): void
    {
        $path = config_path('rulecraft.php');
        $original = is_file($path) ? file_get_contents($path) : null;
        file_put_contents(
            $path,
            '<?php return '.var_export(['tables' => config('rulecraft.tables')], true).';'
        );

        try {
            $this->artisan('config:cache')->assertExitCode(0);
            $cached = require $this->app->getCachedConfigPath();
            $this->assertSame('business_conditions', $cached['rulecraft']['tables']['rules']);
            $this->assertSame(
                'conditional_grants',
                $cached['rulecraft']['tables']['role_permission_rules']
            );
        } finally {
            $this->artisan('config:clear')->assertExitCode(0);
            if ($original === null) {
                unlink($path);
            } else {
                file_put_contents($path, $original);
            }
        }
    }
}
