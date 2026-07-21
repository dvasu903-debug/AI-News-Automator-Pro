<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Query;

/**
 * Supported comparison operators for Filter. Kept to a narrow, safe set —
 * the QueryBuilder maps each to a fixed SQL fragment, so there is no path
 * from an operator value to arbitrary SQL injection.
 */
enum FilterOperator: string
{
    case Equals             = '=';
    case NotEquals          = '!=';
    case GreaterThan        = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan           = '<';
    case LessThanOrEqual    = '<=';
    case Like               = 'LIKE';
    case In                 = 'IN';
    case NotIn               = 'NOT IN';
    case Between            = 'BETWEEN';
    case IsNull             = 'IS NULL';
    case IsNotNull          = 'IS NOT NULL';
}
