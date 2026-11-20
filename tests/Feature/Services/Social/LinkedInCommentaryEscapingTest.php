<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\LinkCard\LinkCardFetcher;
use App\Services\Social\LinkCard\LinkCardMetadata;
use App\Services\Social\LinkedInPagePublisher;
use App\Services\Social\LinkedInPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function linkedInEscapingPost(string $network, string $content): Post
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);

    $account = $network === 'linkedin-page'
        ? SocialAccount::factory()->linkedinPage()->create([
            'workspace_id' => $workspace->id,
            'platform_user_id' => '123456',
            'token_expires_at' => now()->addDays(60),
            'meta' => ['organization_id' => '123456'],
        ])
        : SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $workspace->id,
            'platform_user_id' => 'abc123xyz',
            'token_expires_at' => now()->addDays(60),
        ]);

    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $user->id,
        'content' => $content,
        'content_type' => $network === 'linkedin-page' ? ContentType::LinkedInPagePost : ContentType::LinkedInPost,
        'meta' => ['link_preview' => false],
    ]);
    $post->setRelation('socialAccount', $account);

    return $post;
}

function linkedInEscapingPublisher(string $network): LinkedInPublisher|LinkedInPagePublisher
{
    return $network === 'linkedin-page' ? new LinkedInPagePublisher : new LinkedInPublisher;
}

/**
 * The commentary of every post creation request sent.
 *
 * @return array<int, string>
 */
function linkedInSentCommentaries(): array
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), '/rest/posts'))
        ->map(fn (array $pair) => $pair[0]['commentary'])
        ->values()
        ->all();
}

dataset('linkedin networks', ['linkedin', 'linkedin-page']);

