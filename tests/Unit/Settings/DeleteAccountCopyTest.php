<?php

declare(strict_types=1);

test('workspace delete members warning describes permanent account deletion', function () {
    $warning = trans_choice('settings.workspace.delete_members_warning', 1, ['count' => 1]);

    expect($warning)
        ->toContain('permanently deleted')
        ->not->toContain('personal account');
});

test('account delete billing failure flash says nothing was deleted', function () {
    $flash = __('settings.flash.delete_failed_billing');

    expect($flash)
        ->toContain('Nothing was deleted')
        ->not->toContain('already removed');
});

test('account delete warning mentions invited members are permanently deleted', function () {
    expect(__('settings.delete_account.warning_message'))
        ->toContain('invited members')
        ->toContain('permanently deleted');

    expect(__('settings.delete_account.modal_description_password'))
        ->toContain('invited members')
        ->toContain('permanently deleted');
});
