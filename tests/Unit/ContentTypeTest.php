<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Ruklab\Connector\Content\ContentType;
use Ruklab\Connector\Support\ConnectorException;

/**
 * A model that lists what it will accept, the way every site's models do.
 */
class ArticuloConFillable extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['title', 'body', 'is_active'];
}

/**
 * A landing whose body is a page builder tree, not text.
 */
class LandingConBloques extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['title', 'content'];

    /** @var array<string, string> */
    protected $casts = ['content' => 'array'];
}

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

        it('publishes when asked to publish, whichever of the two words is used', function () {
            // `publish` is the word Ruk Lab's own tool documents, and it used
            // to fall through the === 'published' comparison and unpublish the
            // record. Asking to publish took the page down.
            expect(tipoArticulo()->toColumns(['status' => 'publish']))->toBe(['is_active' => true]);
            expect(tipoArticulo()->toColumns(['status' => 'PUBLISH']))->toBe(['is_active' => true]);
            expect(tipoArticulo()->toColumns(['status' => true]))->toBe(['is_active' => true]);
        });

        it('refuses a status this site has nowhere to put', function () {
            // A WordPress has five. Reading `pending` as "not published" would
            // take a live page down on the way to doing something else.
            expect(fn () => tipoArticulo()->toColumns(['status' => 'pending']))
                ->toThrow(ConnectorException::class, 'pending');

            expect(fn () => tipoArticulo()->toColumns(['status' => 'future']))
                ->toThrow(ConnectorException::class);
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

describe('ContentType::unfillableColumns', function () {
    it('says nothing when the model accepts every mapped column', function () {
        $tipo = tipoArticulo([
            'model' => ArticuloConFillable::class,
            'fields' => ['title' => 'title', 'content' => 'body'],
        ]);

        expect($tipo->unfillableColumns($tipo->toColumns([
            'title' => 'Hola',
            'content' => 'Texto',
            'status' => 'publish',
        ])))->toBe([]);
    });

    it('names a mapped column the model will not accept', function () {
        // Declaring a field in config/ruklab.php and forgetting it in the
        // model's $fillable answers 200, reports the field as changed, and
        // changes nothing. It has to be loud.
        $tipo = tipoArticulo([
            'model' => ArticuloConFillable::class,
            'fields' => ['title' => 'title', 'meta_title' => 'meta_title'],
        ]);

        expect($tipo->unfillableColumns($tipo->toColumns([
            'title' => 'Hola',
            'meta_title' => 'SEO',
        ])))->toBe(['meta_title']);
    });
});

describe('ContentType::structuredFields', function () {
    it('refuses to write a column that holds a structure', function () {
        // Ruk Lab sends fields as strings. A landing whose body is an array of
        // blocks would have the string itself encoded into it, and the page
        // would stop rendering. Reading it is fine; writing it is not.
        $tipo = tipoArticulo([
            'model' => LandingConBloques::class,
            'fields' => ['title' => 'title', 'content' => 'content'],
            'status' => null,
        ]);

        expect($tipo->structuredFields())->toBe(['content']);
        expect($tipo->writable())->toBe(['title']);
        expect($tipo->readable())->toContain('content');
    });

    it('leaves plain columns alone', function () {
        $tipo = tipoArticulo([
            'model' => ArticuloConFillable::class,
            'fields' => ['title' => 'title', 'content' => 'body'],
        ]);

        expect($tipo->structuredFields())->toBe([]);
        expect($tipo->writable())->toBe(['title', 'content']);
    });
});
