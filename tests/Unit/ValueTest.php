<?php

declare(strict_types=1);

use Ruklab\Connector\Support\Value;

enum UbicacionDePrueba: string
{
    case Principal = 'principal';
}

enum SinValor
{
    case Uno;
}

describe('Value', function () {
    it('unwraps a backed enum, which is how the first real menu read failed', function () {
        // Un enum no es escalar y no tiene forma de cadena: castearlo a string
        // lanza. Los modelos de estas webs castean columnas a enums de rutina.
        expect(Value::plain(UbicacionDePrueba::Principal))->toBe('principal');
    });

    it('falls back to the name of an enum without a value', function () {
        expect(Value::plain(SinValor::Uno))->toBe('Uno');
    });

    it('formats a date the way the rest of the platform reads them', function () {
        expect(Value::plain(new DateTimeImmutable('2026-08-06 14:30:00')))->toBe('2026-08-06 14:30:00');
    });

    it('leaves alone what is already plain', function () {
        expect(Value::plain('hola'))->toBe('hola');
        expect(Value::plain(42))->toBe(42);
        expect(Value::plain(null))->toBeNull();
        expect(Value::plain(['a' => 1]))->toBe(['a' => 1]);
    });

    it('does not invent a value for an object that has none', function () {
        expect(Value::plain(new stdClass))->toBeNull();
    });
});
