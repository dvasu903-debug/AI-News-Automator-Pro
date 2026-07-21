<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

enum StopReason: string
{
    case EndTurn      = 'end_turn';
    case MaxTokens     = 'max_tokens';
    case ToolUse       = 'tool_use';
    case ContentFilter = 'content_filter';
    case Unknown       = 'unknown';
}
