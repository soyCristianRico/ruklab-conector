<?php

declare(strict_types=1);

namespace Ruklab\Connector\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only thing standing between the open internet and this site's content.
 *
 * A site with no token configured serves nothing: an unconfigured connector is
 * one nobody deliberately connected, and answering would be worse than not
 * being installed. The comparison is timing-safe, because a token that can be
 * guessed one character at a time is not a token.
 */
final class AuthenticateConnector
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ruklab.token', '');

        if ($expected === '') {
            return response()->json([
                'message' => 'El conector de Ruk Lab no está configurado en esta web.',
            ], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json([
                'message' => 'Credencial no válida.',
            ], 401);
        }

        return $next($request);
    }
}
