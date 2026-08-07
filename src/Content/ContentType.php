<?php

declare(strict_types=1);

namespace Ruklab\Connector\Content;

use Illuminate\Database\Eloquent\Model;
use Ruklab\Connector\Support\ConnectorException;
use Ruklab\Connector\Support\Value;

/**
 * One kind of content this site holds, and how its fields are named here.
 *
 * The point of the indirection is that Ruk Lab speaks one vocabulary —
 * `title`, `content`, `excerpt`, `slug` — while each site calls those things
 * whatever its own models call them. Without the map, either every site has to
 * rename its columns or the platform has to learn eight sets of names.
 *
 * It is also the extension point. A site with something of its own worth
 * editing — a course, a listing, a property — registers it here with the same
 * shape, and every tool that already exists reaches it. No new code on either
 * side.
 */
final readonly class ContentType
{
    /**
     * Fields Ruk Lab knows how to ask for. A type maps the ones it has and
     * leaves out the rest; a page with no excerpt simply does not list one.
     */
    public const KNOWN_FIELDS = [
        'title',
        'content',
        'excerpt',
        'slug',
        'meta_title',
        'meta_description',
        'author',
        'published_at',
    ];

    /**
     * A site of ours has two states, live or not, held in one boolean column.
     * Ruk Lab speaks WordPress' vocabulary, which has five words for this and
     * spells the first one `publish`, so the words have to be met halfway.
     *
     * What is not here is refused rather than guessed. `pending`, `private` and
     * `future` mean something on a WordPress that has nowhere to land here, and
     * reading them as "not published" would take a page down on the way to
     * doing something else entirely.
     */
    private const LIVE_WORDS = ['publish', 'published', 'live', 'active', 'visible'];

    private const NOT_LIVE_WORDS = ['draft', 'unpublished', 'inactive', 'hidden'];

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $fields  Ruk Lab's name => this site's column.
     * @param  string|null  $status  Column that says whether it is live, if any.
     * @param  array<int, string>  $readonly  Mapped fields that must not be written.
     * @param  string|null  $url  Path pattern for the public page, `/blog/{slug}`.
     */
    public function __construct(
        public string $model,
        public string $label,
        public array $fields,
        public ?string $status = null,
        public array $readonly = [],
        public ?string $url = null,
    ) {}

    /**
     * Rebuild from `var_export`, which is how `config:cache` stores this.
     *
     * Every deployment runs that command, and without this it fails outright:
     * PHP exports an object as a call to `__set_state` and gives up when the
     * class has none. A type declared in a config file has to survive being
     * written out as PHP and read back, and this is the hook that lets it.
     *
     * @param  array<string, mixed>  $state
     */
    public static function __set_state(array $state): self
    {
        return new self(
            model: $state['model'],
            label: $state['label'],
            fields: $state['fields'],
            status: $state['status'] ?? null,
            readonly: $state['readonly'] ?? [],
            url: $state['url'] ?? null,
        );
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $fields
     * @param  array<int, string>  $readonly
     */
    public static function make(
        string $model,
        string $label,
        array $fields,
        ?string $status = null,
        array $readonly = [],
        ?string $url = null,
    ): self {
        return new self($model, $label, $fields, $status, $readonly, $url);
    }

    /**
     * Where this record lives on the public site.
     *
     * Publishing an article has to answer with a link somebody can open — it
     * is the first thing anyone asks for after "done". The site is the only
     * one who knows how its own URLs are built, so it says here, once per
     * type, instead of the platform guessing from a slug.
     */
    public function urlFor(Model $record): ?string
    {
        if ($this->url === null) {
            return null;
        }

        $path = preg_replace_callback(
            '/\{(\w+)\}/',
            function (array $matches) use ($record): string {
                $column = $this->fields[$matches[1]] ?? $matches[1];

                return (string) Value::plain($record->{$column});
            },
            $this->url,
        );

        return rtrim(self::baseUrl(), '/').'/'.ltrim((string) $path, '/');
    }

    /**
     * Where this site's public pages live.
     *
     * Usually the app's own URL, but not always: Ruk Lab's blog is served from
     * a different domain than the application, and a link built from the wrong
     * one is worse than no link at all.
     */
    private static function baseUrl(): string
    {
        $base = config('ruklab.base_url');

        return is_string($base) && $base !== '' ? $base : (string) config('app.url');
    }

    /**
     * Whether this site actually has the model. A template that ships without
     * one — no landings, no menus — should report the type as unavailable
     * rather than fail when someone asks for it.
     */
    public function exists(): bool
    {
        return class_exists($this->model);
    }

    /**
     * The column behind one of Ruk Lab's field names, or null when this type
     * has no such field.
     */
    public function column(string $field): ?string
    {
        return $this->fields[$field] ?? null;
    }

    /**
     * Fields that can be written: everything mapped, minus what the site
     * marked read-only, minus anything that is not a field Ruk Lab knows.
     *
     * @return array<int, string>
     */
    public function writable(): array
    {
        $writable = array_intersect(array_keys($this->fields), self::KNOWN_FIELDS);

        return array_values(array_diff($writable, $this->readonly, $this->structuredFields()));
    }

    /**
     * @return array<int, string>
     */
    public function readable(): array
    {
        return array_values(array_intersect(array_keys($this->fields), self::KNOWN_FIELDS));
    }

    public function newModel(): Model
    {
        /** @var Model */
        return new $this->model;
    }

    /**
     * Turn a record into Ruk Lab's vocabulary.
     *
     * @return array<string, mixed>
     */
    public function present(Model $record): array
    {
        $presented = ['id' => $record->getKey()];

        foreach ($this->readable() as $field) {
            $presented[$field] = Value::plain($record->{$this->fields[$field]});
        }

        if ($this->status !== null) {
            $presented['status'] = $record->{$this->status} ? 'published' : 'draft';
        }

        $url = $this->urlFor($record);

        if ($url !== null) {
            $presented['url'] = $url;
        }

        return $presented;
    }

    /**
     * Casts that mean a column holds a structure, not text.
     *
     * Ruk Lab sends fields as strings. Writing a string into one of these makes
     * Eloquent encode the string itself — a page built of blocks becomes the
     * JSON of a paragraph, and the page stops rendering. Reading one is
     * harmless; writing one is not, so these are read-only whatever the map
     * says.
     */
    private const STRUCTURED_CASTS = '/^(array|json|object|collection|encrypted:(array|object|collection)|Illuminate\\\\Database\\\\Eloquent\\\\Casts\\\\As)/i';

    /**
     * Mapped fields whose column holds a structure rather than text.
     *
     * @return array<int, string>
     */
    public function structuredFields(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $casts = $this->newModel()->getCasts();

        return array_values(array_filter(
            array_keys($this->fields),
            fn (string $field): bool => isset($casts[$this->fields[$field]])
                && preg_match(self::STRUCTURED_CASTS, (string) $casts[$this->fields[$field]]) === 1,
        ));
    }

    /**
     * Which of these columns the model will not accept.
     *
     * `fill()` drops a column missing from a model's `$fillable` and says
     * nothing about it. A type that maps such a column would answer 200, report
     * the field as changed, and have changed nothing — the worst shape a
     * failure can take, because it looks like success on both ends.
     *
     * @param  array<string, mixed>  $columns
     * @return array<int, string>
     */
    public function unfillableColumns(array $columns): array
    {
        $model = $this->newModel();

        return array_values(array_filter(
            array_keys($columns),
            fn (string $column): bool => ! $model->isFillable($column),
        ));
    }

    /**
     * Read a status as live or not live, or refuse it.
     *
     * The comparison used to be `$value === 'published'`, which meant every
     * other word — `publish` among them, the one Ruk Lab's own tool documents —
     * quietly took the record offline. Asking to publish unpublished.
     */
    public static function isLive(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $word = mb_strtolower(trim((string) $value));

        if (in_array($word, self::LIVE_WORDS, true) || $word === '1') {
            return true;
        }

        if (in_array($word, self::NOT_LIVE_WORDS, true) || $word === '0') {
            return false;
        }

        throw ConnectorException::unknownStatus($word, [...self::LIVE_WORDS, ...self::NOT_LIVE_WORDS]);
    }

    /**
     * Turn Ruk Lab's vocabulary back into this site's columns.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function toColumns(array $values): array
    {
        $columns = [];

        foreach ($values as $field => $value) {
            if ($field === 'status' && $this->status !== null) {
                $columns[$this->status] = self::isLive($value);

                continue;
            }

            if (in_array($field, $this->writable(), true)) {
                $columns[$this->fields[$field]] = $value;
            }
        }

        return $columns;
    }
}
