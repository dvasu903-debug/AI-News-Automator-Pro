<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\DTO;

enum FetchStatus: string
{
    case Success = 'success';
    case Failed  = 'failed';
}
