<?php

namespace Bnussbau\TrmnlPipeline\Data;

use ValueError;

/**
 * Color type supported by a given model.
 *
 * These color types effectively correspond to the color types in the PNG specification.
 * There are no alpha variants because this system is built around sending a single image
 *  to the display device and thus transparency is not useful.
 */
enum ColorType: string
{
    case GRAYSCALE = 'grayscale';
    case RGB = 'rgb';
    case INDEXED = 'indexed';

    /**
     * Create a ColorType from the corresponding string literal.
     */
    public static function fromString(string $value): self
    {
        return match ($value) {
            'grayscale' => self::GRAYSCALE,
            'rgb' => self::RGB,
            'indexed' => self::INDEXED,
            default => throw new ValueError("Unknown ColorType: $value"),
        };
    }
}
