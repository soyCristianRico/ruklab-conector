<?php

declare(strict_types=1);

namespace Ruklab\Connector\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Ruklab\Connector\Support\ConnectorException;

/**
 * Images that belong to a record, rather than fields on it.
 *
 * The rest of the connector maps one of Ruk Lab's names onto one of this
 * site's columns. An image does not fit that: our sites keep theirs in a media
 * library, attached to the record but stored apart, resized on the way in.
 * There is no column to write a filename into.
 *
 * So a type declares which of its collections Ruk Lab may reach, by name, and
 * this puts the file there. Anything not declared is unreachable, the same rule
 * the fields follow.
 */
final readonly class MediaService
{
    /**
     * Formats a browser will actually render. A PDF or an SVG in the hero slot
     * of an article is either a mistake or somebody probing.
     */
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private ContentRegistry $registry = new ContentRegistry,
    ) {}

    /**
     * Attach an image to a record, replacing whatever was in that slot.
     *
     * Replacing rather than adding is deliberate: a featured image is one
     * image, and publishing the same article twice should not leave the site
     * with two heroes and no way to say which one wins.
     *
     * @return array<string, mixed>
     */
    public function attach(string $typeName, int|string $id, string $name, UploadedFile $file): array
    {
        $this->requireWrites();

        $type = $this->registry->get($typeName);
        $target = $this->targetFor($type, $name);
        $record = $this->find($type, $typeName, $id);

        $this->guardFile($file);

        $url = is_array($target)
            ? $this->storeInColumn($record, $target, $file)
            : $this->storeInCollection($record, $typeName, $name, $target, $file);

        return [
            'type' => $typeName,
            'id' => $record->getKey(),
            'name' => $name,
            'collection' => is_array($target) ? $target['column'] : $target,
            'url' => $url,
        ];
    }

    /**
     * The usual case: a media library collection on the record.
     */
    private function storeInCollection(
        Model $record,
        string $typeName,
        string $name,
        string $collection,
        UploadedFile $file,
    ): string {
        if (! $record instanceof \Spatie\MediaLibrary\HasMedia) {
            throw new ConnectorException(
                sprintf(
                    'El tipo «%s» declara la imagen «%s» en una colección, pero su modelo no usa medialibrary. '
                    .'Si esta web guarda la ruta en una columna, decláralo con ["column" => "…"].',
                    $typeName,
                    $name,
                ),
                500,
            );
        }

        $record->clearMediaCollection($collection);

        return $record
            ->addMedia($file->getRealPath())
            ->usingFileName($this->safeFileName($file))
            ->toMediaCollection($collection)
            ->getUrl();
    }

    /**
     * The other case: the site keeps a path in a column of the record.
     *
     * Ruk Lab's own blog does this, and it is common enough in a site that
     * never needed conversions. The file goes on a disk and the column gets the
     * public path, which is exactly what that site's own admin writes there.
     *
     * @param  array{column: string, disk?: string, folder?: string, prefix?: string}  $target
     */
    private function storeInColumn(Model $record, array $target, UploadedFile $file): string
    {
        $disk = $target['disk'] ?? 'public';
        $folder = $target['folder'] ?? 'imagenes';

        // The record key goes in the name so two articles whose photos are
        // both called «portada.jpg» do not overwrite each other, and so
        // republishing the same one replaces its own image rather than
        // leaving orphans behind. Medialibrary gets this for free by giving
        // every file its own folder; here it has to be said.
        $filename = $record->getKey().'-'.$this->safeFileName($file);

        $path = $file->storePubliclyAs($folder, $filename, $disk);

        if ($path === false) {
            throw new ConnectorException('No se ha podido guardar la imagen en el disco de esta web.', 500);
        }

        $url = ($target['prefix'] ?? '/storage/').ltrim((string) $path, '/');

        $record->forceFill([$target['column'] => $url])->save();

        return $url;
    }

    /**
     * The images a type exposes, as Ruk Lab's name => current URL.
     *
     * @return array<string, string|null>
     */
    public function present(ContentType $type, Model $record): array
    {
        if ($type->media === []) {
            return [];
        }

        $images = [];

        foreach ($type->media as $name => $target) {
            $images[$name] = is_array($target)
                ? (($value = $record->{$target['column']}) !== null ? (string) $value : null)
                : $record->getFirstMedia($target)?->getUrl();
        }

        return $images;
    }

    /**
     * Where an image of this name goes: a media library collection (a string)
     * or a column of the record (an array).
     *
     * @return string|array{column: string, disk?: string, folder?: string, prefix?: string}
     */
    private function targetFor(ContentType $type, string $name): string|array
    {
        $target = $type->media[$name] ?? null;

        if (is_array($target) && ($target['column'] ?? '') !== '') {
            return $target;
        }

        if (! is_string($target) || $target === '') {
            throw new ConnectorException(
                sprintf(
                    'Este tipo de contenido no tiene ninguna imagen llamada «%s». Las que sí: %s.',
                    $name,
                    $type->media === [] ? 'ninguna' : implode(', ', array_keys($type->media)),
                ),
                403,
            );
        }

        return $target;
    }

    private function guardFile(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new ConnectorException('El archivo no ha llegado entero.', 422);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new ConnectorException(
                sprintf('La imagen pesa más de %d MB, que es el máximo.', (int) (self::MAX_BYTES / 1024 / 1024)),
                422,
            );
        }

        if (! in_array((string) $file->getMimeType(), self::ALLOWED_MIMES, true)) {
            throw new ConnectorException(
                sprintf(
                    'El formato «%s» no vale para una imagen de una web. Acepta: %s.',
                    (string) $file->getMimeType(),
                    implode(', ', self::ALLOWED_MIMES),
                ),
                422,
            );
        }
    }

    /**
     * The name reaches the public URL, so it cannot carry a path or the
     * caller's idea of where the file should live.
     */
    private function safeFileName(UploadedFile $file): string
    {
        $name = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);
        $slug = trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-');

        return ($slug !== '' ? mb_strtolower($slug) : 'imagen').'.'.$file->guessExtension();
    }

    private function find(ContentType $type, string $typeName, int|string $id): Model
    {
        $record = $type->newModel()->newQuery()->find($id);

        if (! $record instanceof Model) {
            throw ConnectorException::notFound($typeName, $id);
        }

        return $record;
    }

    private function requireWrites(): void
    {
        if (! config('ruklab.writes_enabled', false)) {
            throw ConnectorException::readOnly();
        }
    }
}
