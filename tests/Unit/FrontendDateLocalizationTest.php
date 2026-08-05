<?php

declare(strict_types=1);

/**
 * Regression guard: display dates must use localized dayjs formats (LL/LLL/lll/LT)
 * or @/date helpers — not English/Portuguese-locked format strings.
 */
test('frontend display dates do not hardcode english or portuguese format literals', function () {
    $root = resource_path('js');
    $forbidden = [
        "format('D MMM YYYY",
        'format("D MMM YYYY',
        "format('D [de]",
        'format("D [de]',
        '[às]',
        '[at]',
        'Today at',
        'Just now',
        '4:21 PM',
        '4:18 PM',
        'Jan 20, 2026',
        'Jan 21, 2026',
        'DD/MM/YYYY HH:mm',
    ];

    $violations = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'ts', 'js'], true)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            continue;
        }

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = str_replace(base_path().'/', '', $file->getPathname()).": {$needle}";
            }
        }
    }

    expect($violations)->toBeEmpty(
        "Hardcoded date display literals found:\n".implode("\n", $violations)
    );
});

test('date helpers use localized dayjs formats', function () {
    $contents = file_get_contents(resource_path('js/date.ts'));

    expect($contents)
        ->toContain("format('LL')")
        ->toContain("format('LLL')")
        ->toContain("format('lll')")
        ->toContain("format('L LT')")
        ->toContain('formatPreviewPostedAt')
        ->toContain('formatDateOnly')
        ->toContain("dayjs.utc(date).format('LL')")
        ->not->toContain('[de]')
        ->not->toContain('[às]')
        ->not->toContain('.calendar()');
});

test('api key expiry uses formatDateOnly to avoid timezone day shift', function () {
    $apiKeys = file_get_contents(resource_path('js/pages/settings/workspace/ApiKeys.vue'));

    expect($apiKeys)
        ->toContain('date.formatDateOnly(token.expires_at)')
        ->not->toContain('date.formatDate(token.expires_at)');
});

test('discord preview does not use dayjs calendar plugin', function () {
    $contents = file_get_contents(resource_path('js/components/posts/previews/DiscordPreview.vue'));

    expect($contents)
        ->toContain('formatPreviewPostedAt')
        ->toContain("'discord'")
        ->not->toContain('.calendar(');
});

test('preview posted-at uses scheduled datetime when present', function () {
    $edit = file_get_contents(resource_path('js/pages/posts/Edit.vue'));

    expect($edit)
        ->toContain(':posted-at="scheduledDateTime || null"')
        ->toContain('hasPickedTime = ref(Boolean(post.value.scheduled_at))')
        ->not->toContain('hasPickedTime ? scheduledDateTime');
});
