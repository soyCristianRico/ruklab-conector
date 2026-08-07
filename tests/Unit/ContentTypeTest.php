<?php

declare(strict_types=1);

use Ruklab\Connector\Content\ContentType;

function tipoArticulo(array $overrides = []): ContentType
{
    return ContentType::make(
        model: $overrides['model'] ?? 'App\Models\BlogPost',
        label: 'Artículos',
        fields: $overrides['fields'] ?? [
            'title' => 'title',
            'content' => 'body',
            'excerpt' => 'excerpt',
            'slug' => 'slug',
        ],
        status: $overrides['status'] ?? 'is_active',
        readonly: $overrides['readonly'] ?? [],
    );
}

describe('ContentType', function () {
    describe('column', function () {
        it('translates Ruk Lab vocabulary into this site column names', function () {
            expect(tipoArticulo()->column('content'))->toBe('body');
            expect(tipoArticulo()->column('title'))->toBe('title');
        });

        it('returns nothing for a field this type does not have', function () {
            expect(tipoArticulo()->column('author'))->toBeNull();
        });
    });

    describe('writable', function () {
        it('lists only mapped fields', function () {
            expect(tipoArticulo()->writable())->toBe(['title', 'content', 'excerpt', 'slug']);
        });

        it('leaves out what the site marked read-only', function () {
            $tipo = tipoArticulo(['readonly' => ['slug']]);

            expect($tipo->writable())->not->toContain('slug');
            expect($tipo->readable())->toContain('slug');
        });

        it('ignores a mapped field Ruk Lab does not know about', function () {
            $tipo = tipoArticulo(['fields' => ['title' => 'title', 'inventado' => 'columna_rara']]);

            expect($tipo->writable())->toBe(['title']);
        });
    });

    describe('toColumns', function () {
        it('turns Ruk Lab fields back into columns', function () {
            expect(tipoArticulo()->toColumns(['title' => 'Hola', 'content' => '<p>Texto</p>']))
                ->toBe(['title' => 'Hola', 'body' => '<p>Texto</p>']);
        });

        it('maps the status onto whatever column says it is live', function () {
            expect(tipoArticulo()->toColumns(['status' => 'published']))->toBe(['is_active' => true]);
            expect(tipoArticulo()->toColumns(['status' => 'draft']))->toBe(['is_active' => false]);
        });

        it('drops a field that is not writable instead of passing it through', function () {
            $tipo = tipoArticulo(['readonly' => ['slug']]);

            expect($tipo->toColumns(['title' => 'Hola', 'slug' => 'colado']))->toBe(['title' => 'Hola']);
        });

        it('drops a column name sent directly, so nobody writes past the map', function () {
            // Sending the site's own column name rather than Ruk Lab's field
            // must not work: the map is the surface, and bypassing it would
            // let a caller reach a column nobody declared.
            expect(tipoArticulo()->toColumns(['body' => 'directo', 'is_active' => true]))->toBe([]);
        });
    });

    describe('exists', function () {
        it('reports a model this site does not have', function () {
            expect(tipoArticulo(['model' => 'App\Models\NoExiste'])->exists())->toBeFalse();
        });
    });
});

describe('ContentType urls', function () {
    it('builds the public link from the pattern the site declared', function () {
        $tipo = ContentType::make(
            model: 'App\Models\BlogPost',
            label: 'Artículos',
            fields: ['title' => 'title', 'slug' => 'slug'],
            url: '/blog/{slug}',
        );

        $registro = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $attributes = ['slug' => 'un-articulo'];
        };

        expect($tipo->urlFor($registro))->toBe('https://cierzo.test/blog/un-articulo');
    });

    it('returns nothing when the site did not say how its urls look', function () {
        $tipo = ContentType::make(
            model: 'App\Models\BlogPost',
            label: 'Artículos',
            fields: ['title' => 'title'],
        );

        expect($tipo->urlFor(new class extends \Illuminate\Database\Eloquent\Model {}))->toBeNull();
    });
});

describe('ContentType caching', function () {
    it('survives being written out as PHP and read back', function () {
        // `config:cache` runs on every deployment and stores the config with
        // var_export. Without __set_state that command fails outright, which
        // means the site cannot be deployed at all.
        $tipo = ContentType::make(
            model: 'App\Models\Course',
            label: 'Cursos',
            fields: ['title' => 'name', 'content' => 'description'],
            status: 'is_active',
            readonly: ['slug'],
            url: '/cursos/{slug}',
        );

        $devuelto = eval('return '.var_export($tipo, true).';');

        expect($devuelto)->toBeInstanceOf(ContentType::class);
        expect($devuelto->model)->toBe('App\Models\Course');
        expect($devuelto->fields)->toBe(['title' => 'name', 'content' => 'description']);
        expect($devuelto->status)->toBe('is_active');
        expect($devuelto->readonly)->toBe(['slug']);
        expect($devuelto->url)->toBe('/cursos/{slug}');
    });

    it('survives it with only the fields that are required', function () {
        $tipo = ContentType::make(
            model: 'App\Models\Page',
            label: 'Páginas',
            fields: ['title' => 'title'],
        );

        $devuelto = eval('return '.var_export($tipo, true).';');

        expect($devuelto->status)->toBeNull();
        expect($devuelto->readonly)->toBe([]);
    });
});
