<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use InvalidArgumentException;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Thuraaung\Rulecraft\Actions\ActionRegistry;
use Thuraaung\Rulecraft\Builder\BuilderComponent;
use Thuraaung\Rulecraft\Console\ExportCommand;
use Thuraaung\Rulecraft\Console\ImportCommand;
use Thuraaung\Rulecraft\Console\TestCommand;
use Thuraaung\Rulecraft\Contracts\GrantResolver;

class RulecraftServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rulecraft.php', 'rulecraft');

        $this->app->singleton(ActionRegistry::class);

        $this->app->bind(ExpressionEvaluator::class, function (
            Application $app
        ): ExpressionEvaluator {
            $language = new ExpressionLanguage();

            $providers = $app->make(Repository::class)->get('rulecraft.expression_providers', []);

            foreach ($providers as $class) {
                $provider = $app->make($class);

                if (! $provider instanceof ExpressionFunctionProviderInterface) {
                    throw new InvalidArgumentException(
                        'Expression providers must implement '
                        .ExpressionFunctionProviderInterface::class.'.'
                    );
                }

                $language->registerProvider($provider);
            }

            return new ExpressionEvaluator($language);
        });

        $this->app->bind(GrantResolver::class, function (Application $app): GrantResolver {
            return $app->make($app->make(Repository::class)->get('rulecraft.permissions.resolver'));
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rulecraft');
        $this->loadRoutesFrom(__DIR__.'/../routes/builder.php');

        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            $blade->component(BuilderComponent::class, 'rulecraft::builder');
            $blade->if('rulecraft', function (string $group, array $context = []): bool {
                return $this->app->make(Rulecraft::class)
                    ->group($group)
                    ->evaluate($context)
                    ->passed();
            });

            $blade->if('canRulecraft', function (
                string $permission,
                array $context = [],
                ?string $guard = null
            ): bool {
                $user = $this->app->make('auth')->guard($guard)->user();

                if ($user === null) {
                    return false;
                }

                $evaluation = $this->app->make(Rulecraft::class)
                    ->for($user)
                    ->permission($permission);

                if ($guard !== null) {
                    $evaluation = $evaluation->guard($guard);
                }

                return $evaluation->evaluate($context)->passed();
            });
        });

        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class, ExportCommand::class, ImportCommand::class]);

            $this->publishes([
                __DIR__.'/../config/rulecraft.php' => config_path('rulecraft.php'),
            ], 'rulecraft-config');
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/rulecraft'),
            ], 'rulecraft-builder-views');
        }
    }
}
