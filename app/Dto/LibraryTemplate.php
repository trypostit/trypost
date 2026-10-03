<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enums\PostTemplate\Audience;
use App\Enums\PostTemplate\Format;
use App\Enums\PostTemplate\Goal;
use App\Enums\PostTemplate\Type;

final readonly class LibraryTemplate
{
    /**
     * @param  list<Audience>  $audiences
     */
    public function __construct(
        public string $key,
        public string $emoji,
        public Type $type,
        public array $audiences,
        public Format $format,
        public Goal $goal,
        public bool $featured,
    ) {}

    public function title(): string
    {
        return __("template_library.{$this->key}.title");
    }

    public function description(): string
    {
        return __("template_library.{$this->key}.description");
    }

    public function body(): string
    {
        return __("template_library.{$this->key}.body");
    }
}
