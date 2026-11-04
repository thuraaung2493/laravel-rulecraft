<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Console\Application;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use stdClass;
use Thuraaung\Rulecraft\Exceptions\InvalidActionHandlerException;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Rulecraft as RulecraftService;
use Thuraaung\Rulecraft\Tests\Fixtures\ActionServiceProvider;
use Thuraaung\Rulecraft\Tests\Fixtures\InjectedAction;
use Thuraaung\Rulecraft\Tests\Fixtures\RecordingAction;

class ActionRegistrationTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [ActionServiceProvider::class]);
    }

    public function test_provider_and_config_actions_support_dependency_injection(): void
    {
        $recorder = new RecordingAction();
        $this->app->instance(RecordingAction::class, $recorder);
        config(['rulecraft.actions' => ['configured_record' => RecordingAction::class]]);
        $group = $this->group('approval', ['true', 'true']);
        $rules = $group->rules()->get();
        $rules[0]->update(
            ['action' => 'module_record', 'action_parameters' => ['output' => ['approved' => true]]]
        );
        $rules[1]->update(['action' => 'configured_record']);

        $execution = $this->app->make(RulecraftService::class)->group('approval')->run([]);

        $this->assertTrue($execution->successful());
        $this->assertCount(2, $recorder->calls);
        $this->assertSame(['approved' => true], $recorder->calls[1]['context']);
    }

    public function test_provider_registration_works_after_booting_with_cached_config(): void
    {
        $path = config_path('rulecraft.php');
        $original = is_file($path) ? file_get_contents($path) : null;
        file_put_contents($path, '<?php return '.var_export(array_replace(config('rulecraft'), [
            'actions' => ['cached_record' => RecordingAction::class],
        ]), true).';');

        try {
            $this->artisan('config:cache')->assertExitCode(0);
            Application::forgetBootstrappers();
            $this->refreshApplication();
            $this->assertTrue($this->app->configurationIsCached());
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $group = $this->group('approval', ['true', 'true']);
            $rules = $group->rules()->get();
            $rules[0]->update(['action' => 'module_record']);
            $rules[1]->update(['action' => 'cached_record']);

            $execution = Rulecraft::group('approval')->run([]);
            $this->assertTrue($execution->successful());
            $this->assertCount(2, $execution->actions());
        } finally {
            $this->artisan('config:clear')->assertExitCode(0);
            if ($original === null) {
                unlink($path);
            } else {
                file_put_contents($path, $original);
            }
        }
    }

    public function test_registration_survives_facade_reset_and_fresh_service_resolution(): void
    {
        Rulecraft::registerAction('custom', RecordingAction::class);
        Rulecraft::clearResolvedInstances();
        $group = $this->group('approval', ['true']);
        $group->rules()->first()->update(['action' => 'custom']);

        $this->assertTrue(
            $this->app->make(RulecraftService::class)->group('approval')->run([])->successful()
        );
    }

    public function test_registration_and_evaluation_do_not_construct_handlers(): void
    {
        $resolved = 0;
        $this->app->bind(InjectedAction::class, function () use (&$resolved) {
            $resolved++;

            return new InjectedAction(new RecordingAction());
        });
        Rulecraft::registerAction('lazy', InjectedAction::class);
        $group = $this->group('approval', ['true']);
        $group->rules()->first()->update(['action' => 'lazy']);

        $this->assertTrue(Rulecraft::group('approval')->evaluate([])->passed());
        $this->assertSame(0, $resolved);
        $this->assertTrue(Rulecraft::group('approval')->run([])->successful());
        $this->assertSame(1, $resolved);
    }

    public function test_duplicate_provider_names_are_rejected_even_for_the_same_class(): void
    {
        $this->expectException(InvalidActionHandlerException::class);
        $this->expectExceptionMessage('Action [module_record] is already registered.');
        Rulecraft::registerAction('module_record', InjectedAction::class);
    }

    public function test_provider_registration_cannot_replace_config_registration(): void
    {
        config(['rulecraft.actions' => ['configured' => RecordingAction::class]]);
        $this->expectException(InvalidActionHandlerException::class);
        $this->expectExceptionMessage('Action [configured] is already registered.');
        Rulecraft::registerAction('configured', InjectedAction::class);
    }

    public function test_config_changes_cannot_silently_replace_provider_registration(): void
    {
        config(['rulecraft.actions' => ['module_record' => RecordingAction::class]]);
        $group = $this->group('approval', ['true']);
        $group->rules()->first()->update(['action' => 'module_record']);
        $this->expectException(InvalidActionHandlerException::class);
        $this->expectExceptionMessage('Action [module_record] is already registered.');
        Rulecraft::group('approval')->run([]);
    }

    public function test_invalid_registrations_fail_without_constructing_handlers(): void
    {
        foreach ([
            ['', RecordingAction::class],
            ['  ', RecordingAction::class],
            ['invalid', stdClass::class],
            ['missing', 'MissingActionClass'],
        ] as $registration) {
            try {
                Rulecraft::registerAction($registration[0], $registration[1]);
                $this->fail('Expected an invalid registration exception.');
            } catch (InvalidActionHandlerException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    public function test_unresolvable_provider_handler_is_detected_before_any_action_runs(): void
    {
        $recorder = new RecordingAction();
        $this->app->instance(RecordingAction::class, $recorder);
        config(['rulecraft.actions' => ['record' => RecordingAction::class]]);
        $failure = new RuntimeException('Dependency unavailable');
        $this->app->bind(InjectedAction::class, function () use ($failure) {
            throw $failure;
        });
        $group = $this->group('approval', ['true', 'true']);
        $rules = $group->rules()->get();
        $rules[0]->update(['action' => 'record']);
        $rules[1]->update(['action' => 'module_record']);

        try {
            Rulecraft::group('approval')->run([]);
            $this->fail('Expected a handler resolution exception.');
        } catch (InvalidActionHandlerException $error) {
            $this->assertSame($failure, $error->getPrevious());
            $this->assertSame([], $recorder->calls);
        }
    }

    public function test_container_bindings_must_return_an_action(): void
    {
        $this->app->bind(InjectedAction::class, function () {
            return new stdClass();
        });
        $group = $this->group('approval', ['true']);
        $group->rules()->first()->update(['action' => 'module_record']);
        $this->expectException(InvalidActionHandlerException::class);
        $this->expectExceptionMessage('must implement');
        Rulecraft::group('approval')->run([]);
    }
}
