# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package

`craftcodery/laravel-searchable` — a Laravel package providing a `Searchable` trait for Eloquent models. Installed into host Laravel apps via Composer. There is no test suite, no build step, and no lint config in this repo; changes are validated by consuming apps.

Supports Laravel 10/11/12/13 on PHP ^8.2. Auto-registers `SearchableServiceProvider` via `extra.laravel.providers` in `composer.json`.

## Architecture

The whole package is two classes plus a config file:

- `src/Searchable.php` — the trait host models use. Exposes `scopeSearch($query, $search, $limit = 25, $restriction = null)` and requires implementers to define `toSearchableArray()`.
- `src/SearchableServiceProvider.php` — merges/publishes `config/searchable.php` (tag `searchable-config`).
- `config/searchable.php` — declares the available matchers and their default relevance weights.

### How `scopeSearch` builds the query

1. Clones the incoming query and applies joins from `toSearchableArray()['joins']` via `makeJoins()` as `leftJoin`s. A join key can be a comma-joined list of columns (e.g. `'users.primary_org_id,users.secondary_org_id'`) — the first becomes an `on`, the rest become `orOn`. Joins also support optional `where`/`whereIn` clauses inside the keys array.
2. Normalizes the search string (`formatSearchString`). If the input is purely digits with punctuation removed, it's treated as a phone number and the non-digits are stripped.
3. Tokenizes the search into `words` and `orderedWords` (sorted by length desc), capped by `maxWords` (default 5). Phrase quoting (`"..."`) is honored.
4. For every `columns` entry, runs every enabled matcher and builds a `CASE WHEN ... THEN relevance ELSE 0 END` snippet. The list of snippets is summed into a single computed `relevance` column via `addSelectsToQuery`.
5. For every `fulltext` entry, builds a `MATCH() AGAINST()` expression (skipped on SQLite; on SQLite the fulltext columns are folded into the regular columns list instead).
6. Applies a dynamic `threshold = relevance_count * (cols/6) * (matchers/6)` and filters with `HAVING relevance >= threshold` (MySQL/Postgres/SQLite) or a post-`whereRaw` cast (SQL Server, which can't use `HAVING` on derived columns the same way). Adds `ORDER BY relevance DESC` and a `LIMIT`.
7. Groups results: explicit `groupBy` from `toSearchableArray()` if provided, otherwise the model's primary key plus any joined columns that appear in the searchable list. Grouping is skipped entirely on SQL Server.
8. The cloned, search-enriched query is then wrapped back into the original via `fromSub()` so callers can keep chaining `where`s/eager loads on the outer query. Bindings are reassembled in order (`search_bindings` first, then the cloned query's own bindings) because `fromSub` resets binding context.
9. Optional `$restriction` Closure receives the cloned query before binding reassembly — use it to scope the inner query.

### Matchers

Defined as protected methods on the trait and enabled/weighted via `config/searchable.php`. Each matcher either sets `$this->operator`, `$this->searchString`, and pushes to `$this->search_bindings` (simple `CASE WHEN col op ? THEN ...` form) or sets `$this->matcherQuery` directly with a custom SQL expression (for `consecutiveCharactersMatcher`, `timesInStringMatcher`, and the fulltext matcher).

Matchers requiring multi-word context (`exactFullMatcher`, `exactInStringMatcher`) are auto-disabled when there's only one search word. `similarStringMatcher` (uses `SOUNDS LIKE`) is auto-disabled on SQLite and SQL Server.

Per-column SQL transforms can be declared via `toSearchableArray()['mutations']` — e.g. `'users.email' => 'LOWER'` wraps the column reference in `LOWER(...)` inside every generated matcher snippet.

### Driver handling

`getDatabaseDriver()` reads `database.connections.{model->connection ?? default}.driver`. Branches exist for `mysql` (default), `sqlite`, `sqlsrv`, and implicitly `pgsql`. Driver-specific differences:

- Identifier delimiter: backtick everywhere except `sqlsrv` (uses `"`).
- Length function: `CHAR_LENGTH` (MySQL/Postgres), `LENGTH` (SQLite), `LEN` (SQL Server).
- Fulltext is treated as ordinary `columns` on SQLite (`MATCH ... AGAINST` is MySQL-specific); ignored on other non-MySQL drivers.
- SQL Server uses `whereRaw('CAST(relevance AS NUMERIC(10,2)) >= ...')` instead of `HAVING`, and skips `groupBy`.

When adding driver-specific behavior, search for existing `getDatabaseDriver()` checks rather than introducing a new abstraction — the trait does this inline by design.

### Bindings

Matchers push raw values into `$this->search_bindings` in the same order their SQL fragments are generated. `scopeSearch` clears the cloned query's bindings and replays `search_bindings` first, then the cloned query's own bindings — this order matters because matcher fragments live in the `SELECT` list, which precedes any user `WHERE`/`JOIN` bindings. If you add a new matcher, push bindings in the exact order the `?` placeholders appear in its generated SQL.
