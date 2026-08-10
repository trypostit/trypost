<?php

declare(strict_types=1);

namespace App\Services\Brand;

use App\Ai\Agents\BrandAnalyzer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;

final class BrandAnalyzerRunner
{
    private const int MARKDOWN_MAX_CHARS = 4000;

    public function isAvailable(): bool
    {
        return filled(config('ai.providers.'.config('ai.default').'.key'));
    }

    public function analyze(string $bodyHtml): ?LlmBrandAnalysis
    {
        $markdown = $this->htmlToMarkdown($bodyHtml);

        if ($markdown === '') {
            return null;
        }

        try {
            $response = (new BrandAnalyzer)->prompt($markdown);
        } catch (Throwable $e) {
            Log::warning('BrandAnalyzer failed, falling back to meta tags', ['error' => $e->getMessage()]);

            return null;
        }

        return LlmBrandAnalysis::fromResponse($response);
    }

    private function htmlToMarkdown(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $markdown = (new HtmlConverter(['strip_tags' => true]))->convert($html);

        return Str::limit(trim($markdown), self::MARKDOWN_MAX_CHARS, '');
    }
}
