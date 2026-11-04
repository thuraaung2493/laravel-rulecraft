<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Results;

use Throwable;
use Thuraaung\Rulecraft\Models\Rule;

class RuleResult
{
    /** @var Rule */
    private $rule;

    /** @var bool */
    private $passed;

    /** @var bool */
    private $disabled;

    /** @var Throwable|null */
    private $error;

    /** @var string|null */
    private $message;

    public function __construct(Rule $rule, bool $passed, ?Throwable $error = null)
    {
        $this->rule = $rule;
        $this->disabled = ! $rule->enabled;
        $this->passed = $passed && $error === null && ! $this->disabled;
        $this->error = $error;
        $this->message = null;

        if (! $this->disabled && $error === null) {
            $message = $this->passed ? $rule->success_message : $rule->failure_message;
            if ($message !== null && trim($message) !== '') {
                $this->message = $message;
            } elseif (! $this->passed) {
                $this->message = 'Rule "'.$rule->name.'" did not pass.';
            }
        }
    }

    public function rule(): Rule
    {
        return $this->rule;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function disabled(): bool
    {
        return $this->disabled;
    }

    public function failed(): bool
    {
        return ! $this->passed;
    }

    public function error(): ?Throwable
    {
        return $this->error;
    }

    public function message(): ?string
    {
        return $this->message;
    }
}
