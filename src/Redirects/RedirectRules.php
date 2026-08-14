<?php

declare(strict_types=1);

namespace Ruklab\Connector\Redirects;

use Ruklab\Connector\Support\ConnectorException;

/**
 * What makes a redirect safe to store, with no database and no framework in
 * sight.
 *
 * Split out because a redirect is the one change here that cannot really be
 * undone. Content has a snapshot and a rollback; a wrong 301 is cached by
 * browsers and by Google, and removing it does not un-cache it. So the rules
 * are worth testing on their own, which is what this shape buys — the same
 * reason the mapping between vocabularies lives in its own class.
 *
 * The same rules hold in the WordPress connector, deliberately. A person
 * moving between a client's WordPress and one of our sites should not have to
 * learn which one refuses chains.
 */
final class RedirectRules
{
    /**
     * 302 for the genuinely temporary, 307 to keep the method on a POST, and
     * 410 and 451 to say "gone" and "removed for legal reasons" — which take
     * no destination at all.
     */
    public const CODES = [301, 302, 307, 410, 451];

    public const CODES_WITH_TARGET = [301, 302, 307];

    public static function code(mixed $code): int
    {
        $code = (int) $code;

        if (! in_array($code, self::CODES, true)) {
            throw new ConnectorException(
                sprintf('El código %d no vale aquí. Usa uno de: %s.', $code, implode(', ', self::CODES)),
                422,
            );
        }

        return $code;
    }

    /**
     * A source has to be a path on this site.
     *
     * Taking a full URL and quietly keeping its path would be wrong when the
     * domain is not ours: a redirect starts where this site receives a
     * request, and from somebody else's domain it receives nothing.
     */
    public static function source(string $from, string $siteHost): string
    {
        $from = trim($from);

        if ($from === '') {
            throw new ConnectorException('Falta la URL de origen.', 422);
        }

        if (preg_match('#^https?://#i', $from) === 1) {
            $host = (string) parse_url($from, PHP_URL_HOST);

            if (! self::sameHost($host, $siteHost)) {
                throw new ConnectorException(
                    sprintf(
                        '«%s» no es de esta web (%s). Una redirección solo puede partir de una URL propia: '
                        .'el origen es lo que esta web recibe, y de otro dominio no recibe nada.',
                        $from,
                        $siteHost,
                    ),
                    422,
                );
            }

            $from = self::pathOf($from);
        }

        return self::tidy($from);
    }

    /**
     * A target may be a path here or a URL elsewhere — moving a page to
     * another domain is a real thing people do.
     *
     * Stored as it arrived, give or take the whitespace. Tidied the way a
     * source is, this produced the very thing the chain check exists to
     * prevent: on a site whose canonical URLs carry a trailing slash,
     * `/una-pagina` is not the page but the redirect to it, so every rule
     * written that way cost a second hop that nothing here could see — the hop
     * belongs to the site's own routing, not to a rule in this table.
     *
     * A source and a target are normalised for different reasons and must not
     * share a function. A source is tidied to be *compared*, so two spellings
     * of one origin collide. A target is stored to be *served*, and the only
     * spelling that serves in one hop is the one the site actually publishes.
     */
    public static function target(string $to, int $code): string
    {
        $to = trim($to);

        if (! in_array($code, self::CODES_WITH_TARGET, true)) {
            // A destination alongside a 410 would be stored and never used,
            // which reads later as a redirect that mysteriously does not
            // redirect.
            return '';
        }

        if ($to === '') {
            throw new ConnectorException(
                sprintf(
                    'Falta la URL de destino. Un %d lleva a algún sitio; si lo que quieres es decir que la página ya no existe, usa el código 410.',
                    $code,
                ),
                422,
            );
        }

        return $to;
    }

    /**
     * The three ways a redirect can be wrong on arrival.
     *
     * The chain check is the one that earns its keep. A redirect pointing at a
     * URL that itself redirects costs a hop to every visitor and every
     * crawler, and it happens by accident constantly, because whoever adds the
     * second one is not looking at the first.
     *
     * @param  array<int, array{id: int, from: string, to: string, status: string}>  $existing
     */
    public static function guardConflicts(string $source, string $target, array $existing, ?int $ignoreId = null): void
    {
        if ($target !== '' && self::samePath($source, $target)) {
            throw new ConnectorException(
                sprintf('«%s» apunta a sí misma, que es un bucle: el navegador daría vueltas hasta rendirse.', $source),
                422,
            );
        }

        foreach ($existing as $redirect) {
            if ($ignoreId !== null && (int) $redirect['id'] === $ignoreId) {
                continue;
            }

            if (($redirect['status'] ?? '') !== 'active') {
                continue;
            }

            if (self::samePath($redirect['from'], $source)) {
                throw new ConnectorException(
                    sprintf(
                        'Ya hay una redirección activa desde «%s», que lleva a «%s» (id %d). '
                        .'Dos reglas para el mismo origen se pisan. Si la nueva es la buena, cambia esa en vez de añadir otra.',
                        $redirect['from'],
                        $redirect['to'],
                        (int) $redirect['id'],
                    ),
                    409,
                );
            }

            if ($target !== '' && self::samePath($redirect['from'], $target)) {
                throw new ConnectorException(
                    sprintf(
                        '«%s» ya redirige a «%s», así que esto encadenaría dos saltos. '
                        .'Apunta directamente a «%s» y la cadena no llega a existir.',
                        $target,
                        $redirect['to'],
                        $redirect['to'],
                    ),
                    409,
                );
            }
        }
    }

    /**
     * Leading slash, no trailing one, query kept.
     *
     * The trailing slash goes for comparison as well as for storage, because
     * `/servicios/seo` and `/servicios/seo/` are the same page to everyone
     * except a string comparison.
     */
    public static function tidy(string $path): string
    {
        $path = trim($path);

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $path = (string) preg_replace('#/+#', '/', $path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    public static function samePath(string $one, string $other): bool
    {
        return mb_strtolower(self::tidy($one)) === mb_strtolower(self::tidy($other));
    }

    public static function status(mixed $status): string
    {
        $status = mb_strtolower(trim((string) $status));

        if (! in_array($status, ['active', 'inactive'], true)) {
            throw new ConnectorException(
                'El estado de una redirección es «active» o «inactive». Para que deje de aplicarse, ponla en inactive: el conector no borra redirecciones.',
                422,
            );
        }

        return $status;
    }

    private static function pathOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $path = $path === '' ? '/' : $path;

        return is_string($query) && $query !== '' ? $path.'?'.$query : $path;
    }

    private static function sameHost(string $one, string $other): bool
    {
        $strip = static fn (string $host): string => (string) preg_replace('#^www\.#i', '', mb_strtolower($host));

        return $strip($one) !== '' && $strip($one) === $strip($other);
    }
}
