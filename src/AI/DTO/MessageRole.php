<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

enum MessageRole: string
{
    case System    = 'system';
    case User      = 'user';
    case Assistant = 'assistant';
    case Tool      = 'tool';
}
