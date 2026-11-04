<?php

declare(strict_types=1);

// Isolated browser-test application. Never register this fixture in a consuming application.
require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Thuraaung\Rulecraft\RulecraftServiceProvider;
use Thuraaung\Rulecraft\Tests\Fixtures\BuilderData;
use Thuraaung\Rulecraft\Tests\Fixtures\BuilderFunctions;
use Thuraaung\Rulecraft\Tests\TestCase;

class BuilderBrowserFunctions extends BuilderFunctions
{
    /** @return array<string, array<string, mixed>> */
    public function getBuilderFunctions(): array
    {
        $functions = parent::getBuilderFunctions();
        $functions['credit_score']['return_type'] = 'integer';
        $functions['credit_score']['arguments'] = [
            'applicant_id' => ['label' => 'Applicant ID', 'type' => 'integer'],
        ];

        return $functions;
    }
}

class BuilderBrowserApplication extends TestCase
{
    public function bootBrowser(): Application
    {
        $this->setUp();

        return $this->app;
    }

    protected function getPackageProviders($app): array
    {
        return [RulecraftServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $directory = sys_get_temp_dir().'/rulecraft-builder-browser';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $database = $directory.'/database.sqlite';
        if (! is_file($database)) {
            touch($database);
        }
        $app['config']->set('database.connections.testing.database', $database);
        $app['config']->set('session.driver', 'file');
        $app['config']->set('session.files', $directory);
        $app['config']->set('session.cookie', 'rulecraft_browser_test');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('app.debug', true);
        $app['config']->set('rulecraft.builder.enabled', true);
        $app['config']->set('rulecraft.builder.theme',
            ($_GET['theme'] ?? '') === 'bootstrap' ? 'bootstrap' : 'custom');
        $app['config']->set('rulecraft.builder.fields',
            isset($_GET['free']) ? [] : BuilderData::fields());
        $app['config']->set('rulecraft.expression_providers', [BuilderBrowserFunctions::class]);
        $app['config']->set('rulecraft.builder.stylesheet',
            isset($_GET['override']) ? 'builder-test-overrides.css' : null);
        $paths = array_merge([__DIR__], $app['config']->get('view.paths'));
        $app['config']->set('view.paths', $paths);
    }
}

$test = new BuilderBrowserApplication('browser');
$app = $test->bootBrowser();
$app->instance('env', 'local');
$user = new User();
$user->forceFill(['id' => 1, 'name' => 'Builder Developer']);
$app['auth']->setUser($user);
Gate::define('manage-rulecraft', function (): bool {
    return true;
});
Route::get('builder-test-overrides.css', function () {
    return response('.rulecraft-builder{--rc-primary:#2563eb;--rc-radius:14px;}', 200,
        ['Content-Type' => 'text/css']);
});
Route::middleware('web')->get('/', function () {
    return view('fixture');
});
$request = Request::capture();
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
