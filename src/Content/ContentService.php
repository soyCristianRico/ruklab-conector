<?php

declare(strict_types=1);

namespace Ruklab\Connector\Content;

use Illuminate\Database\Eloquent\Model;
use Ruklab\Connector\Support\ConnectorException;
use Ruklab\Connector\Support\Snapshot;

/**
 * Reads and writes the content of this site on behalf of Ruk Lab.
 *
 * Deliberately smaller than its WordPress counterpart. There is no layout tree
 * to parse, no meta field WordPress escapes on the way in, no rendered copy of
 * the same text living somewhere else. A record is a row, and Eloquent already
 * knows how to write a row without eating anything.
 *
 * What it keeps from that one is the shape of the guarantees: nothing outside
 * the declared surface can be touched, nothing is deleted, and a copy is taken
 * before every change.
 */
final readonly class ContentService
{
    public function __construct(
        private ContentRegistry $registry = new ContentRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(string $typeName, array $filters = []): array
    {
        $type = $this->registry->get($typeName);
        $query = $type->newModel()->newQuery();

        if (! empty($filters['search'])) {
            $this->applySearch($query, $type, (string) $filters['search']);
        }

        if (isset($filters['status']) && $filters['status'] !== '' && $type->status !== null) {
            $query->where($type->status, ContentType::isLive($filters['status']));
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);

        $total = (clone $query)->count();

        $records = $query
            ->orderByDesc($type->newModel()->getKeyName())
            ->forPage($page, $perPage)
            ->get();

        return [
            'type' => $typeName,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'records' => $records->map(fn (Model $record): array => $type->present($record))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $typeName, int|string $id): array
    {
        $type = $this->registry->get($typeName);

        return $type->present($this->find($type, $typeName, $id));
    }

    /**
     * Create a record. This is what publishing an article from Ruk Lab lands
     * on: the site is no longer a mailbox that receives a webhook and answers
     * with a URL, it holds content that can be read back and corrected later.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function create(string $typeName, array $values): array
    {
        $this->requireWrites();

        $type = $this->registry->get($typeName);
        $columns = $this->columnsFor($type, $values);

        // A new record with no status column is live the moment it is saved.
        // One that has a status starts as a draft unless asked otherwise, so
        // publishing is always somebody's explicit decision.
        if ($type->status !== null && ! array_key_exists('status', $values)) {
            $columns[$type->status] = false;
        }

        $record = $type->newModel()->newQuery()->create($columns);

        return $type->present($record);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(string $typeName, int|string $id, array $values): array
    {
        $this->requireWrites();

        $type = $this->registry->get($typeName);
        $record = $this->find($type, $typeName, $id);
        $columns = $this->columnsFor($type, $values);

        $snapshot = Snapshot::take($type->model, $record->getKey(), $record->getOriginal());

        $before = $type->present($record);

        $record->fill($columns)->save();

        return [
            'before' => $before,
            'after' => $type->present($record->refresh()),
            'changed' => array_keys($columns),
            'snapshot_id' => $snapshot,
        ];
    }

    /**
     * Put a record back as a copy left it.
     *
     * @return array<string, mixed>
     */
    public function rollback(int $snapshotId): array
    {
        $this->requireWrites();

        $snapshot = Snapshot::find($snapshotId);

        if ($snapshot === null) {
            throw new ConnectorException(
                sprintf('No existe ninguna copia con el id %d. Puede que haya caducado.', $snapshotId),
                404,
            );
        }

        $typeName = $this->typeNameFor($snapshot->model);
        $type = $this->registry->get($typeName);
        $record = $this->find($type, $typeName, $snapshot->record_id);

        // The copy of the current state comes first, so undoing can be undone.
        Snapshot::take($type->model, $record->getKey(), $record->getOriginal());

        $record->forceFill($this->restorable($type, $snapshot->values))->save();

        return [
            'type' => $typeName,
            'restored' => $snapshotId,
            'record' => $type->present($record->refresh()),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function snapshots(string $typeName, int|string $id): array
    {
        $type = $this->registry->get($typeName);

        return Snapshot::forRecord($type->model, $id);
    }

    /**
     * Only the columns this type declared. A snapshot could carry a column
     * that has since been taken off the map, and restoring it would put back
     * something the site decided was out of reach.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function restorable(ContentType $type, array $values): array
    {
        $allowed = array_values($type->fields);

        if ($type->status !== null) {
            $allowed[] = $type->status;
        }

        return array_intersect_key($values, array_flip($allowed));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function columnsFor(ContentType $type, array $values): array
    {
        foreach (array_keys($values) as $field) {
            if ($field === 'status' && $type->status !== null) {
                continue;
            }

            if (! in_array($field, $type->writable(), true)) {
                throw ConnectorException::notWritable((string) $field, $type->writable());
            }
        }

        $columns = $type->toColumns($values);

        if ($columns === []) {
            throw ConnectorException::nothingToChange();
        }

        $rejected = $type->unfillableColumns($columns);

        if ($rejected !== []) {
            throw ConnectorException::notFillable($type->model, $rejected);
        }

        return $columns;
    }

    private function find(ContentType $type, string $typeName, int|string $id): Model
    {
        $record = $type->newModel()->newQuery()->find($id);

        if (! $record instanceof Model) {
            throw ConnectorException::notFound($typeName, $id);
        }

        return $record;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function typeNameFor(string $model): string
    {
        foreach ($this->registry->all() as $name => $type) {
            if ($type->model === $model) {
                return $name;
            }
        }

        throw new ConnectorException(
            'Esa copia pertenece a un tipo de contenido que esta web ya no expone.',
            409,
        );
    }

    private function requireWrites(): void
    {
        if (! config('ruklab.writes_enabled', false)) {
            throw ConnectorException::readOnly();
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Model>  $query
     */
    private function applySearch($query, ContentType $type, string $term): void
    {
        $columns = array_values(array_intersect_key(
            $type->fields,
            array_flip(['title', 'excerpt', 'slug', 'content']),
        ));

        $query->where(function ($query) use ($columns, $term): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }
}
