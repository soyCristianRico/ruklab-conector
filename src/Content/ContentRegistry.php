<?php

declare(strict_types=1);

namespace Ruklab\Connector\Content;

use Ruklab\Connector\Support\ConnectorException;

/**
 * The content types this site offers, and the only door to them.
 *
 * Nothing reaches a model except through a type registered here. That is the
 * same rule the WordPress connector learned the hard way with its widget map:
 * an allowlist keeps the reachable surface equal to the declared surface, so
 * adding a model to the config is the only way to expose it, and nobody can
 * name a table in a request and have it answered.
 */
final class ContentRegistry
{
    /**
     * @return array<string, ContentType>
     */
    public function all(): array
    {
        $declared = config('ruklab.types', []);
        $types = [];

        foreach ($declared as $name => $type) {
            if ($type instanceof ContentType && $type->exists()) {
                $types[$name] = $type;
            }
        }

        return $types;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * @throws ConnectorException
     */
    public function get(string $name): ContentType
    {
        $types = $this->all();

        if (! isset($types[$name])) {
            throw ConnectorException::unknownType($name, array_keys($types));
        }

        return $types[$name];
    }

    /**
     * What ruklab.app stores as this site's capabilities, so the assistant
     * knows what is reachable before it tries.
     *
     * @return array<string, array<string, mixed>>
     */
    public function describe(): array
    {
        $described = [];

        foreach ($this->all() as $name => $type) {
            $described[$name] = [
                'label' => $type->label,
                'readable' => $type->readable(),
                'writable' => $type->writable(),
                'has_status' => $type->status !== null,
                'images' => array_keys($type->media),
                'fields' => $this->describeExtra($type),
            ];
        }

        return $described;
    }

    /**
     * This type's own fields, beyond Ruk Lab's fixed vocabulary — what to
     * call them, what kind of value they hold, and whether they are required.
     * A relation's options are looked up fresh here rather than stored on the
     * type, so a category added after the site was deployed still shows up.
     *
     * @return array<string, array<string, mixed>>
     */
    private function describeExtra(ContentType $type): array
    {
        $described = [];

        foreach ($type->extra as $name => $field) {
            $entry = [
                'label' => $field->label,
                'type' => $field->type->value,
                'required' => $field->required,
            ];

            $options = $this->optionsFor($field);

            if ($options !== []) {
                $entry['options'] = $options;
            }

            $described[$name] = $entry;
        }

        return $described;
    }

    /**
     * @return array<int, string>
     */
    private function optionsFor(ExtraField $field): array
    {
        if ($field->type === ExtraFieldType::Select) {
            return $field->options;
        }

        if ($field->type === ExtraFieldType::Relation && $field->relatedModel !== null && $field->matchColumn !== null) {
            return $field->relatedModel::query()->limit(50)->pluck($field->matchColumn)->all();
        }

        return [];
    }
}