test('linkedin escapes every character little reserves, keeping hashtags', function (string $content, string $commentary, string $network) {
    Http::fake([
        config("trypost.platforms.{$network}.api").'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    linkedInEscapingPublisher($network)->publish(linkedInEscapingPost($network, $content));

    expect(linkedInSentCommentaries())->toBe([$commentary]);
})->with([
    'the post that was cut' => [
        "🚨 PHP issued a new security advisory today (Oct 23).\n\nBut this one is very different.",
        "🚨 PHP issued a new security advisory today \\(Oct 23\\).\n\nBut this one is very different.",
    ],
    'a pipe' => ['a | b', 'a \| b'],
    'braces' => ['{json: true}', '\{json: true\}'],
    'an at sign' => ['ping @team', 'ping \@team'],
    'brackets' => ['[draft]', '\[draft\]'],
    'parentheses' => ['(Oct 23)', '\(Oct 23\)'],
    'angle brackets' => ['x < y > z', 'x \< y \> z'],
    'a hash inside a word' => ['C#', 'C\#'],
    'a backslash' => ['C:\Users\paulo', 'C:\\\\Users\\\\paulo'],
    'an asterisk' => ['5 * 3', '5 \* 3'],
    'an underscore' => ['snake_case', 'snake\_case'],
    'a tilde' => ['~20 people', '\~20 people'],
    'every reserved character together' => ['| { } @ [ ] ( ) < > # \\ * _ ~', '\| \{ \} \@ \[ \] \( \) \< \> \# \\\\ \* \_ \~'],
    'text that is already escaped' => ['a \(b\)', 'a \\\\\(b\\\\\)'],
    'code' => ['Use array_map() on $items[0]->tags', 'Use array\_map\(\) on $items\[0\]-\>tags'],
    'markdown-like emphasis' => ['*bold* _italic_ ~strike~ **double** __init__', '\*bold\* \_italic\_ \~strike\~ \*\*double\*\* \_\_init\_\_'],
    'a typed mention stays text' => ['@[Bill Gates](urn:li:person:123)', '\@\[Bill Gates\]\(urn:li:person:123\)'],
    'a typed hashtag template stays text' => ['{hashtag|#|spam}', '\{hashtag\|\#\|spam\}'],
    'an email and a handle' => ['Write to hi@trypost.it or @trypost', 'Write to hi\@trypost.it or \@trypost'],
    'a heart' => ['I <3 TryPost', 'I \<3 TryPost'],
    'html entities' => ['Tom &amp; Jerry &lt;3', 'Tom & Jerry \<3'],
    'characters that are not reserved' => ['Plain! Is it? Yes: 100% & $5 + tax = "ok"; ^ ` , . - \'', 'Plain! Is it? Yes: 100% & $5 + tax = "ok"; ^ ` , . - \''],
    'unicode text' => ['Olá, ação! 日本語 Привет مرحبا 🚀🎉', 'Olá, ação! 日本語 Привет مرحبا 🚀🎉'],
    'line breaks' => ["Line one\n\nLine two\n- item", "Line one\n\nLine two\n- item"],
    'a url with reserved characters' => ['https://example.com/a_b~c(1)#top', 'https://example.com/a\_b\~c\(1\)\#top'],
    'a url fragment that reads like a hashtag' => [
        'See https://example.com/docs/#install and www.php.net/#news',
        'See https://example.com/docs/\#install and www.php.net/\#news',
    ],
    'a url query' => ['https://x.com/s?q=a_b&tag=#x&u=@me', 'https://x.com/s?q=a\_b&tag=\#x&u=\@me'],
    'a url in parentheses' => ['(see https://example.com/page_1)', '\(see https://example.com/page\_1\)'],
    'an uppercase url' => ['Visit HTTPS://EXAMPLE.COM/?X=1&#Top or WWW.PHP.NET/?a=&#News', 'Visit HTTPS://EXAMPLE.COM/?X=1&\#Top or WWW.PHP.NET/?a=&\#News'],
    'a bare domain with a fragment' => ['example.com/page#top', 'example.com/page\#top'],
    'a bare domain with a fragment after a query' => ['trypost.it/pricing?plan=#yearly and example.com/?a=1&#Top', 'trypost.it/pricing?plan=\#yearly and example.com/?a=1&\#Top'],
    'a url followed by a hashtag' => ['https://trypost.it #buildinpublic', 'https://trypost.it #buildinpublic'],
    'the php.net advisory links' => [
        "Oct 23: https://www.php.net/index.php#2026-10-23-1\nOct 21: https://www.php.net/index.php#2026-10-21-5\n\n#PHP #CyberSecurity",
        "Oct 23: https://www.php.net/index.php\\#2026-10-23-1\nOct 21: https://www.php.net/index.php\\#2026-10-21-5\n\n#PHP #CyberSecurity",
    ],
    'hashtags after a space, a line break or the start' => ["#php first\nShip it #Laravel11\n#tips", "#php first\nShip it #Laravel11\n#tips"],
    'hashtags after brackets and quotes' => [
        '(#café) [#news] {#tag} "#tips" \'#one\' “#two” ‘#three’',
        '\(#café\) \[#news\] \{#tag\} "#tips" \'#one\' “#two” ‘#three’',
    ],
    'hashtags after punctuation' => ['Done.#Laravel Tags:#php a,#b c;#d wow!#e why?#f x-#g', 'Done.#Laravel Tags:#php a,#b c;#d wow!#e why?#f x-#g'],
    'hashtags after an emoji' => ['🚀#launch ❤️#love ✅️#shipped 1️⃣#first 👍🏽#yes', '🚀#launch ❤️#love ✅️#shipped 1️⃣#first 👍🏽#yes'],
    'hashtags in other scripts and with digits' => ['#café #日本 #Привет #web3 #2026Goals #3D', '#café #日本 #Привет #web3 #2026Goals #3D'],
    'a double hash' => ['##tag', '\##tag'],
    'an underscore ends the hashtag' => ['#machine_learning', '#machine\_learning'],
    'a hash after a letter, digit, slash or underscore' => ['F# abc#def x/#tag 5#tag _#tag', 'F\# abc\#def x/\#tag 5\#tag \_\#tag'],
    'a hash without a letter after it' => ['#1 #123 #2026 # alone #_tag #', '\#1 \#123 \#2026 \# alone \#\_tag \#'],
    'a keycap emoji' => ['#️⃣ keycap', '\#️⃣ keycap'],
    'editor html' => ['<p>Hello (world)</p><p>#tag</p>', "Hello \\(world\\)\n#tag"],
    'bold text' => ['<strong>Bold (x)</strong>', '𝗕𝗼𝗹𝗱 \(𝘅\)'],
    'no text' => ['', ''],
])->with('linkedin networks');

test('linkedin escapes the commentary of every post shape', function (array $media, string $network) {
    $post = linkedInEscapingPost($network, 'Launch (v2) #news');
    $post->update(['media' => $media]);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            return Http::response(['value' => [
                'uploadUrl' => 'https://www.linkedin.com/dms/upload/image',
                'image' => 'urn:li:image:FakeImageUrn',
            ]], 200);
        }

        if (str_contains($url, '/rest/documents') && str_contains($url, 'initializeUpload')) {
            return Http::response(['value' => [
                'uploadUrl' => 'https://www.linkedin.com/dms/upload/document',
                'document' => 'urn:li:document:FakeDocUrn',
            ]], 200);
        }

        if (str_contains($url, '/rest/documents/')) {
            return Http::response(['status' => 'AVAILABLE'], 200);
        }

        if (str_contains($url, '/dms/upload/')) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:shape']);
        }

        return Http::response('fake-media-bytes', 200);
    });

    linkedInEscapingPublisher($network)->publish($post);

    expect(linkedInSentCommentaries())->toBe(['Launch \(v2\) #news']);
})->with([
    'an image' => [[
        ['id' => 'image-1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
    ]],
    'a carousel' => [[
        ['id' => 'image-1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
        ['id' => 'image-2', 'path' => 'media/b.jpg', 'url' => 'https://example.com/b.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'b.jpg'],
    ]],
    'a document' => [[
        ['id' => 'doc-1', 'path' => 'media/deck.pdf', 'url' => 'https://example.com/deck.pdf', 'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf'],
    ]],
])->with('linkedin networks');

test('linkedin escapes the commentary once when the post is retried after a token refresh', function (string $network) {
    Http::fake([
        config("trypost.platforms.{$network}.oauth_api").'/oauth/v2/accessToken' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 5184000,
        ], 200),
        config("trypost.platforms.{$network}.api").'/rest/posts' => Http::sequence()
            ->push(['code' => 'EXPIRED_ACCESS_TOKEN', 'message' => 'The token used in the request has expired'], 401)
            ->push(null, 201, ['x-restli-id' => 'urn:li:share:retried']),
    ]);

    linkedInEscapingPublisher($network)->publish(linkedInEscapingPost($network, 'Fixed (again) #php'));

    expect(linkedInSentCommentaries())->toBe(['Fixed \(again\) #php', 'Fixed \(again\) #php']);
})->with('linkedin networks');

test('linkedin reads the link card from the unescaped text', function (string $network) {
    $post = linkedInEscapingPost($network, 'Read this https://example.com/my_article#intro (new)');
    $post->update(['meta' => []]);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->with('Read this https://example.com/my_article#intro (new)')
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/my_article#intro',
            title: 'The Article',
            description: 'A great read',
            imageUrl: null,
        ));

    Http::fake([
        config("trypost.platforms.{$network}.api").'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:article1',
        ]),
    ]);

    linkedInEscapingPublisher($network)->publish($post);

    expect(linkedInSentCommentaries())->toBe(['Read this https://example.com/my\_article\#intro \(new\)']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/rest/posts')
        && $request['content']['article']['source'] === 'https://example.com/my_article#intro'
        && $request['content']['article']['title'] === 'The Article');
})->with('linkedin networks');
