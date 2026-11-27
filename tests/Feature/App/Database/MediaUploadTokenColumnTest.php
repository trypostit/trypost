<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

test('media table has nullable unique upload_token column', function () {
    expect(Schema::hasColumn('medias', 'upload_token'))->toBeTrue();
});
