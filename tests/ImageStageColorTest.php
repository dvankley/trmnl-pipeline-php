<?php

use Bnussbau\TrmnlPipeline\Data\ColorType;
use Bnussbau\TrmnlPipeline\Data\RgbColor;
use Bnussbau\TrmnlPipeline\Exceptions\ProcessingException;
use Bnussbau\TrmnlPipeline\Stages\ImageStage;

// Constants for test configuration
const IMG_WIDTH = 30;
const IMG_HEIGHT = 20;
const BAR_COUNT = 3;
const BAR_WIDTH = 10; // Derived from IMG_WIDTH / BAR_COUNT
const Y_SAMPLE = 10;  // floor(IMG_HEIGHT / 2)
const X_LEFT = 5;     // floor(BAR_WIDTH / 2)
const X_MIDDLE = 15;  // BAR_WIDTH + X_LEFT
const X_RIGHT = 25;   // (2 * BAR_WIDTH) + X_LEFT

// Common stage settings per scenario
const BW_COLORS = 2;
const BW_BIT_DEPTH = 1;
const RGB_COLORS = 16;
const RGB_BIT_DEPTH = 4;
const INDEXED_BIT_DEPTH = 2;

// Thresholds for color assertions
const MAX_LOW_COMPONENT = 40;                // acceptable low value for g/b when red is dominant
const MIN_NEAR_RED = 200;                    // minimum red component to consider a pixel near red
const MAX_NEAR_RED_OTHER = 80;               // maximum g/b when near red
const MAX_GREEN_WHEN_MAPPED_AWAY = 300;      // allow up to 255 if green remains strong after mapping
const MIN_HIGH_OTHER_WHEN_GREEN_MAPPED = -1; // minimum of r or b when green is mapped away (allow 0)
const AVERAGE_CHANNEL_THRESHOLD = 20;        // allowable per-channel delta between pre/post average colors

/**
 * Tests for color support in ImageStage
 */
