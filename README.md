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
| `GET /ruklab/v1/redirects` | Las redirecciones de esta web |
| `POST /ruklab/v1/redirects` | Crear una |
| `POST /ruklab/v1/redirects/{id}` | Cambiar destino, código o estado |
| `GET /ruklab/v1/snapshots/{tipo}/{id}` | Copias guardadas |
| `POST /ruklab/v1/rollback` | Restaurar una copia |

No hay ninguna ruta que borre, y no la habrá.

## Redirecciones

En WordPress son siempre de algún plugin —Rank Math, Redirection— y allí el
conector se limita a manejar el que ya haya. Aquí no hay ninguno que manejar,
así que este paquete trae la tabla y el middleware que la sirve.

El middleware actúa **solo sobre un 404**, nunca antes. Una regla guardada no
puede tapar una página que esta web sí sirve: si alguien redirige una URL que
todavía funciona, la regla se queda ahí sin hacer nada hasta que la página
desaparece, que es el orden inofensivo.

Se refusan, con el motivo escrito, tres cosas que son siempre un error: una
redirección a sí misma, un segundo origen igual a uno que ya existe, y una
cadena —apuntar a una URL que a su vez redirige—. La última es la que más pasa,
porque quien añade la segunda no está mirando la primera.

Una redirección no se borra, se pone en `inactive`. Un 301 lo cachean el
navegador y Google, y quitar la fila no descachea nada; dejarla es lo que
permite ver después por qué una URL dejó de redirigir.

Para una web que las gestione por su cuenta —en el servidor, o con sus propias
rutas—:

```
RUKLAB_CONNECTOR_REDIRECTS=false
```

y el conector responde que no las lleva en vez de competir con lo que ya hay.

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

## Campos propios de un tipo

`fields` es el vocabulario fijo de Ruk Lab —`title`, `content`, `slug`...—, y
un tipo puede tener más cosas que eso: una noticia tiene fuente, un curso
tiene precio. Esos se declaran en `extra`, y viajan bajo `meta` en vez de en el
nivel de arriba:

​```php
'noticias' => ContentType::make(
    model: \App\Models\News::class,
    label: 'Noticias',
    fields: [
        'title' => 'title',
        'content' => 'body',
        'slug' => 'slug',
    ],
    readonly: ['slug'],
    extra: [
        'category' => ExtraField::relation(
            column: 'category_id',
            label: 'Área',
            relatedModel: \App\Models\Category::class,
            matchColumn: 'name',
            required: true,
        ),
        'source_name' => ExtraField::text(column: 'source_name', label: 'Fuente', required: true),
        'source_url' => ExtraField::url(column: 'source_url', label: 'URL de la fuente', required: true),
    ],
),
​```

Un `relation` nunca viaja como el id interno de esta web —ese número no
significa nada fuera de ella—: se manda y se devuelve por el valor de
`matchColumn` (el nombre de la categoría, no su id), resuelto al escribir y
buscado al leer. Un valor que no coincide con nada se rechaza por su nombre,
igual que un estado que esta web no reconoce.

Un campo `required` solo se exige al crear. Una actualización parcial puede
dejarlo como está.

`GET /ruklab/v1/info` describe estos campos por tipo —nombre, etiqueta, tipo,
si es obligatorio y, para un `select` o un `relation`, las opciones
disponibles— para que quien llama sepa qué puede mandar antes de intentarlo.

## Tests

```bash
composer install
vendor/bin/pest
```

Cubren el mapeo entre el vocabulario de Ruk Lab y las columnas de cada web, que
es donde está la lógica. Lo que toca el framework se prueba dentro de una web
real, que es el único sitio donde significa algo.
