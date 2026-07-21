<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Dedup;

enum SourceItemStatus: string
{
    case Seen      = 'seen';      // discovered, not yet acted on further
    case Processed = 'processed'; // became an article (or was otherwise consumed downstream)
    case Rejected  = 'rejected';  // failed this module's own validation
}
