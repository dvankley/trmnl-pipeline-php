<?php

declare(strict_types=1);

namespace Bnussbau\TrmnlPipeline\Data;

/**
 * Value object representing an RGB color stored as a 24-bit integer (0xRRGGBB).
 */
final readonly class RgbColor
{
    /**
     * Packed 24-bit color value in 0xRRGGBB format
     */
    private int $value;

    /**
     * Create a color from a packed 24-bit int. Values are clamped into 0..0xFFFFFF.
     */
    public function __construct(int $value)
    {
        // Mask to 24-bit in case a larger int is provided
        $this->value = $value & 0xFFFFFF;
    }

    /**
     * Create a color from separate components (0..255 each).
     */
    public static function fromComponents(int $r, int $g, int $b): self
    {
        $r = self::clamp($r, 0, 255);
        $g = self::clamp($g, 0, 255);
        $b = self::clamp($b, 0, 255);

        $value = ($r << 16) | ($g << 8) | $b;

        return new self($value);
    }

    /**
     * Create a color from an Imagick-style associative array with r, g, b keys.
     *
     * @param array{r:int,g:int,b:int} $rgb
     */
    public static function fromArray(array $rgb): self
    {
        return self::fromComponents((int) $rgb['r'], (int) $rgb['g'], (int) $rgb['b']);
    }

    /**
     * Get the packed 24-bit integer value (0xRRGGBB).
     */
    public function toInt(): int
    {
        return $this->value;
    }

    /**
     * Get the color as an Imagick-compatible rgb() string.
     * Example: "rgb(255, 0, 0)"
     */
    public function toImagickString(): string
    {
        return sprintf('rgb(%d, %d, %d)', $this->r(), $this->g(), $this->b());
    }

    /**
     * Get the color as an associative array
     *
     * @return array{r:int,g:int,b:int}
     */
    public function toArray(): array
    {
        return ['r' => $this->r(), 'g' => $this->g(), 'b' => $this->b()];
    }

    /** Component getters (0..255) */
    public function r(): int
    {
        return ($this->value >> 16) & 0xFF;
    }

    public function g(): int
    {
        return ($this->value >> 8) & 0xFF;
    }

    public function b(): int
    {
        return $this->value & 0xFF;
    }

    private static function clamp(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }
}
