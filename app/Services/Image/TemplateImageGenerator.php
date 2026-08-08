<?php

declare(strict_types=1);

namespace App\Services\Image;

use App\Enums\Workspace\ImageStyle;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Ai\AiImageClient;
use App\Services\Ai\RecordAiUsage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;

class TemplateImageGenerator
{
    public const DEFAULT_WIDTH = 1080;

    public const DEFAULT_HEIGHT = 1350;

    /** Active canvas width. Set per render call so templates can scale. */
    private int $width = self::DEFAULT_WIDTH;

    /** Active canvas height. Set per render call so templates can scale. */
    private int $height = self::DEFAULT_HEIGHT;

    public function __construct(
        private BrandColorMapper $colorMapper,
        private AiImageClient $aiImage,
    ) {}

    /**
     * Render a slide and return the storage path plus the source meta needed
     * to regenerate it later. Returns null on failure (e.g. the AI client
     * could not produce a usable image).
     *
     * @param  array<int, string>  $imageKeywords
     * @return array{path: string, source_meta: array<string, mixed>}|null
     */
    public function render(
        Workspace $workspace,
        SocialAccount $socialAccount,
        string $title,
        string $body,
        array $imageKeywords,
        int $width = self::DEFAULT_WIDTH,
        int $height = self::DEFAULT_HEIGHT,
        ?string $backgroundPath = null,
        bool $applyBrandVisuals = true,
    ): ?array {
        $this->width = $width;
        $this->height = $height;

        $orientation = $this->orientationForCanvas();
        $rawStyle = $workspace->image_style;
        $imageStyle = match (true) {
            $rawStyle instanceof ImageStyle => $rawStyle,
            is_string($rawStyle) => ImageStyle::tryFrom($rawStyle) ?? ImageStyle::DEFAULT,
            default => ImageStyle::DEFAULT,
        };
        $language = $workspace->content_language;

        // When brand visuals are off (e.g. faithful curation), the AI background
        // is generated without brand colours or identity — neutral imagery driven
        // only by the post's own keywords.
        $brandColor = $applyBrandVisuals ? $workspace->brand_color : null;
        $backgroundColor = $applyBrandVisuals ? $workspace->background_color : null;
        $textColor = $applyBrandVisuals ? $workspace->text_color : null;
        $brandDescription = $applyBrandVisuals ? $workspace->brand_description : null;

        $generatedNewBackground = false;
        $resolvedBackgroundPath = null;
        $imageData = null;

        if (is_string($backgroundPath) && trim($backgroundPath) !== '' && Storage::exists($backgroundPath)) {
            $resolvedBackgroundPath = $backgroundPath;
            $imageData = Storage::get($backgroundPath);
        }

        if (! is_string($imageData) || $imageData === '') {
            $imageData = $this->aiImage->generate(
                keywords: $imageKeywords,
                style: $imageStyle,
                orientation: $orientation,
                language: $language,
                brandColor: $brandColor,
                backgroundColor: $backgroundColor,
                textColor: $textColor,
                brandDescription: $brandDescription,
            );

            if ($imageData === null) {
                return null;
            }

            $generatedNewBackground = true;
            $resolvedBackgroundPath = $this->storeBackgroundImage($imageData);
        }

        $manager = new ImageManager(Driver::class);

        $canvas = $this->renderTemplateA($manager, $imageData, $title, $body);
        $canvas = $this->renderFooter($canvas, $socialAccount);

        $filename = 'ai-images/'.uniqid('slide_', true).'.webp';
        Storage::put($filename, (string) $canvas->encode(new WebpEncoder(quality: 85)));

        if ($generatedNewBackground) {
            RecordAiUsage::recordImage(
                workspace: $workspace,
                provider: 'openai',
                model: AiImageClient::MODEL,
                metadata: [
                    'image_style' => $imageStyle->value,
                    'width' => $this->width,
                    'height' => $this->height,
                ],
            );
        }

        RecordAiUsage::recordTemplate(
            workspace: $workspace,
            provider: 'internal',
            metadata: [
                'width' => $this->width,
                'height' => $this->height,
            ],
        );

        return [
            'path' => $filename,
            'source_meta' => [
                'keywords' => array_values($imageKeywords),
                'style' => $imageStyle->value,
                'language' => $language,
                'model' => AiImageClient::MODEL,
                'title' => $title,
                'body' => $body,
                'width' => $this->width,
                'height' => $this->height,
                'brand_color' => $brandColor,
                'background_color' => $backgroundColor,
                'text_color' => $textColor,
                'background_path' => $resolvedBackgroundPath,
            ],
        ];
    }

