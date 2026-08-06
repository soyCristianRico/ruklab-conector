<?php

declare(strict_types=1);

namespace Ruklab\Connector\Content;

use Illuminate\Database\Eloquent\Model;
use Ruklab\Connector\Support\ConnectorException;
use Ruklab\Connector\Support\Snapshot;

/**
 * Navigation menus, where the site keeps them in the database.
 *
 * Not every template does: some carry their navigation in the theme, and this
 * has to say so rather than fail at it. Same rule the WordPress connector
 * follows for a site whose user cannot edit theme options — the answer is a
 * sentence naming the reason, and the rest of the content keeps working.
 */
final readonly class MenuService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function tree(?string $location = null): array
    {
        $items = $this->query($location)->get();

        return $this->nest($items->all(), null);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function create(array $values): array
    {
        $this->requireWrites();

        $model = $this->model();
        $item = $model->newQuery()->create($this->columns($values));

        return $this->present($item);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(int|string $id, array $values): array
    {
        $this->requireWrites();

        $item = $this->find($id);
        $columns = $this->columns($values);

        Snapshot::take($item::class, $item->getKey(), $item->getOriginal());

        $item->fill($columns)->save();

        return $this->present($item->refresh());
    }

    /**
     * Reorder by listing ids in the order they should appear.
     *
     * Only the entries that actually move are written, so putting back an
     * order that already holds costs nothing and leaves no trail of changes
     * that changed nothing.
     *
     * @param  array<int, int|string>  $orderedIds
     * @return array<int, array<string, mixed>>
     */
    public function reorder(array $orderedIds, ?string $location = null): array
    {
        $this->requireWrites();

        $fields = $this->fields();
        $position = $fields['position'] ?? null;

        if ($position === null) {
            throw new ConnectorException('Los menús de esta web no guardan un orden que se pueda cambiar.', 409);
        }

        $items = $this->query($location)->get()->keyBy(fn (Model $item): string => (string) $item->getKey());
        $moved = [];

        foreach (array_values($orderedIds) as $index => $id) {
            $item = $items->get((string) $id);

            if (! $item instanceof Model) {
                throw new ConnectorException(
                    sprintf('El elemento %s no está en este menú. Reordena solo con los que ya están.', $id),
                    422,
                );
            }

            if ((int) $item->{$position} === $index + 1) {
                continue;
            }

            Snapshot::take($item::class, $item->getKey(), $item->getOriginal());
            $item->{$position} = $index + 1;
            $item->save();

            $moved[] = $item->getKey();
        }

        return ['moved' => $moved, 'items' => $this->tree($location)];
    }

    public function available(): bool
    {
        $model = config('ruklab.menus.model');

        return is_string($model) && class_exists($model);
    }

    /**
     * @param  array<int, Model>  $items
     * @return array<int, array<string, mixed>>
     */
    private function nest(array $items, int|string|null $parent): array
    {
        $fields = $this->fields();
        $parentColumn = $fields['parent'] ?? null;
        $branch = [];

        foreach ($items as $item) {
            $itemParent = $parentColumn !== null ? $item->{$parentColumn} : null;

            if ((string) $itemParent !== (string) $parent) {
                continue;
            }

            $branch[] = [
                ...$this->present($item),
                'children' => $this->nest($items, $item->getKey()),
            ];
        }

        return $branch;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Model $item): array
    {
        $presented = ['id' => $item->getKey()];

        foreach ($this->fields() as $field => $column) {
            $value = $item->{$column};
            $presented[$field] = is_scalar($value) || $value === null ? $value : (string) $value;
        }

        $status = config('ruklab.menus.status');

        if (is_string($status)) {
            $presented['status'] = $item->{$status} ? 'published' : 'draft';
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function columns(array $values): array
    {
        $fields = $this->fields();
        $columns = [];

        foreach ($values as $field => $value) {
            if (! isset($fields[$field])) {
                throw new ConnectorException(
                    sprintf('El campo «%s» no se puede cambiar en un elemento de menú.', $field),
                    403,
                );
            }

            $columns[$fields[$field]] = $value;
        }

        if ($columns === []) {
            throw ConnectorException::nothingToChange();
        }

        return $columns;
    }

    /**
     * @return array<string, string>
     */
    private function fields(): array
    {
        $fields = config('ruklab.menus.fields', []);

        return is_array($fields) ? $fields : [];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    private function query(?string $location)
    {
        $query = $this->model()->newQuery();
        $fields = $this->fields();

        if ($location !== null && isset($fields['location'])) {
            $query->where($fields['location'], $location);
        }

        if (isset($fields['position'])) {
            $query->orderBy($fields['position']);
        }

        return $query;
    }

    private function find(int|string $id): Model
    {
        $item = $this->model()->newQuery()->find($id);

        if (! $item instanceof Model) {
            throw ConnectorException::notFound('elemento de menú', $id);
        }

        return $item;
    }

    private function model(): Model
    {
        if (! $this->available()) {
            throw ConnectorException::menusUnavailable();
        }

        $model = (string) config('ruklab.menus.model');

        /** @var Model */
        return new $model;
    }

    private function requireWrites(): void
    {
        if (! config('ruklab.writes_enabled', false)) {
            throw ConnectorException::readOnly();
        }
    }
}
