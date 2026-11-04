<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Thuraaung\Rulecraft\Rulecraft;

class ExportCommand extends Command
{
    /** @var string */
    protected $signature = 'rulecraft:export {group : Root group name}'
        .' {--output= : New JSON file path; omit to print JSON}';

    /** @var string */
    protected $description = 'Export a complete policy as versioned JSON without running actions';

    public function handle(Rulecraft $rulecraft): int
    {
        try {
            $json = $rulecraft->export((string) $this->argument('group'));

            if ($this->input->hasParameterOption('--output')) {
                $path = $this->option('output');

                if (! is_string($path) || trim($path) === '') {
                    throw new InvalidArgumentException('--output requires a file path.');
                }

                $this->writeFile($path, $json.PHP_EOL);
                $this->info(OutputFormatter::escape('Exported policy to '.$path));
            } else {
                $this->getOutput()->writeln($json, OutputInterface::OUTPUT_RAW);
            }

            return 0;
        } catch (Throwable $error) {
            $message = OutputFormatter::escape('Unable to export policy: '.$error->getMessage());
            $this->getOutput()->getErrorStyle()->writeln($message);

            return 2;
        }
    }

    private function writeFile(string $path, string $json): void
    {
        if (! is_dir(dirname($path))) {
            throw new InvalidArgumentException('Output directory does not exist.');
        }

        if (is_link($path)) {
            throw new InvalidArgumentException('Output path must not be a symbolic link.');
        }

        $stream = @fopen($path, 'x');

        if ($stream === false) {
            throw new RuntimeException('Cannot create ['.$path.']; it may already exist.');
        }

        $complete = false;

        try {
            if (@fwrite($stream, $json) !== strlen($json)) {
                throw new RuntimeException('Could not write the complete policy to ['.$path.'].');
            }

            $complete = true;
        } finally {
            fclose($stream);

            if (! $complete) {
                @unlink($path);
            }
        }
    }
}
