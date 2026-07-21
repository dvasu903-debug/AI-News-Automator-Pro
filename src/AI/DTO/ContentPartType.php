<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

enum ContentPartType: string
{
    case Text  = 'text';
    case Image = 'image';
}
