<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;
use Thuraaung\Rulecraft\Rulecraft;

class ImportCommand extends Command
{
    /** @var string */
    protected $signature = 'rulecraft:import {--file= : JSON policy file path}'
        .' {--json= : JSON policy string}';

    /** @var string */
    protected $description = 'Import a new policy from a JSON string or file';

    public function handle(Rulecraft $rulecraft): int
    {
        try {
            $json = $this->readJson();
            $group = $rulecraft->import($json);
            $this->info(OutputFormatter::escape('Imported policy ['.$group->name.'].'));

            return 0;
        } catch (Throwable $error) {
            $message = OutputFormatter::escape('Unable to import policy: '.$error->getMessage());
            $this->getOutput()->getErrorStyle()->writeln($message);

            return 2;
        }
    }

    private function readJson(): string
    {
        $hasFile = $this->input->hasParameterOption('--file');
        $hasJson = $this->input->hasParameterOption('--json');

        if ($hasFile === $hasJson) {
            throw new InvalidArgumentException('Provide exactly one of --file or --json.');
        }

        $value = $this->option($hasFile ? 'file' : 'json');

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('The selected input option requires a value.');
        }

        if ($hasJson) {
            return $value;
        }

        if (! is_file($value) || ! is_readable($value)) {
            throw new InvalidArgumentException('Policy file ['.$value.'] is not readable.');
        }

        $json = @file_get_contents($value);

        if ($json === false) {
            throw new RuntimeException('Cannot read policy file ['.$value.'].');
        }

        return $json;
    }
}
