<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Thuraaung\Rulecraft\Builder\DefinitionCompiler;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;

/**
 * @property int $id
 * @property int $rule_group_id
 * @property string $name
 * @property bool $enabled
 * @property string $expression
 * @property string|null $failure_message
 * @property string|null $success_message
 * @property string|null $action
 * @property array<string, mixed>|null $action_parameters
 * @property array<string, mixed>|null $builder_definition
 * @property int $priority
 * @property-read RuleGroup $group
 */
class Rule extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name', 'expression', 'action', 'action_parameters', 'priority', 'enabled',
        'builder_definition', 'failure_message', 'success_message',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['priority' => 0, 'enabled' => true];

    /** @var array<string, string> */
    protected $casts = [
        'action_parameters' => 'array',
        'builder_definition' => 'array',
        'priority' => 'integer',
        'enabled' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Rule $rule): void {
            if ($rule->builder_definition === null) {
                return;
            }

            try {
                $expression = app(DefinitionCompiler::class)
                    ->compile($rule->builder_definition, false);
            } catch (InvalidPolicyException $error) {
                $expression = null;
            }

            if ($expression !== $rule->expression) {
                $rule->builder_definition = null;
            }
        });
    }

    /** @param array<string, mixed>|\stdClass|null $value */
    public function setBuilderDefinitionAttribute($value): void
    {
        $this->attributes['builder_definition'] = $value === null ? null
            : json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public function getTable(): string
    {
        return config('rulecraft.tables.rules');
    }

    /** @return BelongsTo<RuleGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(RuleGroup::class, 'rule_group_id');
    }
}
