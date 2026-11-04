<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Thuraaung\Rulecraft\Exceptions\InvalidGroupHierarchyException;
use Thuraaung\Rulecraft\Models\RuleGroup;

class GroupHierarchy
{
    public const DEFAULT_MAX_DEPTH = 10;

    /** @var int */
    private $maxDepth;

    public function __construct(Repository $config)
    {
        $this->maxDepth = $config->get('rulecraft.max_depth', self::DEFAULT_MAX_DEPTH);

        if (! is_int($this->maxDepth) || $this->maxDepth < 1) {
            throw new InvalidArgumentException('rulecraft.max_depth must be a positive integer.');
        }
    }

    public function maxDepth(): int
    {
        return $this->maxDepth;
    }

    /** @return array<int, string> */
    public function ancestors(RuleGroup $group): array
    {
        $ancestors = [];
        $parentId = $group->parent_id;

        while ($parentId !== null) {
            $id = (string) $parentId;

            if ($id === (string) $group->getKey() || in_array($id, $ancestors, true)) {
                throw new InvalidGroupHierarchyException(
                    'Groups cannot contain circular parent relationships.'
                );
            }

            $parent = RuleGroup::query()->find($parentId);

            if ($parent === null) {
                throw new InvalidGroupHierarchyException('The parent group does not exist.');
            }

            $ancestors[] = $id;
            $this->assertPath($ancestors, $group);
            $parentId = $parent->parent_id;
        }

        return $ancestors;
    }

    /**
     * @param  array<int, string>  $ancestors
     */
    public function assertPath(array $ancestors, RuleGroup $group): void
    {
        if (count($ancestors) >= $this->maxDepth) {
            throw new InvalidGroupHierarchyException(
                'Group nesting exceeds rulecraft.max_depth ('.$this->maxDepth.').'
            );
        }

        if ($group->getKey() !== null && in_array((string) $group->getKey(), $ancestors, true)) {
            throw new InvalidGroupHierarchyException(
                'Groups cannot contain circular parent relationships.'
            );
        }
    }

    public function validate(RuleGroup $group): void
    {
        if ($group->parent_id !== null && $group->exists && $group->permissionRules()->exists()) {
            throw new InvalidGroupHierarchyException(
                'Remove conditional permission assignments before moving a root group.'
            );
        }

        $pending = [[$group, $this->ancestors($group)]];

        // Check the entire moved subtree, not just its new parent's depth.
        for ($index = 0; $index < count($pending); $index++) {
            [$current, $ancestors] = $pending[$index];
            $this->assertPath($ancestors, $current);

            if (! $current->exists) {
                continue;
            }

            $ancestors[] = (string) $current->getKey();

            foreach ($current->groups()->get() as $child) {
                $pending[] = [$child, $ancestors];
            }
        }
    }
}
