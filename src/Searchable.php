<?php

namespace CraftCodery\Searchable;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait Searchable
{
    /**
     * The bindings for the search query.
     */
    protected $search_bindings = [];

    /**
     * The searchable columns for the model.
     *
     * @var array
     */
    protected $searchable = [];

    /**
     * The necessary relevance score for
     * results to be returned.
     *
     * @var int|null
     */
    protected $threshold;

    /**
     * The operator to use for the query.
     *
     * @var string
     */
    protected $operator = '=';

    /**
     * The words to be searched for.
     *
     * @var string[]
     */
    protected $words = [];

    /**
     * The words to be searched for ordered by length.
     *
     * @var string[]
     */
    protected $orderedWords = [];

    /**
     * The string to use for the query.
     *
     * @var string
     */
    protected $searchString;

    /**
     * The select query for a matcher.
     *
     * @var string|null
     */
    protected $matcherQuery;

    /**
     * Creates the search scope.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $search
     * @param int $limit
     * @param string|null|Closure $restriction
     *
     * @return \Illuminate\Database\Eloquent\Builder
     *
     * @see search()
     */
    public function scopeSearch(Builder $query, $search, $limit = 25, $restriction = null)
    {
        $this->searchable = $this->toSearchableArray();

        $cloned_query = clone $query;
        $cloned_query->select($this->getTable() . '.*');
        $this->makeJoins($cloned_query);

        $search = $this->formatSearchString($search);
        if ($search === '') {
            return $query;
        }

        $this->getWords($search);

        $selects = [];
        $this->search_bindings = [];
        $relevance_count = array_sum($this->getMatchers());

        foreach ($this->getColumns() as $column => $relevance) {
            $relevance_count += $relevance;
            foreach ($this->getSearchQueriesForColumn($column, $relevance) as $select) {
                $selects[] = $select;
            }
        }

        foreach ($this->getFullTextColumns() as $column => $relevance) {
            $relevance_count += $relevance;
            $this->fullTextMatcher($column, $relevance);
            $selects[] = $this->getSearchQuery($column, $relevance * 100);
        }

        $this->addSelectsToQuery($cloned_query, $selects);
        $this->filterQueryWithRelevance($cloned_query, $relevance_count, $limit);
        $this->makeGroupBy($cloned_query);

        if ($restriction instanceof Closure) {
            $cloned_query = $restriction($cloned_query);
        }

        $clone_bindings = $cloned_query->getBindings();
        $cloned_query->setBindings([]);
        $cloned_query->setBindings([], 'join');

        $this->addBindingsToQuery($cloned_query, $this->search_bindings);
        $this->addBindingsToQuery($cloned_query, $clone_bindings);

        $this->mergeQueries($cloned_query, $query);

        if ($this->getDatabaseDriver() === 'sqlsrv') {
            $query->whereRaw('CAST(relevance AS NUMERIC(10,2)) >= ' . number_format($this->threshold, 2, '.', ''));
        }

        return $query;
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    abstract public function toSearchableArray();

    /**
     * Adds the sql joins to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    protected function makeJoins(Builder $query)
    {
        foreach ($this->getJoins() as $table => $keys) {
            $query->leftJoin($table, function ($join) use ($keys) {
                $join->on($keys[0], '=', $keys[1]);
                if (array_key_exists('whereIn', $keys)) {
                    $join->whereIn($keys['whereIn'][0], $keys['whereIn'][1]);
                } elseif (array_key_exists('where', $keys)) {
                    $join->where($keys['where'][0], $keys['where'][1], $keys['where'][2]);
                }
            });
        }
    }

    /**
     * Returns the tables that are to be joined.
     *
     * @return array
     */
    protected function getJoins()
    {
        return Arr::get($this->searchable, 'joins', []);
    }

    /**
     * Format the search string for our query.
     *
     * @param string $search
     *
     * @return string
     */
    protected function formatSearchString($search)
    {
        $search = mb_strtolower(trim($search));

        // Determine if we're attempting to search for a phone number.
        $alphanumeric = preg_replace('/[\W_]/', '', $search);

        if (ctype_digit($alphanumeric)) {
            return $alphanumeric;
        }

        return $search;
    }

    /**
     * Get the words that will be used in the search query.
     *
     * @param string $search
     *
     * @return bool
     */
    protected function getWords($search)
    {
        preg_match_all('/"((?:\\\\.|[^\\\\"])*)"|(\S+)/', $search, $matches);
        $words = $matches[1];
        $number_of_matches = count($matches);
        for ($i = 2; $i < $number_of_matches; $i++) {
            $words = array_filter($words) + $matches[$i];
        }

        $this->words = array_slice($words, 0, $this->searchable['maxWords'] ?? 5);

        usort($words, fn($a, $b) => strlen($b) <=> strlen($a));

        $this->orderedWords = array_slice($words, 0, $this->searchable['maxWords'] ?? 5);

        return true;
    }

    /**
     * Get the matchers to use to determine the relevance score.
     *
     * @return array
     */
    protected function getMatchers()
    {
        $matchers = $this->getSearchableConfig()['matchers'];

        if (count($this->words) === 1) {
            unset($matchers['exactFullMatcher'], $matchers['exactInStringMatcher']);
        }

        if (in_array($this->getDatabaseDriver(), ['sqlite', 'sqlsrv'], true)) {
            unset($matchers['similarStringMatcher']);
        }

        return $matchers;
    }

    /**
     * Returns database driver Ex: mysql, pgsql, sqlite.
     *
     * @return string
     */
    protected function getDatabaseDriver()
    {
        $key = $this->connection ?? config('database.default');

        return config('database.connections.' . $key . '.driver');
    }

    /**
     * Returns the delimiter to use for the current database driver.
     *
     * @return string
     */
    protected function getDelimiter()
    {
        if ($this->getDatabaseDriver() === 'sqlsrv') {
            return '"';
        }

        return '`';
    }

    /**
     * Returns the search columns.
     *
     * @return array
     */
    protected function getColumns()
    {
        if (array_key_exists('columns', $this->searchable)) {
            $driver = $this->getDatabaseDriver();
            $prefix = config('database.connections' . $driver . 'prefix');
            $columns = [];
            foreach ($this->searchable['columns'] as $column => $priority) {
                $columns[$prefix . $column] = $priority;
            }

            if ($driver === 'sqlite' && array_key_exists('fulltext', $this->searchable)) {
                foreach ($this->searchable['fulltext'] as $column => $priority) {
                    $columns[$prefix . $column] = $priority;
                }
            }

            return $columns;
        }

        return [];
    }

    /**
     * Returns the search queries for the specified column.
     *
     * @param string $column
     * @param float $relevance
     *
     * @return array
     */
    protected function getSearchQueriesForColumn($column, $relevance)
    {
        $queries = [];

        foreach ($this->getMatchers() as $matcher => $score) {
            if ($matcher === 'exactFullMatcher') {
                $this->exactMatcher(implode(' ', $this->words));
                $queries[] = $this->getSearchQuery($column, $relevance * $score);

                continue;
            }

            if ($matcher === 'exactInStringMatcher') {
                $this->inStringMatcher(implode(' ', $this->words));
                $queries[] = $this->getSearchQuery($column, $relevance * $score);

                continue;
            }

            foreach ($this->words as $word) {
                $this->$matcher($word, $column, $relevance * $score);
                $queries[] = $this->getSearchQuery($column, $relevance * $score);
            }
        }

        return $queries;
    }

    /**
     * Matches an exact string and applies a high multiplier to bring any exact matches to the top
     * When sanitize is on, if the expression strips some of the characters from the search query
     * then this may not be able to match against a string despite entering in an exact match.
     *
     * @param string $query
     */
    protected function exactMatcher($query)
    {
        $this->operator = '=';
        $this->searchString = "$query";
        $this->search_bindings[] = $this->searchString;
        $this->matcherQuery = null;
    }

    /**
     * Returns the sql string for the given parameters.
     *
     * @param string $column
     * @param float $relevance
     *
     * @return string
     */
    protected function getSearchQuery($column, $relevance)
    {
        $cases = [];
        $cases[] = $this->createMatcher($column, $relevance);

        return implode(' + ', $cases);
    }

    /**
     * Returns the comparison string.
     *
     * @param string $column
     * @param float $relevance
     *
     * @return string
     */
    protected function createMatcher($column, $relevance)
    {
        $formatted = $this->getDelimiter() . str_replace('.', "{$this->getDelimiter()}.{$this->getDelimiter()}", $column) . $this->getDelimiter();

        if (isset($this->searchable['mutations'][$column])) {
            $formatted = $this->searchable['mutations'][$column] . '(' . $formatted . ')';
        }

        return $this->matcherQuery ?? "CASE WHEN $formatted $this->operator ? THEN $relevance ELSE 0 END";
    }

    /**
     * Matches against any occurrences of a string within a string and is case-insensitive.
     *
     * For example, a search for 'smi' would match; 'John Smith' or 'Smiley Face'
     *
     * @param string $query
     */
    protected function inStringMatcher($query)
    {
        $this->operator = 'LIKE';
        $this->searchString = "%$query%";
        $this->search_bindings[] = $this->searchString;
        $this->matcherQuery = null;
    }

    /**
     * Returns the full text search columns.
     *
     * @return array
     */
    protected function getFullTextColumns()
    {
        if (array_key_exists('fulltext', $this->searchable)) {
            $driver = $this->getDatabaseDriver();

            if ($driver === 'sqlite') {
                return [];
            }

            $prefix = config('database.connections' . $driver . 'prefix');
            $columns = [];
            foreach ($this->searchable['fulltext'] as $column => $priority) {
                $columns[$prefix . $column] = $priority;
            }

            return $columns;
        }

        return [];
    }

    /**
     * Matches a full text column against a search query
     *
     * @param string $column
     * @param int|float $relevance
     */
    protected function fullTextMatcher($column, $relevance)
    {
        $this->search_bindings[] = implode(' ', $this->orderedWords);
        $column = str_replace('.', "{$this->getDelimiter()}.{$this->getDelimiter()}", $column);

        $this->matcherQuery = "(MATCH({$this->getDelimiter()}$column{$this->getDelimiter()}) AGAINST (?) * $relevance * 2)";
    }

    /**
     * Puts all the select clauses to the main query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $selects
     */
    protected function addSelectsToQuery(Builder $query, array $selects)
    {
        $query->addSelect(new Expression('(' . implode(' + ', $selects) . ') as relevance'));
    }

    /**
     * Adds the relevance filter to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $relevance_count
     * @param int|null $limit
     */
    protected function filterQueryWithRelevance(Builder $query, $relevance_count, $limit)
    {
        if ($limit === null) {
            $limit = 25;
        }

        $this->threshold = $relevance_count * (count($this->getColumns()) / 6) * (count($this->getMatchers()) / 6);

        $query->limit($limit);
        if ($this->getDatabaseDriver() !== 'sqlsrv') {
            $query->havingRaw('relevance >= ' . number_format($this->threshold, 2, '.', ''));
        }
        $query->orderBy('relevance', 'desc');
    }

    /**
     * Makes the query not repeat the results.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    protected function makeGroupBy(Builder $query)
    {
        if ($this->getDatabaseDriver() === 'sqlsrv') {
            return;
        }
        
        if ($groupBy = $this->getGroupBy()) {
            $query->groupBy($groupBy);
        } else {
            $columns = $this->getTable() . '.' . $this->primaryKey;

            $query->groupBy($columns);

            $joins = array_keys($this->getJoins());

            foreach ($this->getColumns() as $column => $relevance) {
                array_map(function ($join) use ($column, $query) {
                    if (Str::contains($column, $join)) {
                        $query->groupBy($column);
                    }
                }, $joins);
            }
        }
    }

    /**
     * Returns whether or not to keep duplicates.
     *
     * @return array|bool
     */
    protected function getGroupBy()
    {
        if (array_key_exists('groupBy', $this->searchable)) {
            return $this->searchable['groupBy'];
        }

        return false;
    }

    /**
     * Adds the bindings to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $bindings
     * @param string $type
     */
    protected function addBindingsToQuery(Builder $query, array $bindings, $type = 'having')
    {
        foreach ($bindings as $binding) {
            $query->addBinding($binding, $type);
        }
    }

    /**
     * Merge our cloned query builder with the original one.
     *
     * @param \Illuminate\Database\Eloquent\Builder $clone
     * @param \Illuminate\Database\Eloquent\Builder $original
     */
    protected function mergeQueries(Builder $clone, Builder $original)
    {
        $tableName = DB::connection($this->connection)->getTablePrefix() . $this->getTable();
        $original->fromSub($clone, $tableName);
    }

    /**
     * Returns the table columns.
     *
     * @return array
     */
    public function getTableColumns()
    {
        return $this->searchable['table_columns'];
    }

    /**
     * Matches Strings that begin with the search string.
     *
     * For example, a search for 'hel' would match; 'Hello World' or 'helping hand'
     *
     * @param string $query
     */
    protected function startofStringMatcher($query)
    {
        $this->operator = 'LIKE';
        $this->searchString = "$query%";
        $this->search_bindings[] = $this->searchString;
        $this->matcherQuery = null;
    }

    /**
     * Matches strings for Acronym 'like' matches but does NOT return Studly Case Matches.
     *
     * for example, a search for 'fb' would match; 'foo bar' or 'Fred Brown' but not 'FreeBeer'.
     *
     * @param string $query
     */
    protected function acronymMatcher($query)
    {
        $this->operator = 'LIKE';

        $query = preg_replace('/[^0-9a-zA-Z]/', '', $query);
        $this->searchString = implode('% ', str_split(strtoupper($query))) . '%';
        $this->search_bindings[] = $this->searchString;

        $this->matcherQuery = null;
    }

    /**
     * Matches strings that include all the characters in the search relatively position within the string.
     * It also calculates the percentage of characters in the string that are matched and applies the multiplier
     * accordingly.
     *
     * For Example, a search for 'fba' would match; 'Foo Bar' or 'Afraid of bats'
     *
     * @param string $query
     * @param string $column
     * @param int|float $relevance
     */
    protected function consecutiveCharactersMatcher($query, $column, $relevance)
    {
        $this->operator = 'LIKE';
        $this->searchString = '%' . implode('%', str_split(preg_replace('/[^0-9a-zA-Z]/', '', $query))) . '%';
        $this->search_bindings[] = $this->searchString;
        $this->search_bindings[] = $query;
        $column = str_replace('.', "{$this->getDelimiter()}.{$this->getDelimiter()}", $column);

        $this->matcherQuery = "CASE WHEN REPLACE({$this->getDelimiter()}$column{$this->getDelimiter()}, '\.', '') $this->operator ? THEN ROUND($relevance * ( {$this->getLengthMethod()}( ? ) / {$this->getLengthMethod()}( REPLACE({$this->getDelimiter()}$column{$this->getDelimiter()}, ' ', '') )), 0) ELSE 0 END";
    }

    /**
     * Get the proper length method depending on driver.
     *
     * @return string
     */
    protected function getLengthMethod()
    {
        if ($this->getDatabaseDriver() === 'sqlite') {
            return 'LENGTH';
        }

        if ($this->getDatabaseDriver() === 'sqlsrv') {
            return 'LEN';
        }

        return 'CHAR_LENGTH';
    }

    /**
     * Matches the start of each word against each word in a search.
     *
     * For example, a search for 'jo ta' would match; 'John Taylor' or 'Joshua B. Takashi'
     *
     * @param string $query
     */
    protected function startOfWordsMatcher($query)
    {
        $this->operator = 'LIKE';
        $this->searchString = str_replace(' ', '% ', $query) . '%';
        $this->search_bindings[] = $this->searchString;
        $this->matcherQuery = null;
    }

    /**
     * Matches against occurrences of a string that sounds like another string.
     *
     * For example, a search for 'aarron' would match 'Aaron'
     *
     * @param string $query
     */
    protected function similarStringMatcher($query)
    {
        $this->operator = 'SOUNDS LIKE';
        $this->searchString = "$query";
        $this->search_bindings[] = $this->searchString;
        $this->matcherQuery = null;
    }

    /**
     * Matches a string based on how many times the search string appears inside
     * the string. It then applies the multiplier for each occurrence.
     *
     * For example, a search for 'tha' would match; 'I hope that that cat has caught that mouse' (3 x multiplier) or
     * 'Thanks, it was great!' (1 x multiplier)
     *
     * @param string $query
     * @param string $column
     * @param int|float $relevance
     */
    protected function timesInStringMatcher($query, $column, $relevance)
    {
        $this->search_bindings[] = $query;
        $this->search_bindings[] = $query;
        $column = str_replace('.', "{$this->getDelimiter()}.{$this->getDelimiter()}", $column);

        $this->matcherQuery = "($relevance * ROUND(({$this->getLengthMethod()}(COALESCE({$this->getDelimiter()}$column{$this->getDelimiter()}, '')) - {$this->getLengthMethod()}( REPLACE( LOWER(COALESCE({$this->getDelimiter()}$column{$this->getDelimiter()}, '')), lower(?), ''))) / {$this->getLengthMethod()}(?), 0))";
    }

    /**
     * Get the config for the package.
     *
     * @return array
     */
    protected function getSearchableConfig()
    {
        return app('config')->get('searchable');
    }
}