    private function storeBackgroundImage(string $imageData): string
    {
        $manager = new ImageManager(Driver::class);
        $background = $manager->decodeBinary($imageData)->cover($this->width, $this->height);
        $backgroundPath = 'ai-images/'.uniqid('bg_', true).'.webp';

        Storage::put($backgroundPath, (string) $background->encode(new WebpEncoder(quality: 85)));

        return $backgroundPath;
    }

    /**
     * Pick the closest aspect ratio for the active canvas so the AI image
     * generator returns a photo that doesn't need heavy cropping.
     */
    private function orientationForCanvas(): string
    {
        $ratio = $this->width / $this->height;
        if ($ratio > 1.1) {
            return 'landscape';
        }
        if ($ratio < 0.9) {
            return 'portrait';
        }

        return 'squarish';
    }

    private function renderTemplateA(ImageManager $manager, string $imageData, string $title, string $body): ImageInterface
    {
        // Cover-fit Unsplash image to active canvas size.
        $image = $manager->decodeBinary($imageData)->cover($this->width, $this->height);

        // Smooth gradient mask: covers full image height, peaks at 0.9 alpha (linear).
        $this->applyBottomGradient($image, 1.0, 0.9, 1.0);

        $fontBold = $this->fontPath('Inter-Bold.ttf');
        $fontMedium = $this->fontPath('Inter-Medium.ttf');

        // Layout (bottom-up): footer area → body → title. All text rendered via raw GD
        // for pixel-precise positioning. Same wrap+measure helper used for layout math.
        $titleSize = 56;
        $bodySize = 28;
        $titleLineHeight = 1.25;
        $bodyLineHeight = 1.55;
        $footerReserved = 150;
        $bodyMargin = 16;
        $titleMargin = 36;
        $padding = 60;
        $maxWidth = $this->width - 2 * $padding;

        $bodyLines = $fontMedium ? $this->wrapText($body, $fontMedium, $bodySize, $maxWidth) : [];
        $titleLines = $fontBold ? $this->wrapText($title, $fontBold, $titleSize, $maxWidth) : [];

        $bodyHeight = $this->measureBlockHeight($bodyLines, $bodySize, $bodyLineHeight);
        $titleHeight = $this->measureBlockHeight($titleLines, $titleSize, $titleLineHeight);

        $bodyTopY = $this->height - $footerReserved - $bodyMargin - $bodyHeight;
        $titleTopY = $bodyTopY - $titleMargin - $titleHeight;

        $core = $image->core()->native();

        if ($fontBold && $titleLines) {
            $this->renderTextLines($core, $titleLines, $fontBold, $titleSize, $titleLineHeight, '#ffffff', $padding, $titleTopY);
        }
        if ($fontMedium && $bodyLines) {
            $this->renderTextLines($core, $bodyLines, $fontMedium, $bodySize, $bodyLineHeight, '#f5f5f5', $padding, $bodyTopY);
        }

        return $image;
    }

    /**
     * Wrap text into lines that fit within $maxWidth using the given font.
     * Respects explicit \n line breaks. Returns an array of line strings.
     *
     * @return array<int, string>
     */
    private function wrapText(string $text, string $fontPath, int $fontSize, int $maxWidth): array
    {
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];
            if (empty($words)) {
                $lines[] = '';

                continue;
            }
            $current = '';
            foreach ($words as $word) {
                $candidate = $current === '' ? $word : $current.' '.$word;
                $box = imagettfbbox($fontSize, 0, $fontPath, $candidate);
                $width = abs($box[2] - $box[0]);
                if ($width > $maxWidth && $current !== '') {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }
            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines;
    }