describe('ImageStage Color Support', function (): void {
    beforeEach(function (): void {
        // Color definitions
        $this->testImageLeftColorRed = RgbColor::fromComponents(255, 0, 0);
        $this->testImageMiddleColorRed = RgbColor::fromComponents(255, 32, 32);
        $this->testImageRightColorGreen = RgbColor::fromComponents(0, 255, 0);

        // Palette is: pure red, near green
        $this->palette = [
            RgbColor::fromComponents(255, 0, 0),
            RgbColor::fromComponents(0, 255, 32)
        ];

        // Create a small test image with 3 distinct colors (left/middle/right bars)
        $this->colorImagePath = sys_get_temp_dir() . '/test_color_image.png';
        $imagick = new Imagick;
        $imagick->newImage(IMG_WIDTH, IMG_HEIGHT, new ImagickPixel('white'));
        $imagick->setImageFormat('png');

        $draw = new ImagickDraw;
        // Left third (x: 0..BAR_WIDTH-1): exact red
        $draw->setFillColor(new ImagickPixel($this->testImageLeftColorRed->toImagickString()));
        $draw->rectangle(0, 0, BAR_WIDTH - 1, IMG_HEIGHT - 1);
        // Middle third (x: BAR_WIDTH..2*BAR_WIDTH-1): near red
        $draw->setFillColor(new ImagickPixel($this->testImageMiddleColorRed->toImagickString()));
        $draw->rectangle(BAR_WIDTH, 0, (2 * BAR_WIDTH) - 1, IMG_HEIGHT - 1);
        // Right third (x: 2*BAR_WIDTH..3*BAR_WIDTH-1): green
        $draw->setFillColor(new ImagickPixel($this->testImageRightColorGreen->toImagickString()));
        $draw->rectangle(2 * BAR_WIDTH, 0, (3 * BAR_WIDTH) - 1, IMG_HEIGHT - 1);

        $imagick->drawImage($draw);
        $imagick->writeImage($this->colorImagePath);
        $imagick->clear();
    });

    afterEach(function (): void {
        // Clean up test image
        if (isset($this->colorImagePath) && file_exists($this->colorImagePath)) {
            unlink($this->colorImagePath);
        }
        if (isset($this->resultPath) && file_exists($this->resultPath)) {
            unlink($this->resultPath);
        }
    });

    it('defaults to grayscale when no color_type set', function (): void {
        $stage = new ImageStage;
        $stage->width(IMG_WIDTH)->height(IMG_HEIGHT)->colors(BW_COLORS)->bitDepth(BW_BIT_DEPTH);

        $this->resultPath = $stage($this->colorImagePath);

        expect(file_exists($this->resultPath))->toBeTrue();
        $resultImage = new Imagick($this->resultPath);
        // Check all three bars: left (red), middle (near-red), right (green)
        foreach ([[X_LEFT, Y_SAMPLE], [X_MIDDLE, Y_SAMPLE], [X_RIGHT, Y_SAMPLE]] as [$x, $y]) {
            $p = $resultImage->getImagePixelColor($x, $y);
            $c = $p->getColor();
            // In grayscale, R=G=B for every sampled pixel
            expect($c['r'])->toBe($c['g']);
            expect($c['g'])->toBe($c['b']);
        }
        $resultImage->clear();
    });

    it('preserves color when color_type is RGB', function (): void {
        $stage = new ImageStage;
        $stage
            ->colorType(ColorType::RGB)
            ->width(IMG_WIDTH)
            ->height(IMG_HEIGHT)
            ->colors(RGB_COLORS)
            ->bitDepth(RGB_BIT_DEPTH);

        $this->resultPath = $stage($this->colorImagePath);

        expect(file_exists($this->resultPath))->toBeTrue();
        $resultImage = new Imagick($this->resultPath);

        // All samples should be effectively unchanged in this case since there should be no
        //  loss of fidelity in this conversion.
        $leftSampleColor = $resultImage->getImagePixelColor(X_LEFT, Y_SAMPLE)->getColor();
        colorWithinThreshold($leftSampleColor, $this->testImageLeftColorRed->toArray(), 5);
        $middleSampleColor = $resultImage->getImagePixelColor(X_MIDDLE, Y_SAMPLE)->getColor();
        colorWithinThreshold($middleSampleColor, $this->testImageMiddleColorRed->toArray(), 5);
        $rightSampleColor = $resultImage->getImagePixelColor(X_RIGHT, Y_SAMPLE)->getColor();
        colorWithinThreshold($rightSampleColor, $this->testImageRightColorGreen->toArray(), 5);
        $resultImage->clear();
    });

    it('throws when indexed color_type without palette', function (): void {
        $stage = new ImageStage;
        $stage
            ->colorType(ColorType::INDEXED)
            ->width(IMG_WIDTH)
            ->height(IMG_HEIGHT)
            ->colors(count($this->palette))
            ->bitDepth(INDEXED_BIT_DEPTH);

        expect(fn(): string => $stage($this->colorImagePath))
            ->toThrow(ProcessingException::class, 'Palette is required when color_type is indexed');
    });

    it('maps to provided palette when indexed with palette', function (): void {
        $stage = new ImageStage;
        $stage
            ->colorType(ColorType::INDEXED)
            ->palette($this->palette)
            ->colors(count($this->palette))
            ->width(IMG_WIDTH)
            ->height(IMG_HEIGHT)
            ->bitDepth(INDEXED_BIT_DEPTH);

        // Compute average color before processing
        // We expect the dithering and remapping process to result in roughly the same average color.
        $avgBefore = averageColorFromImagePath($this->colorImagePath);

        $this->resultPath = $stage($this->colorImagePath);

        expect(file_exists($this->resultPath))->toBeTrue();
        $resultImage = new Imagick($this->resultPath);

        // Left bar (exact red) should map to palette red
        $leftSampleColor = $resultImage->getImagePixelColor(X_LEFT, Y_SAMPLE)->getColor();
        colorWithinThreshold($leftSampleColor, $this->palette[0]->toArray(), 5);
        // Middle bar (near red) should map to palette red
        //  There will be some pixels near the bottom that are the other palette color (green)
        //  once error has had a chance to accumulate. We'll detect that by averaging all pixel values below.
        $middleSampleColor = $resultImage->getImagePixelColor(X_MIDDLE, Y_SAMPLE)->getColor();
        colorWithinThreshold($middleSampleColor, $this->palette[0]->toArray(), 5);
        // Right bar (green); should map to the near-green palette entry
        $rightSampleColor = $resultImage->getImagePixelColor(X_RIGHT, Y_SAMPLE)->getColor();
        colorWithinThreshold($rightSampleColor, $this->palette[1]->toArray(), 5);

        // Compute average color after processing
        // Compare to average color before processing to verify that the remapping and dithering process hasn't
        //  substantially changed the average color value.
        $avgAfter = averageColorFromImagePath($this->resultPath);
        colorWithinThreshold($avgAfter, $avgBefore, AVERAGE_CHANNEL_THRESHOLD);

        $resultImage->clear();
    });

    /**
     * Assert that {@see $actual} is within {@see $threshold} of {@see $expected} for each channel.
     *
     * @param array{r:int,g:int,b:int} $actual
     * @param array{r:int,g:int,b:int} $expected
     * @param int $threshold
     * @return void
     */
    function colorWithinThreshold(array $actual, array $expected, int $threshold): void
    {
        test()->expect(abs($actual['r'] - $expected['r']))->toBeLessThan($threshold);
        test()->expect(abs($actual['g'] - $expected['g']))->toBeLessThan($threshold);
        test()->expect(abs($actual['b'] - $expected['b']))->toBeLessThan($threshold);
    }

    /**
     * Compute the average RGB color of all pixels in an image.
     *
     * @param string $path
     * @return array{r:int,g:int,b:int}
     */
    function averageColorFromImagePath(string $path): array
    {
        $imagick = new Imagick($path);
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();

        $sumR = 0;
        $sumG = 0;
        $sumB = 0;
        $total = $width * $height;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = $imagick->getImagePixelColor($x, $y)->getColor();
                $sumR += $color['r'];
                $sumG += $color['g'];
                $sumB += $color['b'];
            }
        }

        $imagick->clear();

        return [
            'r' => (int) round($sumR / $total),
            'g' => (int) round($sumG / $total),
            'b' => (int) round($sumB / $total),
        ];
    }
});
