<?php

declare(strict_types=1);

namespace Ruklab\Connector\Redirects;

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
    public function available(): bool
    {
        return (bool) config('ruklab.redirects.enabled', true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $search = null): array
    {
        $this->requireAvailable();

        $query = Redirect::query()->orderByDesc('id');

        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(function ($query) use ($term): void {
                $query->where('source', 'like', $term)->orWhere('target', 'like', $term);
            });
        }

        return $query->get()->map(fn (Redirect $redirect): array => $redirect->present())->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $from, string $to, mixed $code = 301): array
    {
        $this->requireAvailable();
        $this->requireWrites();

        $code = RedirectRules::code($code);
        $source = RedirectRules::source($from, $this->host());
        $target = RedirectRules::target($to, $code);

        RedirectRules::guardConflicts($source, $target, $this->all());

        $redirect = Redirect::query()->create([
            'source' => $source,
            'target' => $target,
            'code' => $code,
            'is_active' => true,
            'hits' => 0,
        ]);

        return $redirect->present();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(int|string $id, array $values): array
    {
        $this->requireAvailable();
        $this->requireWrites();

        $redirect = Redirect::query()->find($id);

        if (! $redirect instanceof Redirect) {
            throw ConnectorException::notFound('redirección', $id);
        }

        $before = $redirect->present();
        $changes = [];

        if (($values['status'] ?? null) !== null) {
            $changes['status'] = RedirectRules::status($values['status']);
            $redirect->is_active = $changes['status'] === 'active';
        }

        $code = ($values['code'] ?? null) !== null
            ? RedirectRules::code($values['code'])
            : (int) $redirect->code;

        if (($values['code'] ?? null) !== null) {
            $changes['code'] = $code;
            $redirect->code = $code;
        }

        if (($values['to'] ?? null) !== null) {
            $target = RedirectRules::target((string) $values['to'], $code);

            RedirectRules::guardConflicts($before['from'], $target, $this->all(), (int) $redirect->getKey());

            $changes['to'] = $target;
            $redirect->target = $target;
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
            'after' => $redirect->refresh()->present(),
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
        if (! $this->available()) {
            return null;
        }

        $tidied = RedirectRules::tidy($path);

        return Redirect::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Redirect $redirect): bool => RedirectRules::samePath($redirect->source, $tidied));
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
