<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Ruklab\Connector\Content\ContentType;
use Ruklab\Connector\Content\ExtraField;
use Ruklab\Connector\Content\ExtraFieldType;
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

describe('ContentType base url', function () {
    afterEach(fn () => $GLOBALS['ruklab_base_url'] = null);

    it('builds links with the public domain when it differs from the app', function () {
        // Ruk Lab's own blog is served from ruklab.com while the application
        // lives on ruklab.app. A link built from the app URL would 404.
        $GLOBALS['ruklab_base_url'] = 'https://ruklab.com';

        $tipo = ContentType::make(
            model: 'App\Models\BlogPost',
            label: 'Artículos',
            fields: ['slug' => 'slug'],
            url: '/blog/{slug}',
        );

        $registro = new class extends Model
        {
            protected $attributes = ['slug' => 'un-articulo'];
        };

        expect($tipo->urlFor($registro))->toBe('https://ruklab.com/blog/un-articulo');
    });
});

describe('ContentType media', function () {
    it('carries the map from Ruk Lab names to this site collections', function () {
        $tipo = tipoArticulo(['model' => ArticuloConFillable::class]);

        expect($tipo->media)->toBe([]);

        $conImagen = ContentType::make(
            model: ArticuloConFillable::class,
            label: 'Artículos',
            fields: ['title' => 'title'],
            media: ['featured' => 'hero'],
        );

        expect($conImagen->media)->toBe(['featured' => 'hero']);
    });

    it('survives config:cache with its images', function () {
        // Every deployment writes the config out as PHP. An image map that did
        // not come back would make the site look like it has none.
        $tipo = ContentType::make(
            model: 'App\Models\BlogPost',
            label: 'Artículos',
            fields: ['title' => 'title'],
            media: ['featured' => 'hero'],
        );

        $devuelto = eval('return '.var_export($tipo, true).';');

        expect($devuelto->media)->toBe(['featured' => 'hero']);
    });
});

/**
 * A news item, with fields no other type has: an area, and a source. Neither
 * fits Ruk Lab's fixed vocabulary, which is exactly why `extra` exists.
 *
 * Relation resolution needs a real Eloquent model with a database behind it,
 * which this suite deliberately does not have — the stub `Model` here only
 * reads attributes, it has no `query()`. That half is exercised where it
 * means something: inside a real site. What is tested here is everything
 * `ContentType` does on its own: which extra fields are known, which are
 * required, and how a non-relation value is coerced.
 */
function tipoNoticia(array $overrides = []): ContentType
{
    return ContentType::make(
        model: $overrides['model'] ?? 'App\Models\News',
        label: 'Noticias',
        fields: ['title' => 'title', 'content' => 'body', 'slug' => 'slug'],
        readonly: ['slug'],
        extra: $overrides['extra'] ?? [
            'source_name' => ExtraField::text(column: 'source_name', label: 'Fuente', required: true),
            'source_url' => ExtraField::url(column: 'source_url', label: 'URL de la fuente', required: true),
            'featured' => ExtraField::boolean(column: 'is_featured', label: 'Destacada'),
            'priority' => ExtraField::number(column: 'priority', label: 'Prioridad'),
        ],
    );
}

describe('ContentType extra fields', function () {
    describe('readableExtra', function () {
        it('lists the names of the extra fields, not their columns', function () {
            expect(tipoNoticia()->readableExtra())
                ->toBe(['source_name', 'source_url', 'featured', 'priority']);
        });

        it('is empty for a type with none', function () {
            expect(tipoArticulo()->readableExtra())->toBe([]);
        });
    });

    describe('requiredExtraFields / missingRequiredExtra', function () {
        it('lists only the extra fields marked required', function () {
            expect(tipoNoticia()->requiredExtraFields())->toBe(['source_name', 'source_url']);
        });

        it('names what is missing from what was given', function () {
            expect(tipoNoticia()->missingRequiredExtra(['source_name']))->toBe(['source_url']);
            expect(tipoNoticia()->missingRequiredExtra(['source_name', 'source_url']))->toBe([]);
        });

        it('has nothing missing when the type has no required extra fields', function () {
            expect(tipoArticulo()->missingRequiredExtra([]))->toBe([]);
        });
    });

    describe('extraColumns', function () {
        it('maps a field name given under meta to its column', function () {
            expect(tipoNoticia()->extraColumns(['source_name' => 'El Periódico']))
                ->toBe(['source_name' => 'El Periódico']);
        });

        it('coerces a boolean field regardless of how it arrives', function () {
            expect(tipoNoticia()->extraColumns(['featured' => '1']))->toBe(['is_featured' => true]);
            expect(tipoNoticia()->extraColumns(['featured' => 0]))->toBe(['is_featured' => false]);
        });

        it('coerces a number field to an actual number', function () {
            expect(tipoNoticia()->extraColumns(['priority' => '3']))->toBe(['priority' => 3]);
        });

        it('refuses a name this type does not have under extra', function () {
            expect(fn () => tipoNoticia()->extraColumns(['inventado' => 'x']))
                ->toThrow(ConnectorException::class, 'inventado');
        });
    });

    describe('present with extra fields', function () {
        it('nests them under meta, by their Ruk Lab name', function () {
            $tipo = tipoNoticia(['extra' => [
                'source_name' => ExtraField::text(column: 'source_name', label: 'Fuente', required: true),
            ]]);

            $registro = new class extends Model
            {
                protected $attributes = ['title' => 'Ha pasado algo', 'source_name' => 'El Periódico'];
            };

            expect($tipo->present($registro))->toHaveKey('meta', ['source_name' => 'El Periódico']);
        });

        it('does not add a meta key for a type with no extra fields', function () {
            expect(tipoArticulo()->present(new class extends Model
            {
                protected $attributes = ['title' => 'Hola'];
            }))->not->toHaveKey('meta');
        });
    });

    describe('caching', function () {
        it('survives config:cache with its extra fields', function () {
            $tipo = ContentType::make(
                model: 'App\Models\News',
                label: 'Noticias',
                fields: ['title' => 'title'],
                extra: [
                    'source_name' => ExtraField::text(column: 'source_name', label: 'Fuente', required: true),
                    'category' => ExtraField::relation(
                        column: 'category_id',
                        label: 'Área',
                        relatedModel: 'App\Models\Category',
                        matchColumn: 'name',
                    ),
                ],
            );

            $devuelto = eval('return '.var_export($tipo, true).';');

            expect($devuelto->extra['source_name'])->toBeInstanceOf(ExtraField::class);
            expect($devuelto->extra['source_name']->required)->toBeTrue();
            expect($devuelto->extra['category']->type)->toBe(ExtraFieldType::Relation);
            expect($devuelto->extra['category']->relatedModel)->toBe('App\Models\Category');
            expect($devuelto->extra['category']->matchColumn)->toBe('name');
        });
    });
});
