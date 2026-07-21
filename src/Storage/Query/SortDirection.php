<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Query;

enum SortDirection: string
{
    case Ascending  = 'ASC';
    case Descending = 'DESC';
}
