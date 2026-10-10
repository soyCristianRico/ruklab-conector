<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Ruklab\Connector\Redirects\RedirectMap;
use Ruklab\Connector\Redirects\RedirectRules;
use Ruklab\Connector\Support\ConnectorException;

/**
 * A row of the site's own table, with the column names its panel uses.
 */
class RedirectDeLaWeb extends Model
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }
}

enum CodigoDeLaWeb: int
{
    case Permanente = 301;
}

function mapaDeLaWeb(array $overrides = []): RedirectMap
{
    return new RedirectMap(
        model: RedirectDeLaWeb::class,
        fields: [
            'source' => 'source',
            'target' => 'destination',
            'code' => 'status_code',
            'active' => 'is_active',
            'hits' => 'hits',
            'match' => 'match_type',
        ],
        codes: $overrides['codes'] ?? [301, 302, 307, 308, 410],
        defaults: ['match_type' => 'exact', 'preserve_query' => true],
    );
}

describe('RedirectMap', function () {
    describe('present', function () {
        it('answers in the shape every other site uses, whatever the columns are called', function () {
            $fila = new RedirectDeLaWeb([
                'id' => 7,
                'source' => '/viejo',
                'destination' => '/nuevo',
                'status_code' => CodigoDeLaWeb::Permanente,
                'is_active' => true,
                'hits' => '12',
                'match_type' => 'exact',
            ]);

            expect(mapaDeLaWeb()->present($fila))->toBe([
                'id' => 7,
                'from' => '/viejo',
                'to' => '/nuevo',
                'code' => 301,
                'status' => 'active',
                'hits' => 12,
                'manager' => 'site',
                'match' => 'exact',
            ]);
        });

        it('says a 410 goes nowhere instead of failing on its null destination', function () {
            $fila = new RedirectDeLaWeb(['id' => 1, 'source' => '/fuera', 'destination' => null, 'status_code' => 410, 'is_active' => false]);

            $presentada = mapaDeLaWeb()->present($fila);

            expect($presentada['to'])->toBe('');
            expect($presentada['status'])->toBe('inactive');
        });

        it('names this package as the manager when the table is its own', function () {
            $fila = new RedirectDeLaWeb(['id' => 1, 'source' => '/a', 'target' => '/b', 'code' => 301, 'is_active' => true]);

            expect(RedirectMap::fromConfig()->isSiteTable())->toBeFalse();
            expect(RedirectMap::fromConfig()->present($fila)['manager'])->toBe('ruklab');
        });
    });

    describe('source', function () {
        it('stores a source the way the site compares it: lower case and without a query', function () {
            expect(mapaDeLaWeb()->source('/Servicios/SEO?utm_source=x'))->toBe('/servicios/seo');
        });

        it('leaves a source alone in this package own table', function () {
            expect(RedirectMap::fromConfig()->source('/Servicios/SEO?x=1'))->toBe('/Servicios/SEO?x=1');
        });
    });

    describe('newRow', function () {
        it('builds the columns of the site, with the ones Ruk Lab has no word for', function () {
            expect(mapaDeLaWeb()->newRow('/Viejo', '/nuevo', 301))->toBe([
                'match_type' => 'exact',
                'preserve_query' => true,
                'source' => '/viejo',
                'destination' => '/nuevo',
                'status_code' => 301,
                'is_active' => true,
            ]);
        });

        it('leaves the destination null for a 410, which is how the site stores it', function () {
            expect(mapaDeLaWeb()->newRow('/fuera', '', 410)['destination'])->toBeNull();
        });

        it('keeps an empty string for a 410 in this package own table', function () {
            expect(RedirectMap::fromConfig()->newRow('/fuera', '', 410)['target'])->toBe('');
        });
    });

    describe('exactOnly', function () {
        it('leaves out prefixes and patterns, which answer for more than their own path', function () {
            $filas = [
                ['id' => 1, 'from' => '/a', 'match' => 'exact'],
                ['id' => 2, 'from' => '/blog', 'match' => 'prefix'],
                ['id' => 3, 'from' => '/(.*)', 'match' => 'regex'],
                ['id' => 4, 'from' => '/b'],
            ];

            expect(array_column(mapaDeLaWeb()->exactOnly($filas), 'id'))->toBe([1, 4]);
        });
    });

    describe('codes', function () {
        it('accepts the codes this table declares, including one the base list lacks', function () {
            expect(RedirectRules::code(308, mapaDeLaWeb()->codes))->toBe(308);
        });

        it('refuses a code the table cannot hold and lists the ones it can', function () {
            RedirectRules::code(451, mapaDeLaWeb()->codes);
        })->throws(ConnectorException::class, '301, 302, 307, 308, 410');

        it('lets a 308 carry a destination and a 410 refuse one', function () {
            expect(RedirectRules::target('/nuevo', 308))->toBe('/nuevo');
            expect(RedirectRules::target('/nuevo', 410))->toBe('');
        });
    });
});
