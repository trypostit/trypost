<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus as Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Mail\PostPreview;
use Symfony\Component\DomCrawler\Crawler;

test('the email channel component resolves the platform name and its network logo', function (Platform $platform) {
    $html = view('mail.post-published', [
        'title' => 'Published',
        'workspaceName' => 'Launch Team',
        'url' => 'https://example.test/post',
        'publishedUrl' => null,
        'publication' => ['platform' => $platform, 'accountName' => 'Paulo <Studio>'],
        'postPreview' => ['text' => 'Launch day', 'image' => null, 'attachment' => null],
    ])->render();
    $document = new Crawler($html);
    $icon = 'images/accounts/'.$platform->network().'.png';

    expect($document->filter('img[src="'.asset($icon).'"]')->count())->toBe(1)
        ->and($document->filter('p')->reduce(fn (Crawler $node): bool => $node->text() === 'Paulo <Studio>')->count())->toBe(1)
        ->and($html)->toContain(e($platform->label()))
        ->not->toContain('<Studio>', '<x-channel-identity', '{!! platformExpression !!}')
        ->and(is_file(public_path($icon)))->toBeTrue();
})->with(Platform::cases());

test('publication emails show the channel and escaped post content separately', function (Status $status, Locale $locale) {
    app()->setLocale($locale->value);
    $account = SocialAccount::factory()->instagram()->create([
        'display_name' => 'Paulo Castellano',
        'username' => 'paulocastellano',
    ]);
    $post = Post::factory()->forAccount($account)->create([
        'content' => '<p>Tom &amp; Jerry</p><p>New post</p>',
        'publish_status' => $status,
        'platform_url' => 'https://www.instagram.com/p/published-post/',
        'error_message' => 'Connection expired <script>alert(1)</script>',
    ]);

    $mail = $status === Status::Published ? new PostPublished($post) : new PostPublishFailed($post);
    $html = $mail->render();
    $document = new Crawler($html);

    expect($html)->toContain("Tom &amp; Jerry\nNew post", 'Paulo Castellano', 'Instagram')
        ->not->toContain('Instagram (@paulocastellano)')
        ->and($document->filter('script')->count())->toBe(0)
        ->and($document->filter('img[src="'.asset('images/accounts/instagram.png').'"]')->count())->toBe(1);

    if ($status === Status::Published) {
        expect($document->filter('a[href="https://www.instagram.com/p/published-post/"]')->count())->toBe(1)
            ->and($document->filter('a[href="https://www.instagram.com/p/published-post/"]')->text())->toBe(__('mail.post_published.button'))
            ->and($document->filter('a[href="'.route('app.posts.edit', $post).'"]')->text())->toBe(__('mail.post_published.open_in_app'))
            ->and($document->filter('body')->text())->not->toContain('https://www.instagram.com/p/published-post/')
            ->and($html)->not->toContain('Connection expired');

        $publicAction = $document->filter('a[href="https://www.instagram.com/p/published-post/"]');
        $appAction = $document->filter('a[href="'.route('app.posts.edit', $post).'"]');

        expect($publicAction->closest('tr')->getNode(0))->toBe($appAction->closest('tr')->getNode(0))
            ->and($publicAction->attr('style'))->toContain('background-color: #ddd6fe', 'border')
            ->and($appAction->attr('style'))->toContain('background-color: #ffffff', 'border');
    } else {
        $mail->assertSeeInOrderInHtml([
            'New post',
            __('mail.post_preview.error'),
            'Connection expired &lt;script&gt;alert(1)&lt;/script&gt;',
        ], false);
    }
})->with([Status::Published, Status::Failed])->with([Locale::English, Locale::PortugueseBrazil, Locale::Arabic]);

test('published email keeps the channel snapshot and falls back to the app when no public link exists', function () {
    $post = Post::factory()->instagram()->published()->create([
        'content' => null,
        'social_account_id' => null,
        'platform_name' => 'Archived profile',
        'platform_url' => null,
    ]);

    $mail = new PostPublished($post);

    $mail->assertSeeInHtml('Archived profile');
    $mail->assertSeeInHtml(__('mail.post_preview.no_text'));
    $mail->assertSeeInHtml(route('app.posts.edit', $post));

    expect((new Crawler($mail->render()))->filter('a[href="'.route('app.posts.edit', $post).'"]')->count())->toBe(1);
    $mail->assertSeeInHtml(__('mail.post_published.open_in_app'));
    $mail->assertDontSeeInHtml(__('mail.post_published.button'));
});

test('the email preview uses the first image and preserves its proportions', function () {
    $post = Post::factory()->make([
        'content' => null,
        'media' => [[
            'path' => 'portrait.jpg',
            'url' => 'https://example.test/portrait.jpg',
            'type' => 'image',
            'meta' => ['width' => 1080, 'height' => 1920, 'alt_text' => 'Our studio'],
        ]],
    ]);

    expect(PostPreview::from($post)['image'])->toBe([
        'url' => 'https://example.test/portrait.jpg',
        'alt' => 'Our studio',
        'width' => 158,
    ]);
});

test('the email preview does not render a video as an image', function () {
    $post = Post::factory()->make([
        'content' => null,
        'media' => [[
            'path' => 'launch.mp4',
            'url' => 'https://example.test/launch.mp4',
            'type' => 'video',
            'original_filename' => 'Launch video.mp4',
        ], [
            'path' => 'second.jpg',
            'url' => 'https://example.test/second.jpg',
            'type' => 'image',
        ]],
    ]);

    expect(PostPreview::from($post))->toBe([
        'text' => '',
        'image' => null,
        'attachment' => 'Launch video.mp4',
    ]);
});

test('the image preview renders without a caption and escapes its alternative text', function () {
    $post = Post::factory()->instagram()->published()->create([
        'content' => null,
        'media' => [[
            'path' => 'photo.jpg',
            'url' => 'https://example.test/photo.jpg',
            'type' => 'image',
            'meta' => ['alt_text' => 'Studio " onclick="alert(1)'],
        ]],
    ]);

    $document = new Crawler((new PostPublished($post))->render());
    $image = $document->filter('img[src="https://example.test/photo.jpg"]');

    expect($image->count())->toBe(1)
        ->and($image->attr('alt'))->toBe('Studio " onclick="alert(1)')
        ->and($image->attr('onclick'))->toBeNull();
});
