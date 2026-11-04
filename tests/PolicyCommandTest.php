<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Tester\CommandTester;
use Thuraaung\Rulecraft\Facades\Rulecraft;
use Thuraaung\Rulecraft\Models\Rule;
use Thuraaung\Rulecraft\Models\RuleGroup;
use Thuraaung\Rulecraft\Tests\Fixtures\PolicyData;

class PolicyCommandTest extends TestCase
{
    /** @var string */
    private $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/rulecraft-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_export_stdout_contains_only_json_and_preserves_console_markup(): void
    {
        $group = $this->group();
        $group->rules()->first()->update([
            'action_parameters' => ['message' => '<info>Keep this</info>'],
        ]);
        $tester = $this->command('rulecraft:export');
        $this->assertSame(0, $tester->execute(['group' => 'approval'], [
            'capture_stderr_separately' => true, 'decorated' => true,
        ]));
        $this->assertSame(Rulecraft::export('approval').PHP_EOL, $tester->getDisplay());
        $this->assertSame('', $tester->getErrorOutput());
        $document = json_decode($tester->getDisplay(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            '<info>Keep this</info>',
            $document->group->rules[0]->action_parameters->message
        );
    }

    public function test_export_errors_go_to_stderr_without_polluting_json_stdout(): void
    {
        $tester = $this->command('rulecraft:export');
        $this->assertSame(2, $tester->execute(['group' => 'missing'], [
            'capture_stderr_separately' => true,
        ]));
        $this->assertSame('', $tester->getDisplay());
        $this->assertStringContainsString('does not exist', $tester->getErrorOutput());
    }

    public function test_exported_file_can_be_imported_as_a_complete_policy(): void
    {
        $root = $this->group();
        $child = $root->groups()->create(['name' => 'child', 'match' => 'any']);
        $child->rules()->create(['name' => 'alternative', 'expression' => 'true']);
        $json = Rulecraft::export('approval');
        $path = $this->directory.'/loan policy.json';
        $export = $this->command('rulecraft:export');
        $this->assertSame(0, $export->execute(['group' => 'approval', '--output' => $path]));
        $this->assertSame($json.PHP_EOL, file_get_contents($path));
        $root->delete();
        $import = $this->command('rulecraft:import');
        $this->assertSame(0, $import->execute(['--file' => $path]));
        $this->assertStringContainsString('Imported policy [approval]', $import->getDisplay());
        $this->assertSame($json, Rulecraft::export('approval'));
    }

    public function test_export_never_overwrites_an_existing_file(): void
    {
        $this->group();
        $path = $this->directory.'/existing.json';
        file_put_contents($path, 'original contents');
        $tester = $this->command('rulecraft:export');
        $this->assertSame(2, $tester->execute(['group' => 'approval', '--output' => $path], [
            'capture_stderr_separately' => true,
        ]));
        $this->assertSame('original contents', file_get_contents($path));
        $this->assertSame('', $tester->getDisplay());
        $this->assertStringContainsString('Cannot create', $tester->getErrorOutput());
    }

    public function test_export_rejects_missing_directories_and_empty_output_options(): void
    {
        $this->group();
        foreach ([$this->directory.'/missing/policy.json', '', null] as $path) {
            $tester = $this->command('rulecraft:export');
            $this->assertSame(2, $tester->execute(['group' => 'approval', '--output' => $path], [
                'capture_stderr_separately' => true,
            ]));
            $this->assertSame('', $tester->getDisplay());
            $this->assertStringContainsString('Unable to export', $tester->getErrorOutput());
        }
        $this->assertSame([], glob($this->directory.'/*'));
    }

    public function test_export_does_not_follow_a_dangling_output_symlink(): void
    {
        $this->group();
        $target = $this->directory.'/target.json';
        $link = $this->directory.'/link.json';
        symlink($target, $link);
        $tester = $this->command('rulecraft:export');
        $this->assertSame(2, $tester->execute(['group' => 'approval', '--output' => $link]));
        $this->assertFalse(file_exists($target));
        $this->assertTrue(is_link($link));
    }

    public function test_json_strings_import_without_files_or_action_execution(): void
    {
        $document = PolicyData::document();
        $document->group->rules[0]->action = 'unregistered';
        $tester = $this->command('rulecraft:import');
        $this->assertSame(0, $tester->execute(['--json' => PolicyData::json($document)]));
        $this->assertSame(1, RuleGroup::count());
        $this->assertSame('unregistered', Rule::first()->action);
    }

    public function test_import_requires_exactly_one_nonempty_input_option(): void
    {
        $json = PolicyData::json(PolicyData::document());
        foreach ([
            [], ['--json' => $json, '--file' => 'policy.json'],
            ['--json' => ''], ['--json' => null], ['--file' => ''], ['--file' => null],
        ] as $input) {
            $tester = $this->command('rulecraft:import');
            $this->assertSame(2, $tester->execute($input, [
                'capture_stderr_separately' => true,
            ]));
            $this->assertSame('', $tester->getDisplay());
            $this->assertStringContainsString('Unable to import', $tester->getErrorOutput());
        }
        $this->assertSame(0, RuleGroup::count());
    }

    public function test_import_rejects_invalid_files_and_json_without_saving(): void
    {
        $path = $this->directory.'/invalid.json';
        file_put_contents($path, '{invalid');
        foreach ([
            ['--file' => $path], ['--file' => $this->directory.'/missing.json'],
            ['--file' => $this->directory], ['--json' => '{invalid'], ['--json' => '[]'],
        ] as $input) {
            $tester = $this->command('rulecraft:import');
            $this->assertSame(2, $tester->execute($input, [
                'capture_stderr_separately' => true,
            ]));
            $this->assertSame('', $tester->getDisplay());
            $this->assertStringContainsString('Unable to import', $tester->getErrorOutput());
        }
        $this->assertSame(0, RuleGroup::count());
        $this->assertSame(0, Rule::count());
    }

    public function test_duplicate_cli_import_keeps_the_existing_policy(): void
    {
        $json = PolicyData::json(PolicyData::document());
        $tester = $this->command('rulecraft:import');
        $this->assertSame(0, $tester->execute(['--json' => $json]));
        $this->assertSame(2, $tester->execute(['--json' => $json], [
            'capture_stderr_separately' => true,
        ]));
        $this->assertStringContainsString('already exists', $tester->getErrorOutput());
        $this->assertSame(1, RuleGroup::count());
        $this->assertSame(1, Rule::count());
    }

    private function command(string $name): CommandTester
    {
        $this->withoutMockingConsoleOutput();
        $commands = $this->app->make(Kernel::class)->all();

        return new CommandTester($commands[$name]);
    }
}