    /**
     * Compute total visual height of a wrapped text block. We rely on the actual
     * font ascent (from imagettfbbox of an x-height-tall sample) plus
     * (n-1) * line_spacing for a tight fit.
     *
     * @param  array<int, string>  $lines
     */
    private function measureBlockHeight(array $lines, int $fontSize, float $lineHeight): int
    {
        if (empty($lines)) {
            return 0;
        }
        $lineSpacing = (int) round($fontSize * $lineHeight);

        return $lineSpacing * count($lines);
    }

    /**
     * Render an array of lines line-by-line via imagettftext at explicit y positions.
     * $topY is where the first line's bounding box starts (top of first glyph row).
     *
     * @param  array<int, string>  $lines
     */
    private function renderTextLines($core, array $lines, string $fontPath, int $fontSize, float $lineHeight, string $hexColor, int $x, int $topY): void
    {
        $color = $this->allocateColor($core, $hexColor);
        $lineSpacing = (int) round($fontSize * $lineHeight);
        // imagettftext positions the text at the BASELINE. The font's ascent for our
        // body line height is roughly fontSize * 0.78 — we use that as the offset
        // from $topY to the first baseline.
        $ascent = (int) round($fontSize * 0.82);
        $baselineY = $topY + $ascent;

        foreach ($lines as $line) {
            imagettftext($core, $fontSize, 0, $x, $baselineY, $color, $fontPath, $line);
            $baselineY += $lineSpacing;
        }
    }

