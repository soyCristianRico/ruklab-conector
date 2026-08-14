<?php

declare(strict_types=1);

use Ruklab\Connector\Redirects\RedirectRules;
use Ruklab\Connector\Support\ConnectorException;

describe('RedirectRules', function () {
    describe('source', function () {
        it('treats a path with and without a trailing slash as the same', function () {
            expect(RedirectRules::source('/servicios/seo/', 'cierzo.test'))
                ->toBe(RedirectRules::source('servicios/seo', 'cierzo.test'));
        });

        it('keeps only the path when given a URL of this site', function () {
            expect(RedirectRules::source('https://cierzo.test/viejo/', 'cierzo.test'))->toBe('/viejo');
        });

        it('keeps the query string, which is part of what the visitor asked for', function () {
            expect(RedirectRules::source('https://cierzo.test/buscar?q=seo', 'cierzo.test'))->toBe('/buscar?q=seo');
        });

        it('accepts the site own domain with www in front', function () {
            expect(RedirectRules::source('https://www.cierzo.test/viejo', 'cierzo.test'))->toBe('/viejo');
        });

        it('refuses an origin on somebody else domain', function () {
            // A redirect starts where this site receives a request. From
            // another domain it receives nothing, so this is always a mistake.
            expect(fn () => RedirectRules::source('https://otra-web.com/viejo', 'cierzo.test'))
                ->toThrow(ConnectorException::class, 'no es de esta web');
        });

        it('refuses an empty origin', function () {
            expect(fn () => RedirectRules::source('  ', 'cierzo.test'))
                ->toThrow(ConnectorException::class);
        });
    });

    describe('target', function () {
        it('allows a target on another domain, which is a real move', function () {
            expect(RedirectRules::target('https://otra-web.com/nuevo', 301, 'cierzo.test'))
                ->toBe('https://otra-web.com/nuevo');
        });

        it('reduces a target on this site to its path', function () {
            expect(RedirectRules::target('https://cierzo.test/nuevo/', 301, 'cierzo.test'))->toBe('/nuevo');
        });

        it('takes no destination for a 410, and drops one that arrives anyway', function () {
            // Stored beside a 410 it would never be used, which reads later as
            // a redirect that mysteriously does not redirect.
            expect(RedirectRules::target('/algo', 410, 'cierzo.test'))->toBe('');
        });

        it('asks for a destination when the code needs one', function () {
            expect(fn () => RedirectRules::target('', 301, 'cierzo.test'))
                ->toThrow(ConnectorException::class, '410');
        });
    });

    describe('code', function () {
        it('accepts the codes that describe a move or an absence', function () {
            foreach ([301, 302, 307, 410, 451] as $code) {
                expect(RedirectRules::code($code))->toBe($code);
            }
        });

        it('refuses anything that is not one of them', function () {
            expect(fn () => RedirectRules::code(200))->toThrow(ConnectorException::class);
        });
    });

    describe('guardConflicts', function () {
        it('refuses a redirect to itself', function () {
            expect(fn () => RedirectRules::guardConflicts('/pagina', '/pagina/', []))
                ->toThrow(ConnectorException::class, 'bucle');
        });

        it('refuses a second redirect from an origin that already has one', function () {
            $existing = [['id' => 3, 'from' => '/viejo', 'to' => '/nuevo', 'status' => 'active']];

            expect(fn () => RedirectRules::guardConflicts('/viejo', '/otro', $existing))
                ->toThrow(ConnectorException::class, 'id 3');
        });

        it('ignores an inactive rule when checking that origin', function () {
            $existing = [['id' => 3, 'from' => '/viejo', 'to' => '/nuevo', 'status' => 'inactive']];

            RedirectRules::guardConflicts('/viejo', '/otro', $existing);

            expect(true)->toBeTrue();
        });

        it('refuses a chain and names the destination to use instead', function () {
            // Pointing at a URL that itself redirects costs a hop to every
            // visitor and every crawler, and happens by accident because
            // whoever adds the second one is not looking at the first.
            $existing = [['id' => 5, 'from' => '/intermedia', 'to' => '/final', 'status' => 'active']];

            expect(fn () => RedirectRules::guardConflicts('/vieja', '/intermedia', $existing))
                ->toThrow(ConnectorException::class, '/final');
        });

        it('does not count the rule being edited as a conflict with itself', function () {
            $existing = [['id' => 9, 'from' => '/viejo', 'to' => '/nuevo', 'status' => 'active']];

            RedirectRules::guardConflicts('/viejo', '/otro', $existing, 9);

            expect(true)->toBeTrue();
        });

        it('lets a 410 through without a destination to compare', function () {
            $existing = [['id' => 5, 'from' => '/otra', 'to' => '/final', 'status' => 'active']];

            RedirectRules::guardConflicts('/borrada', '', $existing);

            expect(true)->toBeTrue();
        });
    });

    describe('status', function () {
        it('takes the two states a redirect has', function () {
            expect(RedirectRules::status('ACTIVE'))->toBe('active');
            expect(RedirectRules::status(' inactive '))->toBe('inactive');
        });

        it('refuses anything else and says why deleting is not on the list', function () {
            expect(fn () => RedirectRules::status('deleted'))
                ->toThrow(ConnectorException::class, 'no borra');
        });
    });
});
