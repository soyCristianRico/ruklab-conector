<?php

declare(strict_types=1);

namespace Ruklab\Connector\Redirects;

use Illuminate\Database\Eloquent\Model;
use Ruklab\Connector\Support\Value;

/**
 * Where a site's redirects live and what its columns are called.
 *
 * By default that is the table this package brings. A site that already has a
 * redirects table of its own — with a panel, a middleware and a cache in front
 * of it — declares its model under `ruklab.redirects.model`, and the connector
 * reads and writes that one instead. Two tables would be two lists that
 * disagree: whatever was created from Ruk Lab would not show in the site's
 * panel, and whatever the panel holds would be invisible from Ruk Lab.
 *
 * The same reasoning as `ContentType`: the platform speaks one vocabulary
 * (`from`, `to`, `code`, `status`) and each site keeps its own column names.
 */
final readonly class RedirectMap
{
    /**
     * Ruk Lab's name => the column, for this package's own table.
     */
    public const OWN_FIELDS = [
        'source' => 'source',
        'target' => 'target',
        'code' => 'code',
        'active' => 'is_active',
        'hits' => 'hits',
    ];

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $fields  Ruk Lab's name => this site's column. `match` is optional.
     * @param  array<int, int>  $codes  Status codes this table can hold.
     * @param  array<string, mixed>  $defaults  Columns a new row needs that Ruk Lab has no word for.
     */
    public function __construct(
        public string $model,
        public array $fields = self::OWN_FIELDS,
        public array $codes = RedirectRules::CODES,
        public array $defaults = [],
    ) {}

    public static function fromConfig(): self
    {
        $model = config('ruklab.redirects.model');

        if (! is_string($model) || $model === '') {
            return new self(Redirect::class);
        }

        $fields = config('ruklab.redirects.fields');
        $codes = config('ruklab.redirects.codes');
        $defaults = config('ruklab.redirects.defaults');

        return new self(
            model: $model,
            fields: [...self::OWN_FIELDS, ...(is_array($fields) ? $fields : [])],
            codes: is_array($codes) && $codes !== [] ? array_map(intval(...), array_values($codes)) : RedirectRules::CODES,
            defaults: is_array($defaults) ? $defaults : [],
        );
    }

    /**
     * Whether the table belongs to the site rather than to this package.
     */
    public function isSiteTable(): bool
    {
        return $this->model !== Redirect::class;
    }

    public function column(string $field): string
    {
        return $this->fields[$field] ?? $field;
    }

    /**
     * A source the way this table stores it.
     *
     * A site's own table usually compares sources exactly, and it was written
     * to receive them lower case and without a query — a row stored as pasted
     * would sit there and never match. This package's own table keeps what it
     * is given, which is what it always did.
     */
    public function source(string $source): string
    {
        if (! $this->isSiteTable()) {
            return $source;
        }

        $path = strtok($source, '?#');

        return mb_strtolower($path === false ? $source : $path);
    }

    /**
     * What Ruk Lab reads of one row, in the shape every other site answers
     * with, so a tool cannot tell which kind of table replied.
     *
     * @return array<string, mixed>
     */
    public function present(Model $redirect): array
    {
        $presented = [
            'id' => (int) $redirect->getKey(),
            'from' => (string) Value::plain($redirect->{$this->column('source')}),
            'to' => (string) Value::plain($redirect->{$this->column('target')}),
            'code' => (int) Value::plain($redirect->{$this->column('code')}),
            'status' => $redirect->{$this->column('active')} ? 'active' : 'inactive',
            'hits' => (int) $redirect->{$this->column('hits')},
            'manager' => $this->isSiteTable() ? 'site' : 'ruklab',
        ];

        if (isset($this->fields['match'])) {
            $presented['match'] = (string) Value::plain($redirect->{$this->fields['match']});
        }

        return $presented;
    }

    /**
     * A destination as the column holds it. A site's own table leaves the
     * destination null for a 410, which answers on the spot; this package's
     * own column is not nullable and keeps an empty string.
     */
    public function target(string $target): ?string
    {
        return $target === '' && $this->isSiteTable() ? null : $target;
    }

    /**
     * The columns of a new row.
     *
     * @return array<string, mixed>
     */
    public function newRow(string $source, string $target, int $code): array
    {
        return [
            ...$this->defaults,
            $this->column('source') => $this->source($source),
            $this->column('target') => $this->target($target),
            $this->column('code') => $code,
            $this->column('active') => true,
        ];
    }

    /**
     * The rows a new rule can actually collide with. A prefix or a pattern
     * answers for more than the path it is stored under, and comparing it as
     * if it were an exact path would refuse a redirect that does not conflict
     * with anything.
     *
     * @param  array<int, array<string, mixed>>  $presented
     * @return array<int, array<string, mixed>>
     */
    public function exactOnly(array $presented): array
    {
        return array_values(array_filter(
            $presented,
            fn (array $row): bool => ! isset($row['match']) || $row['match'] === 'exact',
        ));
    }
}
