<?php

declare(strict_types=1);

use App\Support\Mail\PostExcerpt;

test('a less-than sign typed in the post stays in the email excerpt', function () {
    expect(PostExcerpt::from("I <3 TryPost\nprice < 10", 200))->toBe("I <3 TryPost\nprice < 10");
});

test('an excerpt of a post from the former rich editor keeps its paragraphs', function () {
    expect(PostExcerpt::from('<p>Hello <strong>world</strong></p><p>Bye</p>', 200))->toBe("Hello world\nBye");
});
