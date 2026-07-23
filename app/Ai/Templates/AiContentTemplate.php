<?php

declare(strict_types=1);

namespace App\Ai\Templates;

use App\Enums\Ai\ContentStyle;
use Illuminate\Contracts\JsonSchema\JsonSchema;

interface AiContentTemplate
{
    /** The typed enum case for this style. */
    public function style(): ContentStyle;

    /** Stable key used in the request + registry, e.g. 'image_card'. */
    public function key(): string;

    /** i18n key for the gallery label. */
    public function name(): string;

    /** i18n key for the gallery description. */
    public function description(): string;

    /** Public path to the preview thumbnail shown in the picker. */
    public function previewAsset(): string;

    /** Whether a social account must be selected for this template. */
    public function needsAccount(): bool;

    /**
     * Whether this template's generated image honors the workspace brand
     * palette (so the "Brand colors" choice is meaningful). Tweet-card styles
     * are brand-colored by design and ignore the flag.
     */
    public function appliesBrandVisuals(): bool;

    /**
     * Content types (by value) this template can produce. Empty = all AI formats.
     *
     * @return array<int, string>
     */
    public function supportedFormats(): array;

    /** The Blade view path for the generator prompt. */
    public function promptView(TemplateContext $context): string;

    /**
     * The structured-output schema for the generator.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema, TemplateContext $context): array;

    /**
     * Build the post from the (humanized) structured output.
     *
     * @param  array<string, mixed>  $structured
     */
    public function assemble(array $structured, TemplateContext $context): GeneratedPost;
}
