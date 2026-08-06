<?php

declare(strict_types=1);

namespace Ruklab\Connector\Support;

use BackedEnum;
use DateTimeInterface;
use UnitEnum;

/**
 * Turns whatever a model hands back into something that survives being JSON.
 *
 * Sites cast their columns: a menu location is an enum, a publication date is
 * a Carbon instance. Casting those to string blindly throws, which is how the
 * first real menu read failed — an enum is not a scalar and has no string
 * form. Each one has an obvious plain value; this is the one place that knows
 * which.
 */
final class Value
{
    public static function plain(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_scalar($value) || $value === null || is_array($value)) {
            return $value;
        }

        return method_exists($value, '__toString') ? (string) $value : null;
    }
}
