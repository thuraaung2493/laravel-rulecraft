<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Events;

use Thuraaung\Rulecraft\Results\EvaluationResult;

class GroupEvaluated
{
    /** @var string */
    public $group;

    /** @var EvaluationResult */
    public $result;

    /** @var array<array-key, mixed> */
    public $context;

    /** @param array<array-key, mixed> $context */
    public function __construct(string $group, EvaluationResult $result, array $context)
    {
        $this->group = $group;
        $this->result = $result;
        $this->context = $context;
    }
}
