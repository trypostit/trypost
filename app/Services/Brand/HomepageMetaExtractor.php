<?php

declare(strict_types=1);

namespace App\Services\Brand;

use App\Enums\Workspace\ContentLanguage;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * Deterministic extraction of brand metadata from homepage HTML.
 * Only reads meta tags and a handful of <link> elements — does not call any LLM.
 */
final class HomepageMetaExtractor
{
    private const array TITLE_SEPARATORS = [' | ', ' - ', ' — ', ' – '];

    public function __construct(
        private readonly CssColorFrequencyExtractor $cssColorFrequency = new CssColorFrequencyExtractor,
    ) {}

    public function extract(string $html, string $baseUrl, string $extraCss = ''): BrandMetadata
    {
        $crawler = new Crawler($html, $baseUrl);

        $colors = $this->extractColors($crawler, $html, $extraCss);

        return new BrandMetadata(
            name: $this->extractName($crawler, $baseUrl),
            description: $this->extractDescription($crawler),
            language: $this->extractLanguage($crawler),
            logoUrl: $this->extractLogoUrl($crawler, $baseUrl),
            brandColor: data_get($colors, 'brand_color'),
            backgroundColor: data_get($colors, 'background_color'),
            textColor: data_get($colors, 'text_color'),
        );
    }

    /**
     * Absolute URLs of external stylesheets referenced from the homepage.
     * Used to fetch CSS that the deterministic color extractor needs but isn't
     * inlined in <style> blocks.
     *
     * @return list<string>
     */
    public function extractStylesheetUrls(string $html, string $baseUrl): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $urls = [];

        $crawler->filter('link[rel="stylesheet"][href]')->each(function (Crawler $node) use ($baseUrl, &$urls): void {
            $href = trim((string) $node->attr('href', ''));
            if ($href === '' || str_starts_with(strtolower($href), 'data:')) {
                return;
            }

            $urls[] = UriResolver::resolve($href, $baseUrl);
        });

