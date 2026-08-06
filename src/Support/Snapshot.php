<?php

declare(strict_types=1);

namespace Ruklab\Connector\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * What a record held before Ruk Lab changed it.
 *
 * Same reasoning as in the WordPress connector: a change made through an API,
 * to a page nobody is looking at, is discovered late, and by then there is
 * nothing to compare against. Here it is cheaper — a row of columns rather
 * than a layout tree — so there is even less excuse not to keep one.
 *
 * @property int $id
 * @property string $model
 * @property string $record_id
 * @property array<string, mixed> $values
 */
final class Snapshot extends Model
{
    public $timestamps = false;

    protected $table = 'ruklab_snapshots';

    protected $fillable = ['model', 'record_id', 'values', 'created_at'];

    /**
     * Store the current state of a record and prune what is past retention.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $values
     */
    public static function take(string $model, int|string $recordId, array $values): int
    {
        $snapshot = self::query()->create([
            'model' => $model,
            'record_id' => (string) $recordId,
            'values' => $values,
            'created_at' => now(),
        ]);

        self::prune($model, $recordId);

        return (int) $snapshot->getKey();
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<int, array<string, mixed>>
     */
    public static function forRecord(string $model, int|string $recordId, int $limit = 20): array
    {
        return self::query()
            ->where('model', $model)
            ->where('record_id', (string) $recordId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            // The stored values are left out on purpose: a list of copies
            // should not weigh as much as the records it protects.
            ->map(fn (self $snapshot): array => [
                'id' => $snapshot->id,
                'created_at' => (string) $snapshot->created_at,
                'fields' => array_keys($snapshot->values),
            ])
            ->all();
    }

    /**
     * Kept for so many days and so many per record, whichever bites first.
     * Days alone lets a record edited all morning fill the table unnoticed.
     *
     * @param  class-string<Model>  $model
     */
    public static function prune(string $model, int|string $recordId): void
    {
        $keep = (int) config('ruklab.snapshots.per_record', 10);

        $stale = self::query()
            ->where('model', $model)
            ->where('record_id', (string) $recordId)
            ->orderByDesc('id')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            self::query()->whereIn('id', $stale)->delete();
        }

        self::query()
            ->where('created_at', '<', now()->subDays((int) config('ruklab.snapshots.days', 30)))
            ->delete();
    }

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
