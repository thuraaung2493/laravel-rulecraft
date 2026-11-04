<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests\Fixtures;

use stdClass;

class PolicyData
{
    public static function document(string $name = 'approval'): stdClass
    {
        return (object) ['version' => 1, 'group' => (object) [
            'name' => $name,
            'match' => 'all',
            'enabled' => true,
            'rules' => [self::rule()],
            'groups' => [],
        ]];
    }

    public static function rule(): stdClass
    {
        return (object) [
            'name' => 'within-limit',
            'expression' => 'amount <= limit',
            'failure_message' => null,
            'success_message' => null,
            'enabled' => true,
            'priority' => 0,
            'action' => null,
            'action_parameters' => null,
        ];
    }

    public static function json(stdClass $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
