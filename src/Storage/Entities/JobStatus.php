<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Entities;

enum JobStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Delayed    = 'delayed';
    case Completed  = 'completed';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';
}
