<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Thuraaung\Rulecraft\Tests\Fixtures\User;

class BuilderConfigurationTest extends TestCase
{
    /** @param Application $app */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('rulecraft.builder.enabled', true);
        $app['config']->set('rulecraft.builder.route_prefix', 'business-rule-editor');
        $app['config']->set('rulecraft.builder.ability', 'edit-business-policies');
    }

    public function test_prefix_and_authorization_ability_are_configurable(): void
    {
        $this->installPermissions();
        $this->group();
        $this->actingAs(new User(['id' => 1, 'name' => 'Administrator']));
        Gate::define('edit-business-policies', function (): bool {
            return true;
        });
        $url = route('rulecraft.builder.load', ['group' => 'approval']);
        $this->assertStringContainsString('/business-rule-editor/policy', $url);
        $this->getJson($url)->assertOk()->assertJsonPath('group.name', 'approval');
    }

    public function test_builder_routes_support_laravel_route_caching(): void
    {
        $router = $this->app['router'];
        $routes = $router->getRoutes();
        foreach ($routes as $route) {
            $route->prepareForSerialization();
        }
        $compiled = $routes->compile();
        $this->assertArrayHasKey('rulecraft.builder.save', $compiled['attributes']);
        $router->setCompiledRoutes($compiled);
        $cached = $router->getRoutes()->getByName('rulecraft.builder.save');
        $this->assertSame('business-rule-editor/save', $cached->uri());
        $this->assertSame(['POST'], $cached->methods());
    }
}
