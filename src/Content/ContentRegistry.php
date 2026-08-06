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
            ];
        }

        return $described;
    }
}
