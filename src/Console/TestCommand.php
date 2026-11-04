<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;
use Thuraaung\Rulecraft\Results\EvaluationResult;
use Thuraaung\Rulecraft\Rulecraft;

class TestCommand extends Command
{
    /** @var string */
    protected $signature = 'rulecraft:test {group : Name of the group to evaluate}'
        .' {--data= : JSON context object}';

    /** @var string */
    protected $description = 'Evaluate a saved rule group without executing actions';

    public function handle(Rulecraft $rulecraft): int
    {
        try {
            $data = $this->parseContext();
            $result = $rulecraft->group((string) $this->argument('group'))->evaluate($data);
            $this->renderGroup($result);

            if ($result->hasErrors()) {
                return 2;
            }

            return $result->passed() ? 0 : 1;
        } catch (Throwable $error) {
            $this->error(OutputFormatter::escape('Unable to test group: '.$error->getMessage()));

            return 2;
        }
    }

    /** @return array<array-key, mixed> */
    private function parseContext(): array
    {
        $json = $this->option('data');

        if (! is_string($json) || trim($json) === '') {
            throw new InvalidArgumentException('Provide a JSON context string with --data.');
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data) || substr(ltrim($json), 0, 1) !== '{') {
            throw new InvalidArgumentException(
                'Context must be a JSON object, for example {"amount": 100}.'
            );
        }

        return $data;
    }

    private function renderGroup(EvaluationResult $result, int $depth = 0): void
    {
        $group = $result->group();
        $label = $group === null ? 'Group' : $group->name.' ['.strtoupper($group->match).']';
        $status = $result->disabled() ? 'DISABLED'
            : ($result->hasErrors() ? 'ERROR' : ($result->passed() ? 'PASS' : 'FAIL'));
        $this->line(str_repeat('  ', $depth).$status.'  '.OutputFormatter::escape($label));

        foreach ($result->validationErrors() as $field => $messages) {
            foreach ($messages as $message) {
                $label = 'context.'.$field.': '.$message;
                $this->line(str_repeat('  ', $depth + 1).'ERROR  '.OutputFormatter::escape($label));
            }
        }

        foreach ($result->rules() as $rule) {
            $error = $rule->error();
            $status = $rule->disabled() ? 'DISABLED'
                : ($error !== null ? 'ERROR' : ($rule->passed() ? 'PASS' : 'FAIL'));
            $label = $rule->rule()->name;

            if ($error !== null) {
                $label .= ': '.$error->getMessage();
            } elseif ($rule->message() !== null) {
                $label .= ': '.$rule->message();
            }

            $this->line(str_repeat('  ', $depth + 1).$status.'  '.OutputFormatter::escape($label));
        }

        foreach ($result->groups() as $child) {
            $this->renderGroup($child, $depth + 1);
        }
    }
}