    /**
     * Allocate a GD color from a hex string (#rrggbb).
     *
     * @return int Color identifier suitable for GD draw functions.
     */
    private function allocateColor($core, string $hex): int
    {
        [$r, $g, $b] = $this->hexToRgb($hex);

        $color = imagecolorallocate($core, $r, $g, $b);

        return $color === false ? imagecolorallocate($core, 255, 255, 255) : $color;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Paint a smooth black-to-transparent gradient over the bottom of the image
     * directly on the GD resource. Avoids visible bands from stacked rectangles.
     *
     * @param  float  $easingPower  1.0 = linear, 2.0 = quadratic (slow start),
     *                              <1.0 = ramps up faster at the top.
     */
    private function applyBottomGradient(ImageInterface $image, float $heightFraction, float $maxAlpha, float $easingPower = 2.0): void
    {
        $maskHeight = (int) ($this->height * $heightFraction);
        $maskStart = $this->height - $maskHeight;

        $core = $image->core()->native(); // GD resource
        imagealphablending($core, true);

        for ($y = 0; $y < $maskHeight; $y++) {
            $progress = $y / max(1, $maskHeight - 1);
            $alphaFraction = (float) (pow($progress, $easingPower) * $maxAlpha);
            // GD alpha is inverted: 0=opaque, 127=transparent.
            $gdAlpha = (int) round(127 * (1 - $alphaFraction));
            $color = imagecolorallocatealpha($core, 0, 0, 0, $gdAlpha);
            imagefilledrectangle($core, 0, $maskStart + $y, $this->width - 1, $maskStart + $y, $color);
            imagecolordeallocate($core, $color);
        }
    }

    private function renderFooter(ImageInterface $canvas, SocialAccount $socialAccount): ImageInterface
    {
        // Footer uses Inter Light (300) in slate-grey — always legible on top
        // of the bottom dark gradient applied by Template A.
        $footerColor = '#9ca3af';

        $username = $socialAccount->username ?? '';
        $displayName = $socialAccount->display_label;

        // Footer row anchored from the bottom: avatar + handle + displayName
        // share the same vertical center so they line up cleanly.
        $avatarSize = 48;
        $avatarX = 60;
        $rowCenterY = $this->height - 100; // 100px from the bottom edge

        $avatarY = $rowCenterY - (int) ($avatarSize / 2);

        $textX = $avatarX + $avatarSize + 16;
        // intervention/image's `align('left', 'top')` positions text at its EM-box
        // top. Inter's visual glyph midpoint sits roughly at top + size * 0.42, so
        // we shift textY up by that amount to land its center on rowCenterY.
        $textY = $rowCenterY - (int) round(24 * 0.42);

        // Avatar (circular)
        $avatarBinary = $this->fetchAvatarBinary($socialAccount);
        if ($avatarBinary !== null) {
            $this->drawCircularAvatar($canvas, $avatarBinary, $avatarX, $avatarY, $avatarSize);
        }

        $fontLight = $this->fontPath('Inter-Light.ttf');
        if (! $fontLight || ! file_exists($fontLight)) {
            return $canvas;
        }

        if ($username) {
            $canvas->text('@'.$username, $textX, $textY, function (FontFactory $font) use ($fontLight, $footerColor) {
                $font->filename($fontLight);
                $font->size(24);
                $font->color($footerColor);
                $font->align('left', 'top');
            });
        }

        if ($displayName) {
            $canvas->text($displayName, $this->width - 60, $textY, function (FontFactory $font) use ($fontLight, $footerColor) {
                $font->filename($fontLight);
                $font->size(24);
                $font->color($footerColor);
                $font->align('right', 'top');
            });
        }

        return $canvas;
    }

    /**
     * Fetches the avatar binary via Storage (works with local, R2, S3 — whatever
     * `filesystems.default` is). Returns null when there's no avatar or the read fails.
     */
    private function fetchAvatarBinary(SocialAccount $socialAccount): ?string
    {
        $rawPath = $socialAccount->getRawOriginal('avatar_url');
        if (! $rawPath) {
            return null;
        }

        try {
            if (! Storage::exists($rawPath)) {
                return null;
            }
            $contents = Storage::get($rawPath);

            return $contents ?: null;
        } catch (\Throwable $e) {
            Log::warning('TemplateImageGenerator: avatar fetch failed', [
                'account' => $socialAccount->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Draws a circular avatar by overlaying a per-pixel alpha-masked GD truecolor
     * onto the canvas. Pixels outside the inscribed circle become fully transparent.
     */
    private function drawCircularAvatar(ImageInterface $canvas, string $avatarBinary, int $x, int $y, int $size): void
    {
        $core = $canvas->core()->native(); // GD resource

        $src = @imagecreatefromstring($avatarBinary);
        if (! $src) {
            return;
        }

        // Square-crop center, then resize to $size x $size.
        $sw = imagesx($src);
        $sh = imagesy($src);
        $crop = min($sw, $sh);
        $cx = (int) (($sw - $crop) / 2);
        $cy = (int) (($sh - $crop) / 2);
        $resized = imagecreatetruecolor($size, $size);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
        imagecopyresampled($resized, $src, 0, 0, $cx, $cy, $size, $size, $crop, $crop);
        imagedestroy($src);

        // Apply circular alpha mask.
        $cx = $size / 2;
        $cy = $size / 2;
        $r = $size / 2;
        for ($py = 0; $py < $size; $py++) {
            for ($px = 0; $px < $size; $px++) {
                $dx = $px + 0.5 - $cx;
                $dy = $py + 0.5 - $cy;
                $dist = sqrt($dx * $dx + $dy * $dy);
                if ($dist > $r) {
                    imagesetpixel($resized, $px, $py, $transparent);
                }
            }
        }

        // Composite onto the main canvas (alpha-aware).
        imagealphablending($core, true);
        imagecopy($core, $resized, $x, $y, 0, 0, $size, $size);
        imagedestroy($resized);
    }

    /**
     * Render text at a precise (x, baselineY) position with optional letter spacing.
     * Letter spacing > 0 renders each character individually with extra pixels between glyphs.
     */
    private function drawTextAt($core, string $text, string $fontPath, int $fontSize, string $hexColor, int $x, int $baselineY, int $letterSpacing = 0): void
    {
        $color = $this->allocateColor($core, $hexColor);

        if ($letterSpacing <= 0) {
            imagettftext($core, $fontSize, 0, $x, $baselineY, $color, $fontPath, $text);

            return;
        }

        $cursor = $x;
        $chars = mb_str_split($text);
        foreach ($chars as $char) {
            imagettftext($core, $fontSize, 0, $cursor, $baselineY, $color, $fontPath, $char);
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $char);
            $cursor += abs($bbox[2] - $bbox[0]) + $letterSpacing;
        }
    }

    /**
     * Measure the on-screen width of a string when rendered with extra letter spacing.
     */
    private function measureLetterSpacedWidth(string $text, string $fontPath, int $fontSize, int $letterSpacing): int
    {
        if ($letterSpacing <= 0) {
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $text);

            return abs($bbox[2] - $bbox[0]);
        }

        $width = 0;
        $chars = mb_str_split($text);
        $count = count($chars);
        foreach ($chars as $i => $char) {
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $char);
            $width += abs($bbox[2] - $bbox[0]);
            if ($i < $count - 1) {
                $width += $letterSpacing;
            }
        }

        return $width;
    }

    /**
     * Draw a 1px horizontal line on the GD resource with optional alpha (0..1).
     */
    private function drawHorizontalLine($core, int $x1, int $x2, int $y, string $hexColor, float $alpha = 1.0): void
    {
        [$r, $g, $b] = $this->hexToRgb($hexColor);
        // GD alpha: 0 opaque, 127 transparent.
        $gdAlpha = (int) round(127 * (1 - max(0.0, min(1.0, $alpha))));
        imagealphablending($core, true);
        $color = imagecolorallocatealpha($core, $r, $g, $b, $gdAlpha);
        imageline($core, $x1, $y, $x2, $y, $color);
        imagecolordeallocate($core, $color);
    }

    /**
     * Renders the post as a fake X/Twitter card.
     *
     * When $imageKeywords is null (default), a solid brand-color background is used
     * (original tweet_card behaviour). When $imageKeywords is a non-empty array, an
     * AI-generated photo is fetched, blurred, darkened, and used as the background
     * (tweet_card_image behaviour). Falls back to the solid colour when AI generation
     * returns null so the render never hard-fails.
     *
     * @param  array<int, string>|null  $imageKeywords
     * @return array{path: string, source_meta: array<string, mixed>}|null
     */
    public function renderTweetCard(Workspace $workspace, SocialAccount $socialAccount, string $tweetText, ?array $imageKeywords = null): ?array
    {
        $this->width = self::DEFAULT_WIDTH;
        $this->height = self::DEFAULT_HEIGHT;

        $manager = new ImageManager(Driver::class);

        $brandColor = $workspace->brand_color ?? '#1d9bf0';
        [$pr, $pg, $pb] = $this->hexToRgb($brandColor);

        $canvas = $manager->createImage($this->width, $this->height);
        $core = $canvas->core()->native();

        imagealphablending($core, true);
        imagesavealpha($core, false);

        $useImageBackground = $imageKeywords !== null && $imageKeywords !== [];

        if ($useImageBackground) {
            $canvas = $this->applyTweetCardImageBackground($manager, $canvas, $core, $workspace, $imageKeywords);
            $core = $canvas->core()->native();
        } else {
            $pageBg = imagecolorallocate($core, $pr, $pg, $pb);
            imagefill($core, 0, 0, $pageBg);
        }

        $this->drawTweetCardContent($canvas, $core, $socialAccount, $tweetText);

        $template = $useImageBackground ? 'tweet_card_image' : 'tweet_card';
        $filename = "ai-images/tweet_{$template}_".uniqid('', true).'.webp';
        Storage::put($filename, (string) $canvas->encode(new WebpEncoder(quality: 85)));

        RecordAiUsage::recordTemplate(
            workspace: $workspace,
            provider: 'internal',
            metadata: [
                'template' => $template,
                'width' => $this->width,
                'height' => $this->height,
            ],
        );

        $sourceMeta = [
            'template' => $template,
            'tweet_text' => $tweetText,
            'width' => $this->width,
            'height' => $this->height,
        ];

        if ($useImageBackground) {
            $sourceMeta['keywords'] = array_values($imageKeywords);
        }

        return [
            'path' => $filename,
            'source_meta' => $sourceMeta,
        ];
    }

    /**
     * Generate and apply the blurred + darkened AI photo background for tweet_card_image.
     * Falls back to a solid brand-color fill when the AI client returns null.
     *
     * @param  array<int, string>  $imageKeywords
     */
    private function applyTweetCardImageBackground(
        ImageManager $manager,
        ImageInterface $canvas,
        mixed $core,
        Workspace $workspace,
        array $imageKeywords,
    ): ImageInterface {
        $rawStyle = $workspace->image_style;
        $imageStyle = match (true) {
            $rawStyle instanceof ImageStyle => $rawStyle,
            is_string($rawStyle) => ImageStyle::tryFrom($rawStyle) ?? ImageStyle::DEFAULT,
            default => ImageStyle::DEFAULT,
        };

        $imageData = $this->aiImage->generate(
            keywords: $imageKeywords,
            style: $imageStyle,
            orientation: 'portrait',
            language: $workspace->content_language,
            brandColor: $workspace->brand_color,
            backgroundColor: $workspace->background_color,
            textColor: $workspace->text_color,
            brandDescription: $workspace->brand_description,
        );

        if ($imageData === null) {
            $brandColor = $workspace->brand_color ?? '#1d9bf0';
            [$pr, $pg, $pb] = $this->hexToRgb($brandColor);
            $pageBg = imagecolorallocate($core, $pr, $pg, $pb);
            imagefill($core, 0, 0, $pageBg);

            return $canvas;
        }

        RecordAiUsage::recordImage(
            workspace: $workspace,
            provider: 'openai',
            model: AiImageClient::MODEL,
            metadata: [
                'image_style' => $imageStyle->value,
                'width' => $this->width,
                'height' => $this->height,
            ],
        );

        $photo = $manager->decodeBinary($imageData)->cover($this->width, $this->height);

        // Apply Gaussian blur passes to soften the background photo.
        $photoCoreNative = $photo->core()->native();
        for ($i = 0; $i < 8; $i++) {
            imagefilter($photoCoreNative, IMG_FILTER_GAUSSIAN_BLUR);
        }

        // Composite the blurred photo onto the canvas.
        imagecopy($core, $photoCoreNative, 0, 0, 0, 0, $this->width, $this->height);

        // Paint a semi-transparent dark overlay (~50% opacity) so the white card pops.
        imagealphablending($core, true);
        $dark = imagecolorallocatealpha($core, 0, 0, 0, 63);
        imagefilledrectangle($core, 0, 0, $this->width - 1, $this->height - 1, $dark);
        imagecolordeallocate($core, $dark);

        return $canvas;
    }

    /**
     * Draw the tweet card content (rounded white card, avatar, name, badge, handle,
     * body text) onto the given canvas / GD core. Shared by the solid-color and
     * image-background render paths.
     */
    private function drawTweetCardContent(ImageInterface $canvas, mixed $core, SocialAccount $socialAccount, string $tweetText): void
    {
        $cardPadding = 64;
        $cardX = 72;
        $cardW = $this->width - 2 * $cardX;
        $cardRadius = 24;

        $fontBold = $this->fontPath('Inter-Bold.ttf');
        $fontMedium = $this->fontPath('Inter-Medium.ttf');
        $fontLight = $this->fontPath('Inter-Light.ttf');

        $avatarSize = 72;
        $headerH = $avatarSize + 2 * $cardPadding;
        $nameSize = 28;
        $handleSize = 22;
        $bodySize = 30;
        $bodyLineHeight = 1.55;
        $paragraphGap = (int) round($bodySize * $bodyLineHeight * 0.6);

        $paragraphs = array_values(array_filter(array_map('trim', explode("\n\n", $tweetText)), fn ($p) => $p !== ''));
        $allBodyLines = [];
        foreach ($paragraphs as $i => $para) {
            $lines = ($fontMedium ? $this->wrapText($para, $fontMedium, $bodySize, $cardW - 2 * $cardPadding) : [str_replace("\n", ' ', $para)]);
            if ($i > 0) {
                $allBodyLines[] = '';
            }
            foreach ($lines as $line) {
                $allBodyLines[] = $line;
            }
        }

        $bodyBlockH = 0;
        $lineSpacing = (int) round($bodySize * $bodyLineHeight);
        foreach ($allBodyLines as $line) {
            $bodyBlockH += ($line === '') ? $paragraphGap : $lineSpacing;
        }

        $cardContentH = $headerH + 24 + $bodyBlockH + $cardPadding;
        $cardH = max(400, $cardContentH);
        $cardY = (int) (($this->height - $cardH) / 2);

        $this->drawRoundedRect($core, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, $cardRadius, '#ffffff');

        $avatarX = $cardX + $cardPadding;
        $avatarY = $cardY + $cardPadding;

        $avatarBinary = $this->fetchAvatarBinary($socialAccount);
        if ($avatarBinary !== null) {
            $this->drawCircularAvatar($canvas, $avatarBinary, $avatarX, $avatarY, $avatarSize);
        }

        $nameX = $avatarX + $avatarSize + 16;

        $displayNameText = $socialAccount->display_label;
        $handleText = '@'.($socialAccount->username ?? '');
        $nameBox = $fontBold ? imagettfbbox($nameSize, 0, $fontBold, $displayNameText) : [0, 0, 0, 0, 0, 0, 0, 0];
        $handleBox = $fontLight ? imagettfbbox($handleSize, 0, $fontLight, $handleText) : [0, 0, 0, 0, 0, 0, 0, 0];
        $nameWidth = abs($nameBox[2] - $nameBox[0]);
        $nameAscent = (int) round(abs($nameBox[7]));
        $handleAscent = (int) round(abs($handleBox[7]));

        // Vertically center the two-line (name + handle) block against the avatar.
        $handleSpacing = (int) round($nameSize * 1.4);
        $blockHeight = $handleSpacing + $handleAscent;
        $nameY = $avatarY + (int) round(($avatarSize - $blockHeight) / 2);
        $nameBaselineY = $nameY + $nameAscent;
        $handleBaselineY = $nameY + $handleSpacing + $handleAscent;

        if ($fontBold && $displayNameText !== '') {
            $this->drawTextAt($core, $displayNameText, $fontBold, $nameSize, '#0f1419', $nameX, $nameBaselineY);
        }

        $verifiedPath = public_path('images/ai-templates/verified.png');
        if ($fontBold && $displayNameText !== '' && file_exists($verifiedPath)) {
            $badgeSize = (int) round($nameSize * 1.15);
            $badgeX = $nameX + $nameWidth + 8;
            $badgeY = $nameY + (int) round(($nameAscent - $badgeSize) / 2);
            $verifiedSrc = @imagecreatefrompng($verifiedPath);
            if ($verifiedSrc) {
                $verifiedResized = imagecreatetruecolor($badgeSize, $badgeSize);
                imagealphablending($verifiedResized, false);
                imagesavealpha($verifiedResized, true);
                $transparent = imagecolorallocatealpha($verifiedResized, 0, 0, 0, 127);
                imagefill($verifiedResized, 0, 0, $transparent);
                imagecopyresampled($verifiedResized, $verifiedSrc, 0, 0, 0, 0, $badgeSize, $badgeSize, imagesx($verifiedSrc), imagesy($verifiedSrc));
                imagealphablending($core, true);
                imagecopy($core, $verifiedResized, $badgeX, $badgeY, 0, 0, $badgeSize, $badgeSize);
                imagedestroy($verifiedSrc);
                imagedestroy($verifiedResized);
            }
        }

        if ($fontLight && $socialAccount->username) {
            $this->drawTextAt($core, $handleText, $fontLight, $handleSize, '#536471', $nameX, $handleBaselineY);
        }

        $bodyStartY = $cardY + $headerH + 8;
        $bodyX = $cardX + $cardPadding;

        if ($fontMedium) {
            $ascent = (int) round($bodySize * 0.82);
            $curY = $bodyStartY + $ascent;
            foreach ($allBodyLines as $line) {
                if ($line === '') {
                    $curY += $paragraphGap;

                    continue;
                }
                $color = $this->allocateColor($core, '#0f1419');
                imagettftext($core, $bodySize, 0, $bodyX, $curY, $color, $fontMedium, $line);
                $curY += $lineSpacing;
            }
        }
    }

    /**
     * Draw a filled rounded rectangle on the GD resource.
     */
    private function drawRoundedRect($core, int $x1, int $y1, int $x2, int $y2, int $radius, string $hexColor): void
    {
        [$r, $g, $b] = $this->hexToRgb($hexColor);
        $color = imagecolorallocate($core, $r, $g, $b);

        imagefilledrectangle($core, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($core, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);

        imagefilledellipse($core, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($core, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($core, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($core, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    private function fontPath(string $filename): ?string
    {
        $path = base_path('resources/fonts/'.$filename);

        return file_exists($path) ? $path : null;
    }
}
