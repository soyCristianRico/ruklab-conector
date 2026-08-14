<?php

declare(strict_types=1);

namespace Ruklab\Connector\Redirects;

use Illuminate\Database\Eloquent\Model;

/**
 * A redirect this site serves.
 *
 * WordPress has none of its own either — there it is always some plugin's
 * feature. On a site of ours there is no plugin to borrow, so the connector
 * brings the table and the middleware that reads it. That is the difference
 * between the two sides of this package: on WordPress it drives somebody
 * else's redirects, here it holds them.
 *
 * @property int $id
 * @property string $source
 * @property string $target
 * @property int $code
 * @property bool $is_active
 * @property int $hits
 */
final class Redirect extends Model
{
    protected $table = 'ruklab_redirects';

    protected $fillable = ['source', 'target', 'code', 'is_active', 'hits', 'last_used_at'];

    /**
     * The shape the platform reads, which is the same one the WordPress
     * connector answers with. A tool should not be able to tell which kind of
     * site replied.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'from' => $this->source,
            'to' => (string) $this->target,
            'code' => (int) $this->code,
            'status' => $this->is_active ? 'active' : 'inactive',
            'hits' => (int) $this->hits,
            'manager' => 'ruklab',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => 'integer',
            'is_active' => 'boolean',
            'hits' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }
}
