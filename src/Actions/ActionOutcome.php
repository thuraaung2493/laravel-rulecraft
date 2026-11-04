<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Actions;

use Throwable;
use Thuraaung\Rulecraft\Models\Rule;

class ActionOutcome
{
    /** @var Rule */
    private $rule;

    /** @var ActionResult */
    private $result;

    /** @var Throwable|null */
    private $exception;

    public function __construct(Rule $rule, ActionResult $result, ?Throwable $exception = null)
    {
        $this->rule = $rule;
        $this->result = $result;
        $this->exception = $exception;
    }

    public function rule(): Rule
    {
        return $this->rule;
    }

    public function result(): ActionResult
    {
        return $this->result;
    }

    public function exception(): ?Throwable
    {
        return $this->exception;
    }
}
