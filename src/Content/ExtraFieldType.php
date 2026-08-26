<?php

declare(strict_types=1);

namespace Ruklab\Connector\Content;

/**
 * What kind of value an extra field holds, so Ruk Lab knows how to ask for it
 * and how to show it back — a relation is a name that resolves to this site's
 * own id, not a number nobody outside this site could ever guess.
 */
enum ExtraFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Url = 'url';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case Relation = 'relation';
}
