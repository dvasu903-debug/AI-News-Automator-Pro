<?php

declare(strict_types=1);

/**
 * Default configuration tree for the AI Publishing Engine (the platform
 * powering the AI News Automator Pro plugin).
 *
 * This is a plain data file, not a class — it is required directly by
 * whatever binds ConfigRepositoryInterface, never autoloaded. Keeping
 * defaults here rather than hardcoded inside OptionBackedConfigRepository
 * keeps the repository class itself generic and reusable, and gives every
 * future module one obvious place to add its own default config block.
 *
 * Keys are grouped by module. Only Core's own keys exist yet — later
 * modules append their own top-level key (e.g. 'ai', 'sources',
 * 'publishing') to this array as they're built.
 */

return [
    'logging' => [
        'max_entries' => 200,
    ],
    'rest' => [
        'namespace' => 'ai-news-automator/v1',
    ],
    'settings' => [
        'default_capability' => 'manage_options',
    ],
    'events' => [
        'log_dispatch_failures' => true,
    ],
];
