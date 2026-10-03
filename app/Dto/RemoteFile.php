<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enums\Media\Source;

/**
 * A file to download into the workspace. `allowedHosts` null means the SSRF
 * guard alone decides; otherwise every hop must match one of the hosts
 * (exact, or `*.suffix`).
 */
final readonly class RemoteFile
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<string>|null  $allowedHosts
     * @param  array<string, mixed>  $sourceMeta
     */
    public function __construct(
        public string $url,
        public string $filename,
        public array $headers = [],
        public ?array $allowedHosts = null,
        public ?Source $source = null,
        public array $sourceMeta = [],
    ) {}

    public static function fromUrl(string $url): self
    {
        return new self($url, basename((string) parse_url($url, PHP_URL_PATH)));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->url, $this->filename, $headers, $this->allowedHosts, $this->source, $this->sourceMeta);
    }

    /**
     * Header values can carry the user's access token, so Telescope only
     * ever records their names.
     *
     * @return array<string, mixed>
     */
    public function formatForTelescope(): array
    {
        return [
            'url' => $this->url,
            'filename' => $this->filename,
            'headers' => array_map(fn (): string => '********', $this->headers),
            'allowedHosts' => $this->allowedHosts,
            'source' => $this->source?->value,
            'sourceMeta' => $this->sourceMeta,
        ];
    }
}
