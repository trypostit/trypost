<?php

declare(strict_types=1);

test('dialog and slide-over footers put cancel before their action', function () {
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js'));
    $checked = 0;

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'vue') {
            continue;
        }

        preg_match_all(
            '/<(DialogFooter|SheetFooter)\b[^>]*>(.*?)<\/\1>/s',
            file_get_contents($file->getPathname()),
            $footers,
            PREG_SET_ORDER,
        );

        foreach ($footers as $footer) {
            $cancelPosition = stripos($footer[2], 'cancel');

            if ($cancelPosition === false) {
                continue;
            }

            $checked++;
            $firstButtonEnd = strpos($footer[2], '</Button>');
            $cancelIsFirst = $firstButtonEnd !== false && $cancelPosition < $firstButtonEnd;
            $actionAfterCancel = strpos($footer[2], '<Button', $cancelPosition) !== false;
            $path = substr($file->getPathname(), strlen($root) + 1);

            expect($cancelIsFirst)->toBeTrue("{$path} must render Cancel first.");
            expect($actionAfterCancel)->toBeTrue("{$path} must render Cancel before its action.");
        }
    }

    expect($checked)->toBeGreaterThan(0);
});
