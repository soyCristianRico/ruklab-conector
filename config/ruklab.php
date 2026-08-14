<?php

declare(strict_types=1);

use Ruklab\Connector\Content\ContentType;

return [

    /*
    |--------------------------------------------------------------------------
    | Credencial
    |--------------------------------------------------------------------------
    |
    | El token con el que ruklab.app se identifica ante esta web. Se guarda
    | cifrado en el proyecto correspondiente de ruklab.app y viaja en la
    | cabecera Authorization.
    |
    | Sin token, el conector no expone ninguna ruta: una web sin credencial
    | configurada es una web que nadie ha conectado a propósito, y abrirla
    | sería peor que no tener conector.
    |
    */

    'token' => env('RUKLAB_CONNECTOR_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Escritura
    |--------------------------------------------------------------------------
    |
    | Igual que en el conector de WordPress: una web conectada empieza siendo
    | de solo lectura y alguien tiene que decir que sí.
    |
    */

    'writes_enabled' => env('RUKLAB_CONNECTOR_WRITES', false),

    /*
    |--------------------------------------------------------------------------
    | Dominio público
    |--------------------------------------------------------------------------
    |
    | Con qué dominio se construyen los enlaces que devuelve el conector.
    |
    | Casi siempre es el de la propia aplicación, y entonces esto se deja
    | vacío. Se rellena cuando las páginas públicas viven en otro sitio —el
    | blog de Ruk Lab, sin ir más lejos, se sirve desde ruklab.com mientras
    | que la aplicación está en ruklab.app— porque un enlace construido con
    | el dominio equivocado es peor que no devolver ninguno.
    |
    */

    'base_url' => env('RUKLAB_CONNECTOR_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Tipos de contenido
    |--------------------------------------------------------------------------
    |
    | Qué modelos de esta web son «contenido» y cómo se llaman sus campos.
    |
    | Los tres de abajo son los que traen todas las plantillas, así que una web
    | estándar no necesita tocar nada. Este es también el sitio donde una web a
    | medida añade lo suyo: registrar aquí un modelo propio —un curso, una
    | oposición, una ficha— basta para que las herramientas de Ruk Lab lleguen
    | a él, sin escribir código nuevo ni en la web ni en la plataforma.
    |
    */

    'types' => [

        'post' => ContentType::make(
            model: \App\Models\BlogPost::class,
            label: 'Artículos del blog',
            fields: [
                'title' => 'title',
                'content' => 'body',
                'excerpt' => 'excerpt',
                'slug' => 'slug',
                'meta_title' => 'meta_title',
                'meta_description' => 'meta_description',
                'author' => 'author_name',
                'published_at' => 'published_at',
            ],
            status: 'is_active',
        ),

        'page' => ContentType::make(
            model: \App\Models\Page::class,
            label: 'Páginas',
            fields: [
                'title' => 'title',
                'content' => 'body',
                'slug' => 'slug',
                'meta_title' => 'meta_title',
                'meta_description' => 'meta_description',
            ],
            status: 'is_active',
        ),

        'landing' => ContentType::make(
            model: \App\Models\Landing::class,
            label: 'Landings',
            fields: [
                'title' => 'title',
                'content' => 'body',
                'slug' => 'slug',
                'meta_title' => 'meta_title',
                'meta_description' => 'meta_description',
            ],
            status: 'is_active',
        ),

    ],

    /*
    |--------------------------------------------------------------------------
    | Menús
    |--------------------------------------------------------------------------
    |
    | Solo algunas plantillas tienen menús en base de datos; el resto los lleva
    | en el propio tema. Dejarlo a null aquí es lo correcto en esas: el
    | conector informa de que esta web no sirve menús y las demás herramientas
    | siguen funcionando, en vez de fallar al intentarlo.
    |
    */

    'menus' => [
        'model' => class_exists(\App\Models\MenuItem::class) ? \App\Models\MenuItem::class : null,
        'fields' => [
            'label' => 'label',
            'url' => 'url',
            'parent' => 'parent_id',
            'position' => 'position',
            'location' => 'location',
        ],
        'status' => 'is_active',
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirecciones
    |--------------------------------------------------------------------------
    |
    | En WordPress las redirecciones son siempre de algún plugin y el conector
    | se limita a manejar el que ya haya. Aquí no hay ninguno que manejar, así
    | que el conector trae la tabla y el middleware que la sirve.
    |
    | El middleware solo actúa sobre un 404, nunca antes: una regla guardada no
    | puede tapar una página que esta web sí sirve. Se desactiva en una web que
    | prefiera gestionarlas por su cuenta —en el servidor, o con sus propias
    | rutas— y entonces el conector responde que no las lleva, en vez de
    | competir con lo que ya hay.
    |
    */

    'redirects' => [
        'enabled' => env('RUKLAB_CONNECTOR_REDIRECTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Copias de seguridad
    |--------------------------------------------------------------------------
    |
    | Cuántos días se guarda el estado anterior de cada registro modificado, y
    | cuántas copias como mucho por registro. Lo que hace que un cambio hecho
    | desde fuera se pueda deshacer.
    |
    */

    'snapshots' => [
        'days' => 30,
        'per_record' => 10,
    ],

];
