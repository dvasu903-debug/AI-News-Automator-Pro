# ADR-0004: No Foreign Key Constraints

**Status:** Accepted · **Module:** 3

## Context

Storage's tables (`ana_images.article_id` → `wp_posts.ID`, etc.) have logical relationships to other tables, including WordPress core tables the plugin doesn't own.

## Decision

No table anywhere in the plugin uses a formal `FOREIGN KEY` constraint. This matches WordPress core's own convention (`wp_postmeta`, `wp_options` use none either). Referential integrity is checked by health-check orphan detection (`ImageRepository::findOrphans()`, `StorageHealthCheck`) instead of database-enforced constraints.

## Consequences

- The plugin tolerates activation-order variance, partial migrations, and manual DB surgery without cascade-delete surprises.
- Orphaned rows (e.g. an image record whose article was deleted outside the plugin's own code path) are possible and expected — they're surfaced via health checks, not prevented at the schema level.
- Works uniformly across hosting environments with varying MySQL/MariaDB configurations, some of which handle FK enforcement inconsistently under load.

## Alternatives Considered

- **FK constraints with `ON DELETE SET NULL`.** Rejected: WordPress's own core schema doesn't do this for the same tables, and introducing it here would be inconsistent with how the rest of the WordPress ecosystem (themes, other plugins) already treats `wp_posts` relationships.
