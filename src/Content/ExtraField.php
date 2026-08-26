<?php

declare(strict_types=1);

namespace Ruklab\Connector\Content;

/**
 * A field this site holds that is not part of Ruk Lab's fixed vocabulary —
 * its own thing, the way a news item has a source or a category that a blog
 * post does not.
 *
 * `ContentType::extra` is where one of these gets registered, the same spirit
 * as `fields` but without the pretence that eight names cover every site: a
 * type declares whatever it actually has, named however it wants, and it
 * travels under `meta` instead of colliding with the fixed vocabulary.
 *
 * A `relation` field is never sent or shown as this site's internal id — that
 * number means nothing outside this site. It travels as the value of
 * `matchColumn` on the related model instead, resolved on the way in and
 * looked up on the way out, the same idea `terms` already uses on the
 * WordPress side for taxonomies.
 */
final readonly class ExtraField
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>|null  $relatedModel
     * @param  array<int, string>  $options
     */
    public function __construct(
        public string $column,
        public string $label,
        public ExtraFieldType $type = ExtraFieldType::Text,
        public bool $required = false,
        public ?string $relatedModel = null,
        public ?string $matchColumn = null,
        public array $options = [],
    ) {}

    /**
     * Rebuild from `var_export`, the same reason `ContentType` needs it: this
     * is declared inside `config/ruklab.php`, and `config:cache` writes every
     * config value out as PHP on every deployment.
     *
     * @param  array<string, mixed>  $state
     */
    public static function __set_state(array $state): self
    {
        return new self(
            column: $state['column'],
            label: $state['label'],
            type: $state['type'] ?? ExtraFieldType::Text,
            required: $state['required'] ?? false,
            relatedModel: $state['relatedModel'] ?? null,
            matchColumn: $state['matchColumn'] ?? null,
            options: $state['options'] ?? [],
        );
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>|null  $relatedModel
     * @param  array<int, string>  $options
     */
    public static function make(
        string $column,
        string $label,
        ExtraFieldType $type = ExtraFieldType::Text,
        bool $required = false,
        ?string $relatedModel = null,
        ?string $matchColumn = null,
        array $options = [],
    ): self {
        return new self($column, $label, $type, $required, $relatedModel, $matchColumn, $options);
    }

    public static function text(string $column, string $label, bool $required = false): self
    {
        return self::make($column, $label, ExtraFieldType::Text, $required);
    }

    public static function textarea(string $column, string $label, bool $required = false): self
    {
        return self::make($column, $label, ExtraFieldType::Textarea, $required);
    }

    public static function url(string $column, string $label, bool $required = false): self
    {
        return self::make($column, $label, ExtraFieldType::Url, $required);
    }

    public static function number(string $column, string $label, bool $required = false): self
    {
        return self::make($column, $label, ExtraFieldType::Number, $required);
    }

    public static function boolean(string $column, string $label, bool $required = false): self
    {
        return self::make($column, $label, ExtraFieldType::Boolean, $required);
    }

    /**
     * @param  array<int, string>  $options
     */
    public static function select(string $column, string $label, array $options, bool $required = false): self
    {
        return self::make($column, $label, ExtraFieldType::Select, $required, options: $options);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $relatedModel
     */
    public static function relation(
        string $column,
        string $label,
        string $relatedModel,
        string $matchColumn,
        bool $required = false,
    ): self {
        return self::make($column, $label, ExtraFieldType::Relation, $required, relatedModel: $relatedModel, matchColumn: $matchColumn);
    }
}
