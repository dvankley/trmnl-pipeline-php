<?php

declare(strict_types=1);

namespace Bnussbau\TrmnlPipeline\Data;

use Bnussbau\TrmnlPipeline\Exceptions\ProcessingException;

/**
 * Model data structure loaded from models.json
 */
readonly class ModelData
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public int $width,
        public int $height,
        /**
         * @var int $colors The number of colors the model supports.
         * This value may be less than 2^$bitDepth in cases where the model's supported number of
         *  colors is not a power of 2.
         *
         * The actual set of colors is determined by this property in combination with the {@see self::$colorType} property.
         * For {@see ColorType::RGB} or {@see ColorType::GRAYSCALE}, the colors specified by this property's count are
         *  evenly distributed across the color space.
         * For {@see ColorType::INDEXED}, the colors are explicitly specified in the {@see self::$palette} property, and this
         *  property's value should match the size of the $palette array.
         *
         */
        public int $colors,
        /**
         * @var int $bitDepth The number of bits per pixel.
         */
        public int $bitDepth,
        public float $scaleFactor,
        public int $rotation,
        public string $mimeType,
        public int $offsetX,
        public int $offsetY,
        public string $publishedAt,
        public string $kind,
        public ColorType $colorType,
        /**
         * @var ?array<int> $palette An array of RGB codes for the model's palette.
         * This property is required for models with ColorType::INDEXED.
         *
         * Each entry should be defined in the typical RGB hex code format.
         * Specifically, a 48-bit number where the MSB is red, the middle byte is green, and the LSB is blue.
         */
        public ?array $palette = null,
    ) {}

    /**
     * Load model data from JSON file
     *
     * @return array<string, ModelData>
     *
     * @throws ProcessingException
     */
    public static function loadFromJson(): array
    {
        $jsonPath = __DIR__.'/models.json';

        if (! file_exists($jsonPath)) {
            throw new ProcessingException("Models JSON file not found at: {$jsonPath}");
        }

        $jsonContent = file_get_contents($jsonPath);
        if ($jsonContent === false) {
            throw new ProcessingException('Failed to read models JSON file');
        }

        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ProcessingException('Invalid JSON in models file: '.json_last_error_msg());
        }

        if (! is_array($data)) {
            throw new ProcessingException('Invalid JSON structure: expected array');
        }

        if (! isset($data['data']) || ! is_array($data['data'])) {
            throw new ProcessingException("Invalid models JSON structure: missing 'data' array");
        }

        $models = [];
        foreach ($data['data'] as $modelData) {
            if (! isset($modelData['name'])) {
                throw new ProcessingException("Model data missing required 'name' field");
            }

            $models[$modelData['name']] = new self(
                name: $modelData['name'],
                label: $modelData['label'] ?? '',
                description: $modelData['description'] ?? '',
                width: (int) ($modelData['width'] ?? 0),
                height: (int) ($modelData['height'] ?? 0),
                colors: (int) ($modelData['colors'] ?? 0),
                bitDepth: (int) ($modelData['bit_depth'] ?? 0),
                scaleFactor: (float) ($modelData['scale_factor'] ?? 1.0),
                rotation: (int) ($modelData['rotation'] ?? 0),
                mimeType: $modelData['mime_type'] ?? 'image/png',
                offsetX: (int) ($modelData['offset_x'] ?? 0),
                offsetY: (int) ($modelData['offset_y'] ?? 0),
                publishedAt: $modelData['published_at'] ?? '',
                kind: $modelData['kind'] ?? '',
                colorType: ColorType::fromString($modelData['color_type']),
                palette: isset($modelData['palette']) && is_array($modelData['palette']) ? $modelData['palette'] : null,
            );
        }

        return $models;
    }

    /**
     * Get model data by name
     *
     * @throws ProcessingException
     */
    public static function getByName(string $name): self
    {
        $models = self::loadFromJson();

        if (! isset($models[$name])) {
            throw new ProcessingException("Model '{$name}' not found in models data");
        }

        return $models[$name];
    }

    /**
     * Get all models of a specific kind
     *
     * @return array<ModelData>
     *
     * @throws ProcessingException
     */
    public static function getByKind(string $kind): array
    {
        $models = self::loadFromJson();

        return array_filter(
            $models,
            fn (ModelData $model): bool => $model->kind === $kind
        );
    }

    /**
     * Get all available model names
     *
     * @return array<string>
     *
     * @throws ProcessingException
     */
    public static function getAllNames(): array
    {
        $models = self::loadFromJson();

        return array_keys($models);
    }
}
