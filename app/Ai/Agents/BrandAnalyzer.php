<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Enums\Workspace\BrandVoiceTrait;
use App\Enums\Workspace\ContentLanguage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class BrandAnalyzer implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return view('prompts.brand_analyzer', [
            'voice_groups' => BrandVoiceTrait::grouped(),
            'single_select_groups' => BrandVoiceTrait::singleSelectGroups(),
            'content_languages' => collect(ContentLanguage::values())
                ->map(fn (string $code) => "`{$code}`")
                ->implode(', '),
        ])->render();
    }

    public function provider(): Lab
    {
        return match (config('ai.default')) {
            'openai' => Lab::OpenAI,
            'anthropic' => Lab::Anthropic,
            default => Lab::Gemini,
        };
    }

    public function model(): string
    {
        return config('ai.default_text_model');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('The actual brand or company name (1-4 words, e.g. "Sendkit", "Acme Coffee", "Stripe"). Strip any tagline, slogan, or product descriptor — return only the brand identity itself.')
                ->required(),
            'description' => $schema->string()
                ->description('A concise 2-3 sentence brand description summarizing what the company does, who they serve, and what makes them unique. Written in the detected content language.')
                ->required(),
            'language' => $schema->string()
                ->enum(ContentLanguage::values())
                ->description('The primary language of the content.')
                ->required(),
            'brand_color' => $schema->string()
                ->description('The primary brand color as a hex string starting with # (e.g. "#0ea5e9"). Pick the most prominent accent color used in CTAs, links, or logos. Return empty string if not confidently identifiable.')
                ->required(),
            'background_color' => $schema->string()
                ->description('The dominant page background color as a hex string starting with # (e.g. "#ffffff" or "#0b0f19"). Return empty string if not confidently identifiable.')
                ->required(),
            'text_color' => $schema->string()
                ->description('The dominant body text color as a hex string starting with # (e.g. "#0f172a"). Return empty string if not confidently identifiable.')
                ->required(),
            'voice_traits' => $schema->array()
                ->items($schema->string()->enum(BrandVoiceTrait::values()))
                ->description('Brand voice traits inferred from the site, using only the allowed values. Pick AT MOST ONE per single-select dimension (point of view, formality, energy, humor, attitude, warmth, confidence) and any number of style traits. See the instructions for the groups and what each value means.')
                ->required(),
        ];
    }
}
