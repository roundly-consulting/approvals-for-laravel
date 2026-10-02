<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Normalises a subject type to what the morph columns store.
 *
 * @internal
 */
final class MorphType
{
    /**
     * The stored form of a model class or morph alias: a class the host's morph map
     * aliases becomes its alias; anything else (an alias, an unmapped class) is kept.
     */
    public static function of(string $type): string
    {
        $alias = array_search($type, Relation::morphMap(), true);

        return is_string($alias) ? $alias : $type;
    }
}