        return $urls;
    }

    /**
     * Pull colors from deterministic signals: <meta name="theme-color">, CSS
     * custom properties (--primary, --brand, --background, --foreground), and
     * top-level body { background / color } rules. As a last resort, fall back
     * to the most-frequent non-neutral hex in the raw HTML — works well for
     * sites whose brand color saturates the markup but doesn't expose semantic
     * hooks. The LLM cannot help here because we strip <style> blocks before
     * sending markdown to it.
     *
     * @return array{brand_color: ?string, background_color: ?string, text_color: ?string}
     */
    private function extractColors(Crawler $crawler, string $html, string $extraCss = ''): array
    {
        $brand = $this->metaContent($crawler, 'name', 'theme-color');

        $css = $this->collectStyleSheets($crawler)."\n".$extraCss;

        if ($brand === null || $this->normalizeHex($brand) === null) {
            $brand = $this->matchCssVar($css, ['primary', 'brand', 'brand-primary', 'accent', 'color-primary', 'main', 'theme']);
        }

        // Final fallback: scan all colour values in the collected CSS, cluster
        // perceptually similar shades and pick the most frequent non-neutral
        // cluster. Catches Tailwind/utility-CSS sites where no semantic var
        // exposes the brand colour.
        if ($brand === null || $this->normalizeHex($brand) === null) {
            $brand = $this->cssColorFrequency->extract($css);
        }

        $background = $this->matchCssVar($css, ['background', 'bg', 'background-color', 'surface', 'color-bg', 'body-bg']);
        $text = $this->matchCssVar($css, ['foreground', 'text', 'color-text', 'on-background', 'body-color']);

        if ($background === null) {
            $background = $this->matchBlockRule($css, 'body', 'background-color')
                ?? $this->matchBlockRule($css, 'body', 'background')
                ?? $this->matchBlockRule($css, 'html', 'background-color')
                ?? $this->matchBlockRule($css, 'html', 'background');
        }

        if ($text === null) {
            $text = $this->matchBlockRule($css, 'body', 'color')
                ?? $this->matchBlockRule($css, 'html', 'color');
        }

        return [
            'brand_color' => $this->normalizeHex($brand),
            'background_color' => $this->normalizeHex($background),
            'text_color' => $this->normalizeHex($text),
        ];
    }

    private function collectStyleSheets(Crawler $crawler): string
    {
        $css = '';

        $crawler->filter('style')->each(function (Crawler $node) use (&$css): void {
            $css .= "\n".$node->text('');
        });

        return $css;
    }

    /**
     * @param  list<string>  $names
     */
    private function matchCssVar(string $css, array $names): ?string
    {
        foreach ($names as $name) {
            if (preg_match('/--'.preg_quote($name, '/').'\s*:\s*([^;\n]+)/i', $css, $m) === 1) {
                $value = trim($m[1]);
                if ($this->normalizeHex($value) !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function matchBlockRule(string $css, string $selector, string $property): ?string
    {
        // Crude but effective: capture the contents of the first matching
        // selector { ... } block and grep for the property declaration.
        $pattern = '/(?:^|[^\.\#\w-])'.preg_quote($selector, '/').'\s*\{([^}]*)\}/i';
        if (preg_match($pattern, $css, $block) !== 1) {
            return null;
        }

        if (preg_match('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*([^;]+)/i', $block[1], $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    private function normalizeHex(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim($value));

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})\b/i', $trimmed, $m) === 1) {
            return strtolower($m[0]);
        }

        return null;
    }

    /**
     * Body content converted for the LLM. Returns the main element when available,
     * falling back to body, with script/style/nav/footer stripped.
     */
    public function extractBodyHtml(string $html): string
    {
        $crawler = new Crawler($html);
        $body = $crawler->filter('main')->first();

        if ($body->count() === 0) {
            $body = $crawler->filter('body')->first();
        }

        if ($body->count() === 0) {
            return '';
        }

        foreach (['script', 'style', 'nav', 'footer', 'noscript'] as $selector) {
            $body->filter($selector)->each(function (Crawler $node): void {
                $domNode = $node->getNode(0);
                $domNode?->parentNode?->removeChild($domNode);
            });
        }

        return $body->html();
    }

    private function extractName(Crawler $crawler, string $baseUrl): ?string
    {
        $ogSiteName = $this->metaContent($crawler, 'property', 'og:site_name');
        if ($ogSiteName !== null) {
            return $ogSiteName;
        }

        $domainName = $this->extractDomainName($baseUrl);

        $ogTitle = $this->metaContent($crawler, 'property', 'og:title');
        if ($ogTitle !== null) {
            return $this->pickBrandName($ogTitle, $domainName);
        }

        $title = $crawler->filter('title')->first();
        if ($title->count() > 0) {
            $titleText = trim($title->text(''));
            if ($titleText !== '') {
                return $this->pickBrandName($titleText, $domainName);
            }
        }

        return $domainName;
    }

    /**
     * Pick the cleanest brand name from a page title.
     *
     * If the title contains a separator (e.g. "Acme | Tagline"), strip the suffix and use
     * the prefix as the brand name. If there's no separator, the title is likely a tagline
     * with no brand prefix (e.g. "Email API, SMTP & Marketing Platform"), so we fall back
     * to the domain-derived name (e.g. "Sendkit") which is a much better default for the
     * workspace name.
     */
    private function pickBrandName(string $title, ?string $domainName): ?string
    {
        $stripped = $this->stripTitleSuffix($title);

        if ($stripped !== $title && $stripped !== '') {
            return $stripped;
        }

        return $domainName ?? ($stripped !== '' ? $stripped : null);
    }

    /**
     * Derive a brand name from the URL host.
     *
     * Examples: sendkit.dev → "Sendkit", www.acme.com → "Acme", acme.co.uk → "Acme".
     * Edge case: subdomain hosts like blog.acme.com return "Blog" — acceptable since users
     * typically enter their apex domain when registering a workspace.
     */
    private function extractDomainName(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = preg_replace('/^www\./i', '', $host);
        $first = explode('.', (string) $host)[0] ?? '';

        if ($first === '') {
            return null;
        }

        return ucfirst(strtolower($first));
    }

    private function extractDescription(Crawler $crawler): ?string
    {
        return $this->metaContent($crawler, 'name', 'description')
            ?? $this->metaContent($crawler, 'property', 'og:description');
    }

    private function extractLanguage(Crawler $crawler): ?string
    {
        $html = $crawler->filter('html')->first();

        if ($html->count() === 0) {
            return null;
        }

        $lang = trim((string) $html->attr('lang', ''));

        if ($lang === '') {
            return null;
        }

        return ContentLanguage::fromHtmlLang($lang)?->value;
    }

    private function extractLogoUrl(Crawler $crawler, string $baseUrl): ?string
    {
        $candidates = [];

        // Prefer modern PWA icons (rel="icon" with explicit large sizes — android-chrome-*,
        // favicon-512x512 etc.) because they're usually the cleanest full-logo version.
        // apple-touch-icon is a strong second (always 180x180). The classic /favicon.ico
        // lives inside rel*="icon" but loses the tiebreak on size. og:image is intentionally
        // excluded because it is almost always a social preview banner, not the logo.
        foreach ($crawler->filter('link[rel*="icon"]') as $node) {
            $href = (string) $node->getAttribute('href');
            if ($href === '') {
                continue;
            }

            $rel = strtolower((string) $node->getAttribute('rel'));
            $candidates[] = [
                'href' => $href,
                'size' => $this->parseIconSize($node->getAttribute('sizes')),
                'apple' => str_contains($rel, 'apple-touch-icon') ? 1 : 0,
            ];
        }

        if ($candidates === []) {
            // Fall back to the classic /favicon.ico convention — some sites don't declare
            // any <link rel="icon"> at all but still serve one at the well-known path.
            $origin = $this->originFromUrl($baseUrl);
            if ($origin !== null) {
                return $origin.'/favicon.ico';
            }

            return null;
        }

        // Biggest declared size wins; apple-touch-icon breaks ties over bare "icon".
        usort($candidates, fn (array $a, array $b) => $b['size'] <=> $a['size'] ?: $b['apple'] <=> $a['apple']);

        return UriResolver::resolve((string) data_get($candidates, '0.href'), $baseUrl);
    }

    private function originFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = (string) data_get($parts, 'scheme', '');
        $host = (string) data_get($parts, 'host', '');

        if ($scheme === '' || $host === '') {
            return null;
        }

        return "{$scheme}://{$host}";
    }

    private function parseIconSize(?string $sizes): int
    {
        if ($sizes === null || $sizes === '') {
            return 0;
        }

        preg_match_all('/(\d+)x\d+/', $sizes, $matches);

        return $matches[1] === [] ? 0 : max(array_map('intval', $matches[1]));
    }

    private function metaContent(Crawler $crawler, string $attr, string $value): ?string
    {
        $node = $crawler->filter(sprintf('meta[%s="%s"]', $attr, $value))->first();

        if ($node->count() === 0) {
            return null;
        }

        $content = trim((string) $node->attr('content', ''));

        return $content === '' ? null : $content;
    }

    private function stripTitleSuffix(string $title): string
    {
        foreach (self::TITLE_SEPARATORS as $sep) {
            $idx = mb_strpos($title, $sep);
            if ($idx !== false && $idx > 0) {
                return trim(mb_substr($title, 0, $idx));
            }
        }

        return trim($title);
    }
}
