<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_06_230924_drop_billing_email_from_accounts_table.php');
});

afterEach(function () {
    if (Schema::hasColumn('accounts', 'billing_email')) {
        $this->migration->up();
    }
});

test('drops the billing email column from accounts', function () {
    if (! Schema::hasColumn('accounts', 'billing_email')) {
        $this->migration->down();
    }

    $this->migration->up();

    expect(Schema::hasColumn('accounts', 'billing_email'))->toBeFalse();
});

test('down re-adds the billing email column as nullable', function () {
    $this->migration->down();

    expect(Schema::hasColumn('accounts', 'billing_email'))->toBeTrue()
        ->and(collect(Schema::getColumns('accounts'))->firstWhere('name', 'billing_email')['nullable'])->toBeTrue();
});
