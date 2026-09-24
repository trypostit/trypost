<?php

declare(strict_types=1);

test('the shared tokens match the Connect design', function () {
    $styles = file_get_contents(resource_path('css/app.css'));
    $buttons = file_get_contents(resource_path('js/components/ui/button/index.ts'));

    expect($styles)
        ->toContain('--primary: #fa5d19;')
        ->toContain('--border: #e9e6e3;')
        ->toContain("'Inter', ui-sans-serif")
        ->toContain('--radius: 0.525rem;');

    expect($buttons)
        ->toContain('bg-primary text-primary-foreground hover:bg-primary/90')
        ->toContain('"default": "h-9 px-4 py-2');
});

test('account and post pages use the full-width design while retaining the composer', function () {
    $accounts = file_get_contents(resource_path('js/pages/accounts/Index.vue'));
    $posts = file_get_contents(resource_path('js/pages/posts/Index.vue'));
    $layout = file_get_contents(resource_path('js/layouts/app/AppSidebarLayout.vue'));

    expect($accounts)
        ->toContain('<AppLayout full-width>')
        ->toContain('data-testid="accounts-scroll"');

    expect($posts)
        ->toContain('<AppLayout full-width>')
        ->toContain('data-testid="posts-scroll"')
        ->toContain('data-testid="posts-tabs"')
        ->toContain('<PostComposerDialog');

    expect($layout)
        ->toContain(':default-open="isOpen"')
        ->toContain('<GlobalPostComposer />');
});
