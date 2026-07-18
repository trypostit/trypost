<?php

declare(strict_types=1);

use App\Ai\Agents\BrandAnalyzer;
use App\Enums\Workspace\ContentLanguage;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

test('language schema allows every supported content language', function () {
    $schema = (new BrandAnalyzer)->schema(new JsonSchemaTypeFactory);

    // Guards against the enum silently shrinking back to a hardcoded subset:
    // the LLM may only emit a language the schema allows.
    expect($schema['language']->toArray()['enum'])->toBe(ContentLanguage::values());
});

test('instructions list every supported language code', function () {
    $instructions = (new BrandAnalyzer)->instructions();

    foreach (ContentLanguage::values() as $code) {
        expect($instructions)->toContain("`{$code}`");
    }
});
