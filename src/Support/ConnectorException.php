<?php

declare(strict_types=1);

namespace Ruklab\Connector\Support;

use RuntimeException;

/**
 * Something the caller asked for that this site will not do.
 *
 * The messages are written to be read by whoever asked for the change through
 * the assistant, not by whoever wrote this. A refusal that only says "error"
 * teaches people to retry blindly.
 */
final class ConnectorException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<int, string>  $available
     */
    public static function unknownType(string $name, array $available): self
    {
        return new self(
            sprintf(
                'Esta web no tiene ningún tipo de contenido llamado «%s». Los que tiene son: %s.',
                $name,
                $available === [] ? 'ninguno' : implode(', ', $available),
            ),
            404,
        );
    }

    public static function notFound(string $type, int|string $id): self
    {
        return new self(
            sprintf('No existe ningún «%s» con el id %s en esta web.', $type, $id),
            404,
        );
    }

    public static function readOnly(): self
    {
        return new self(
            'Esta web está conectada en modo solo lectura. Para permitir cambios hay que activar la escritura en su configuración.',
            403,
        );
    }

    /**
     * @param  array<int, string>  $writable
     */
    public static function notWritable(string $field, array $writable): self
    {
        return new self(
            sprintf(
                'El campo «%s» no se puede cambiar en este tipo de contenido. Los que sí: %s.',
                $field,
                implode(', ', $writable),
            ),
            403,
        );
    }

    public static function nothingToChange(): self
    {
        return new self('No se ha indicado ningún campo que cambiar.', 422);
    }

    /**
     * @param  array<int, string>  $accepted
     */
    public static function unknownStatus(string $status, array $accepted): self
    {
        return new self(
            sprintf(
                'Esta web no entiende el estado «%s». Solo tiene dos: publicado o no. Usa uno de estos: %s.',
                $status,
                implode(', ', $accepted),
            ),
            422,
        );
    }

    /**
     * @param  array<int, string>  $columns
     */
    public static function notFillable(string $model, array $columns): self
    {
        return new self(
            sprintf(
                'El modelo %s no acepta escribir en %s. Está declarado en config/ruklab.php pero falta en su $fillable, '
                .'así que el cambio se habría descartado sin avisar.',
                class_basename($model),
                implode(', ', $columns),
            ),
            500,
        );
    }

    public static function menusUnavailable(): self
    {
        return new self(
            'Esta web no guarda sus menús en base de datos, así que no se pueden editar desde aquí. El resto del contenido sí.',
            409,
        );
    }
}
