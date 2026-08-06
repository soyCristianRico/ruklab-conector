# Conector Ruk Lab para webs a medida

Expone `/ruklab/v1` en una web Laravel para que la plataforma de Ruk Lab pueda
leer y editar sus contenidos, con las mismas herramientas que ya usa sobre las
webs en WordPress.

## Instalación

```bash
composer require ruklab/connector
php artisan vendor:publish --tag=ruklab-config
php artisan migrate
```

En el `.env`:

```
RUKLAB_CONNECTOR_TOKEN=   # el mismo que se guarda en la ficha del proyecto
RUKLAB_CONNECTOR_WRITES=false
```

Una web conectada empieza en solo lectura. La escritura se activa a propósito,
no por defecto.

## Qué expone

| Ruta | Qué hace |
|------|----------|
| `GET /ruklab/v1/info` | Qué es esta web y qué tipos ofrece |
| `GET /ruklab/v1/content/{tipo}` | Listado, con búsqueda y estado |
| `POST /ruklab/v1/content/{tipo}` | Crear — es lo que usa la publicación de artículos |
| `GET /ruklab/v1/content/{tipo}/{id}` | Un registro |
| `POST /ruklab/v1/content/{tipo}/{id}` | Cambiar campos |
| `GET /ruklab/v1/snapshots/{tipo}/{id}` | Copias guardadas |
| `POST /ruklab/v1/rollback` | Restaurar una copia |

No hay ninguna ruta que borre, y no la habrá.

## Añadir un tipo propio

`config/ruklab.php` es la única puerta: un modelo que no esté ahí no se puede
leer ni escribir, aunque alguien lo nombre en una petición.

Eso lo hace también el sitio donde una web a medida añade lo suyo. Registrar un
modelo con la misma forma basta para que todas las herramientas que ya existen
lleguen a él, sin tocar ni esta web ni la plataforma:

```php
'curso' => ContentType::make(
    model: \App\Models\Curso::class,
    label: 'Cursos',
    fields: [
        'title' => 'nombre',
        'content' => 'descripcion',
        'slug' => 'slug',
    ],
    status: 'publicado',
    readonly: ['slug'],   // editable no, visible sí
),
```

`readonly` sirve para lo que Ruk Lab debe poder leer pero no cambiar: un slug
del que cuelgan enlaces, un precio que decide otro sistema.

## Tests

```bash
composer install
vendor/bin/pest
```

Cubren el mapeo entre el vocabulario de Ruk Lab y las columnas de cada web, que
es donde está la lógica. Lo que toca el framework se prueba dentro de una web
real, que es el único sitio donde significa algo.
