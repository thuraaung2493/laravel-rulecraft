<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\PermissionServiceProvider;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\RulecraftServiceProvider;
use Thuraaung\Rulecraft\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [PermissionServiceProvider::class, RulecraftServiceProvider::class];
    }

    /** @param Application $app */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.api', ['driver' => 'session', 'provider' => 'users']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    /**
     * @param  array<int, string>  $expressions
     */
    protected function group(
        string $name = 'approval',
        array $expressions = ['amount <= limit'],
        string $match = 'all'
    ): RuleGroup {
        $group = RuleGroup::create(['name' => $name, 'match' => $match]);

        foreach ($expressions as $index => $expression) {
            $group->rules()->create(['name' => 'condition-'.$index, 'expression' => $expression]);
        }

        return $group;
    }

    protected function installPermissions(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
        });

        if (class_exists('CreatePermissionTables', false)) {
            $migration = new \CreatePermissionTables();
        } else {
            $files = glob(
                __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/'
                .'*create_permission_tables*'
            );
            $migration = require $files[0];

            if (! $migration instanceof Migration) {
                $migration = new \CreatePermissionTables();
            }
        }

        $migration->up();
    }
}
