<?php

declare(strict_types=1);

namespace Ruklab\Connector\Redirects;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ruklab\Connector\Support\ConnectorException;

/**
 * Reads and writes this site's redirects on behalf of Ruk Lab.
 *
 * Nothing here deletes. A redirect that should stop applying is deactivated,
 * which leaves the row to look at afterwards — and afterwards is exactly when
 * somebody asks why a URL stopped redirecting.
 */
final readonly class RedirectService
{
    private RedirectMap $map;

    public function __construct(?RedirectMap $map = null)
    {
        $this->map = $map ?? RedirectMap::fromConfig();
    }

    public function available(): bool
    {
        return (bool) config('ruklab.redirects.enabled', true);
    }

    /**
     * Who holds the redirects, as `info` reports it.
     */
    public function manager(): ?string
    {
        if (! $this->available()) {
            return null;
        }

        return $this->map->isSiteTable() ? 'site' : 'ruklab';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $search = null): array
    {
        $this->requireAvailable();

        $query = $this->query()->orderByDesc((new ($this->map->model))->getKeyName());

        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $source = $this->map->column('source');
            $target = $this->map->column('target');

            $query->where(function ($query) use ($term, $source, $target): void {
                $query->where($source, 'like', $term)->orWhere($target, 'like', $term);
            });
        }

        return $query->get()->map(fn (Model $redirect): array => $this->map->present($redirect))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $from, string $to, mixed $code = 301): array
    {
        $this->requireAvailable();
        $this->requireWrites();

        $code = RedirectRules::code($code, $this->map->codes);
        $source = $this->map->source(RedirectRules::source($from, $this->host()));
        $target = RedirectRules::target($to, $code);

        RedirectRules::guardConflicts($source, $target, $this->map->exactOnly($this->all()));

        $redirect = $this->query()->create($this->map->newRow($source, $target, $code));

        return $this->map->present($redirect->refresh());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(int|string $id, array $values): array
    {
        $this->requireAvailable();
        $this->requireWrites();

        $redirect = $this->query()->find($id);

        if (! $redirect instanceof Model) {
            throw ConnectorException::notFound('redirección', $id);
        }

        $before = $this->map->present($redirect);
        $changes = [];

        if (($values['status'] ?? null) !== null) {
            $changes['status'] = RedirectRules::status($values['status']);
            $redirect->{$this->map->column('active')} = $changes['status'] === 'active';
        }

        $code = ($values['code'] ?? null) !== null
            ? RedirectRules::code($values['code'], $this->map->codes)
            : $before['code'];

        if (($values['code'] ?? null) !== null) {
            $changes['code'] = $code;
            $redirect->{$this->map->column('code')} = $code;
        }

        if (($values['to'] ?? null) !== null) {
            $target = RedirectRules::target((string) $values['to'], $code);

            RedirectRules::guardConflicts(
                $before['from'],
                $target,
                $this->map->exactOnly($this->all()),
                (int) $redirect->getKey(),
            );

            $changes['to'] = $target;
            $redirect->{$this->map->column('target')} = $this->map->target($target);
        }

        if ($changes === []) {
            throw new ConnectorException(
                'No has mandado nada que cambiar. Se puede cambiar «to», «code» y «status».',
                422,
            );
        }

        // What arrived and what is already stored are not the same question. A
        // field sent with the value it already holds is not a change, and
        // reporting it as one says the site was edited when it was not — worse
        // than a refusal, because whoever reads it stops looking.
        $asked = array_keys($changes);
        $changes = array_diff_assoc($changes, array_intersect_key($before, $changes));

        if ($changes === []) {
            throw new ConnectorException(
                sprintf(
                    'Esta redirección ya está así, o sea que no hay nada que cambiar: %s. No se ha tocado nada.',
                    implode(', ', array_map(
                        fn (string $field): string => sprintf(
                            '%s = %s',
                            $field,
                            (string) $before[$field] === '' ? '(vacío)' : (string) $before[$field],
                        ),
                        $asked,
                    )),
                ),
                409,
            );
        }

        $redirect->save();

        return [
            'before' => $before,
            'after' => $this->map->present($redirect->refresh()),
            'changed' => array_keys($changes),
        ];
    }

    /**
     * The rule that applies to a path right now, or null.
     *
     * Used by the middleware on a request that found nothing, so a redirect
     * never shadows a page this site actually serves.
     */
    public function match(string $path): ?Redirect
    {
        // A site with a table of its own serves it with its own middleware,
        // which knows its match types and its cache. Answering here as well
        // would be a second opinion about the same URL.
        if (! $this->available() || $this->map->isSiteTable()) {
            return null;
        }

        $tidied = RedirectRules::tidy($path);

        return Redirect::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Redirect $redirect): bool => RedirectRules::samePath($redirect->source, $tidied));
    }

    /**
     * @return Builder<Model>
     */
    private function query(): Builder
    {
        return (new ($this->map->model))->newQuery();
    }

    private function host(): string
    {
        $url = (string) (config('ruklab.base_url') ?: config('app.url'));

        return (string) parse_url($url, PHP_URL_HOST);
    }

    private function requireAvailable(): void
    {
        if (! $this->available()) {
            throw new ConnectorException(
                'Esta web tiene las redirecciones del conector desactivadas, así que las gestiona por su cuenta.',
                409,
            );
        }
    }

    private function requireWrites(): void
    {
        if (! config('ruklab.writes_enabled', false)) {
            throw ConnectorException::readOnly();
        }
    }
}
