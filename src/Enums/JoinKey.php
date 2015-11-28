<?php
namespace Czim\Filter\Enums;

use PHPExtra\Type\Enum\Enum;

/**
 * Unique identifiers for standard join parameter-sets in filters
 */
class JoinKey extends Enum
{
    const TRANSLATIONS = 'translations';
    const PARENT       = 'parent';
    const CHILDREN     = 'children';
    const CHILD        = 'child';
}
