<?php

declare(strict_types=1);

namespace Ruklab\Connector\Http;

use Closure;
use Illuminate\Http\Request;
use Ruklab\Connector\Redirects\RedirectService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the redirects this site holds.
 *
 * It runs after the request, on a 404 and only on a 404, which is what keeps a
 * stored rule from ever shadowing a page the site really serves. Somebody
 * adding a redirect from a URL that still works gets a rule that sits there
 * doing nothing until the page goes — which is the harmless way round.
 *
 * It has to be global rather than in the `web` group: a URL that matches no
 * route never reaches a route's middleware, and those are precisely the URLs
 * a redirect exists for.
 */
final class HandleRedirects
{
    public function __construct(private readonly RedirectService $redirects = new RedirectService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 404) {
            return $response;
        }

        // A 404 on a POST is not a moved page, and answering it with a
        // redirect would send the body somewhere it was never meant to go.
        if (! $request->isMethodCacheable()) {
            return $response;
        }

        $redirect = $this->redirects->match($request->path());

        if ($redirect === null) {
            return $response;
        }

        $redirect->forceFill([
            'hits' => (int) $redirect->hits + 1,
            'last_used_at' => now(),
        ])->save();

        if (! in_array((int) $redirect->code, [301, 302, 307], true)) {
            return response('', (int) $redirect->code);
        }

        return redirect()->away($this->destination($request, (string) $redirect->target), (int) $redirect->code);
    }

    /**
     * A stored target may be a path here or a whole URL elsewhere. The query
     * the visitor arrived with is carried over when the target has none of its
     * own, so a campaign link does not lose its tracking on the way.
     */
    private function destination(Request $request, string $target): string
    {
        $query = (string) $request->getQueryString();

        if ($query === '' || str_contains($target, '?')) {
            return $target;
        }

        return $target.'?'.$query;
    }
}
