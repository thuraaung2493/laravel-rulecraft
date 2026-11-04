<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Permissions;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Thuraaung\Rulecraft\Contracts\GrantResolver;
use Thuraaung\Rulecraft\Events\PermissionEvaluated;
use Thuraaung\Rulecraft\Exceptions\InvalidGroupHierarchyException;
use Thuraaung\Rulecraft\Exceptions\RuleGroupNotFoundException;
use Thuraaung\Rulecraft\Models\RolePermissionRule;
use Thuraaung\Rulecraft\Results\PermissionResult;
use Thuraaung\Rulecraft\Rulecraft;

class PermissionEvaluation
{
    /** @var mixed */
    private $user;

    /** @var GrantResolver */
    private $resolver;

    /** @var Rulecraft */
    private $rulecraft;

    /** @var Dispatcher */
    private $events;

    /** @var string|null */
    private $permission;

    /** @var string|null */
    private $guard;

    /**
     * @param  mixed  $user
     */
    public function __construct(
        $user,
        GrantResolver $resolver,
        Rulecraft $rulecraft,
        Dispatcher $events
    ) {
        $this->user = $user;
        $this->resolver = $resolver;
        $this->rulecraft = $rulecraft;
        $this->events = $events;
    }

    public function permission(string $permission): self
    {
        if ($permission === '') {
            throw new InvalidArgumentException('Permission name cannot be empty.');
        }

        $evaluation = clone $this;
        $evaluation->permission = $permission;

        return $evaluation;
    }

    public function guard(string $guard): self
    {
        if ($guard === '') {
            throw new InvalidArgumentException('Guard name cannot be empty.');
        }

        $evaluation = clone $this;
        $evaluation->guard = $guard;

        return $evaluation;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function evaluate(array $data): PermissionResult
    {
        if ($this->permission === null) {
            throw new InvalidArgumentException('Select a permission before evaluating.');
        }

        $evaluations = collect();
        $errors = collect();
        $passed = false;

        foreach ($this->resolver->grants($this->user, $this->permission, $this->guard) as $grant) {
            $mapping = $this->mappingFor($grant);

            if ($mapping === null) {
                continue;
            }

            try {
                if ($mapping->group === null) {
                    throw new RuleGroupNotFoundException(
                        'Condition group for assignment ['.$mapping->getKey().'] does not exist.'
                    );
                }

                if ($mapping->group->parent_id !== null) {
                    throw new InvalidGroupHierarchyException(
                        'Conditional permissions must reference a root group.'
                    );
                }

                $result = $this->rulecraft->group($mapping->group->name)->evaluate($data);
                $evaluations->put($mapping->getKey(), $result);
                $errors = $errors->merge($result->errors());
                $passed = $passed || $result->passed();
            } catch (RuleGroupNotFoundException|InvalidGroupHierarchyException $error) {
                $errors->push($error);
            }
        }

        $result = new PermissionResult($passed, $evaluations, $errors);
        $this->events->dispatch(new PermissionEvaluated(
            $this->user,
            $this->permission,
            $this->guard,
            $result,
            $data
        ));

        return $result;
    }

    private function mappingFor(RolePermissionGrant $grant): ?RolePermissionRule
    {
        return RolePermissionRule::query()->with('group')
            ->where('role_type', $grant->role()->getMorphClass())
            ->where('role_id', (string) $grant->role()->getKey())
            ->where('permission_type', $grant->permission()->getMorphClass())
            ->where('permission_id', (string) $grant->permission()->getKey())
            ->first();
    }
}
