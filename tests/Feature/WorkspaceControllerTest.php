<?php

declare(strict_types=1);

use App\Ai\Agents\BrandAnalyzer;
use App\Enums\UserWorkspace\Role;
use App\Enums\Workspace\ContentLanguage;
use App\Models\Account;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Brand\LogoAttacher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

// Index tests
test('workspaces index requires authentication', function () {
    $response = $this->get(route('app.workspaces.index'));

    $response->assertRedirect(route('login'));
});

test('workspaces index shows all workspaces for user', function () {
    $workspaces = Workspace::factory()->count(2)->create(['user_id' => $this->user->id]);
    foreach ($workspaces as $workspace) {
        $workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    }

    $response = $this->actingAs($this->user)->get(route('app.workspaces.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workspaces/Index', false)
        ->has('workspaces', 3)
        ->has('currentWorkspaceId')
    );
});

// Create tests
test('create workspace requires authentication', function () {
    $response = $this->get(route('app.workspaces.create'));

    $response->assertRedirect(route('login'));
});

test('create workspace shows form for user with no workspaces', function () {
    // Delete existing workspace so user has none
    $this->user->update(['current_workspace_id' => null]);
    $this->workspace->delete();

    $response = $this->actingAs($this->user)->get(route('app.workspaces.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workspaces/Create', false)
        ->has('availableContentLanguages', count(ContentLanguage::cases()))
        ->where('availableContentLanguages.0', ['value' => 'en', 'label' => 'English', 'englishName' => 'English'])
    );
});

test('create workspace shows form when user already has workspace in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.workspaces.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workspaces/Create', false)
    );
});

// Store tests
test('store workspace requires authentication', function () {
    $response = $this->post(route('app.workspaces.store'), ['name' => 'Test Workspace']);

    $response->assertRedirect(route('login'));
});

test('store workspace creates first workspace', function () {
    // Delete existing workspace so user has none
    $this->user->update(['current_workspace_id' => null]);
    $this->workspace->delete();

    $response = $this->actingAs($this->user)->post(route('app.workspaces.store'), [
        'name' => 'New Workspace',
    ]);

    $response->assertRedirect(route('app.accounts'));

    $this->assertDatabaseHas('workspaces', [
        'name' => 'New Workspace',
        'user_id' => $this->user->id,
    ]);
});

test('store workspace creates second workspace in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->post(route('app.workspaces.store'), [
        'name' => 'Second Workspace',
    ]);

    $response->assertRedirect(route('app.accounts'));

    $this->assertDatabaseHas('workspaces', [
        'name' => 'Second Workspace',
        'user_id' => $this->user->id,
    ]);
});

test('store workspace validates name is required', function () {
    $response = $this->actingAs($this->user)->post(route('app.workspaces.store'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('store workspace sets new workspace as current', function () {
    // Delete existing workspace so user has none
    $this->user->update(['current_workspace_id' => null]);
    $this->workspace->delete();

    $this->actingAs($this->user)->post(route('app.workspaces.store'), [
        'name' => 'New Workspace',
    ]);

    $this->user->refresh();
    $newWorkspace = Workspace::where('name', 'New Workspace')->first();

    expect($this->user->current_workspace_id)->toBe($newWorkspace->id);
});

// Switch tests
test('switch workspace requires authentication', function () {
    $response = $this->post(route('app.workspaces.switch', $this->workspace));

    $response->assertRedirect(route('login'));
});

test('switch workspace changes current workspace', function () {
    $otherWorkspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Member->value]);

    $response = $this->actingAs($this->user)->post(route('app.workspaces.switch', $otherWorkspace));

    $response->assertRedirect(route('app.calendar'));

    $this->user->refresh();
    expect($this->user->current_workspace_id)->toBe($otherWorkspace->id);
});

test('switch workspace returns 403 for workspace user does not belong to', function () {
    $otherUser = User::factory()->create([]);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($this->user)->post(route('app.workspaces.switch', $otherWorkspace));

    $response->assertForbidden();
});

// Settings tests
test('workspace settings requires authentication', function () {
    $response = $this->get(route('app.workspace.settings'));

    $response->assertRedirect(route('login'));
});

test('workspace settings shows the workspace settings page', function () {
    $response = $this->actingAs($this->user)->get(route('app.workspace.settings'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/workspace/Workspace', false)
        ->has('workspace')
    );
});

test('brand settings shows the brand settings page', function () {
    $response = $this->actingAs($this->user)->get(route('app.workspace.brand'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/workspace/Brand', false)
        ->has('workspace')
        ->has('availableContentLanguages', count(ContentLanguage::cases()))
    );
});

test('workspace settings redirects to create if no workspace', function () {
    $this->user->update(['current_workspace_id' => null]);

    $response = $this->actingAs($this->user)->get(route('app.workspace.settings'));

    $response->assertRedirect(route('app.workspaces.create'));
});

// Update settings tests
test('update workspace settings requires authentication', function () {
    $response = $this->put(route('app.workspace.settings.update'), [
        'name' => 'Updated Name',
    ]);

    $response->assertRedirect(route('login'));
});

test('update workspace settings updates workspace and redirects back', function () {
    $response = $this->actingAs($this->user)
        ->from(route('app.workspace.brand'))
        ->put(route('app.workspace.settings.update'), [
            'name' => 'Updated Name',
            'brand_font' => 'Inter',
            'image_style' => 'cinematic',
        ]);

    $response->assertRedirect(route('app.workspace.brand'));

    $this->workspace->refresh();
    expect($this->workspace->name)->toBe('Updated Name');
});

test('update workspace settings persists brand voice traits', function () {
    $this->actingAs($this->user)
        ->from(route('app.workspace.brand'))
        ->put(route('app.workspace.settings.update'), [
            'name' => $this->workspace->name,
            'brand_font' => 'Inter',
            'image_style' => 'cinematic',
            'brand_voice_traits' => ['third_person', 'no_hype', 'data_driven'],
        ])->assertRedirect(route('app.workspace.brand'));

    expect($this->workspace->refresh()->brand_voice_traits)->toBe(['third_person', 'no_hype', 'data_driven']);
});

test('update workspace settings rejects an unknown brand voice trait', function () {
    $this->actingAs($this->user)
        ->from(route('app.workspace.brand'))
        ->put(route('app.workspace.settings.update'), [
            'name' => $this->workspace->name,
            'brand_font' => 'Inter',
            'image_style' => 'cinematic',
            'brand_voice_traits' => ['third_person', 'not_a_real_trait'],
        ])->assertSessionHasErrors('brand_voice_traits.1');
});

test('update workspace settings persists the image_style choice', function () {
    $this->actingAs($this->user)
        ->from(route('app.workspace.brand'))
        ->put(route('app.workspace.settings.update'), [
            'name' => $this->workspace->name,
            'brand_font' => 'Inter',
            'image_style' => 'minimalist',
        ])->assertRedirect(route('app.workspace.brand'));

    expect($this->workspace->refresh()->image_style->value)->toBe('minimalist');
});

test('update workspace settings updates the name when only the name is submitted', function () {
    $this->actingAs($this->user)
        ->from(route('app.workspace.settings'))
        ->put(route('app.workspace.settings.update'), [
            'name' => 'Name Only Update',
        ])
        ->assertRedirect(route('app.workspace.settings'))
        ->assertSessionHasNoErrors();

    expect($this->workspace->refresh()->name)->toBe('Name Only Update');
});

test('update workspace settings still requires brand_font and image_style when submitted empty', function () {
    $this->actingAs($this->user)
        ->put(route('app.workspace.settings.update'), [
            'name' => $this->workspace->name,
            'brand_font' => '',
            'image_style' => '',
        ])->assertSessionHasErrors(['brand_font', 'image_style']);
});

test('update workspace settings rejects unknown image_style values', function () {
    $this->actingAs($this->user)
        ->put(route('app.workspace.settings.update'), [
            'name' => $this->workspace->name,
            'brand_font' => 'Inter',
            'image_style' => 'pixel-art',
        ])->assertSessionHasErrors(['image_style']);
});

test('update workspace settings persists a newly supported content language', function () {
    $this->actingAs($this->user)
        ->from(route('app.workspace.brand'))
        ->put(route('app.workspace.settings.update'), [
            'name' => $this->workspace->name,
            'content_language' => 'fr',
        ])->assertRedirect(route('app.workspace.brand'))
        ->assertSessionHasNoErrors();

    expect($this->workspace->refresh()->content_language)->toBe('fr');
});

test('update workspace settings rejects an unsupported content language', function () {
    $this->actingAs($this->user)
        ->put(route('app.workspace.settings.update'), [
            'name' => $this->workspace->name,
            'content_language' => 'sv',
        ])->assertSessionHasErrors(['content_language']);
});

test('update workspace settings validates required fields', function () {
    $response = $this->actingAs($this->user)->put(route('app.workspace.settings.update'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors(['name']);
});

// Logo upload tests
test('upload workspace logo requires authentication', function () {
    $response = $this->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->image('logo.jpg'),
    ]);

    $response->assertRedirect(route('login'));
});

test('upload workspace logo succeeds with valid image', function () {
    $response = $this->actingAs($this->user)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->image('logo.jpg', 200, 200),
    ]);

    $response->assertRedirect();

    $this->workspace->refresh();
    expect($this->workspace->has_logo)->toBeTrue();
    expect($this->workspace->logo_url)->not->toBeNull();
});

test('upload workspace logo validates file is an image', function () {
    $response = $this->actingAs($this->user)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->create('document.pdf', 100),
    ]);

    $response->assertSessionHasErrors('photo');
});

test('upload workspace logo validates max size', function () {
    $response = $this->actingAs($this->user)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->image('logo.jpg')->size(3000),
    ]);

    $response->assertSessionHasErrors('photo');
});

test('upload workspace logo requires authorization', function () {
    $otherUser = User::factory()->create([]);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkspace->members()->attach($otherUser->id, ['role' => Role::Member->value]);
    $otherUser->update(['current_workspace_id' => $otherWorkspace->id]);
    $otherUser->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    // otherUser is account owner of their own account/workspace, so policy 'update' passes for their own workspace.
    // Switch their current workspace to the original $this->workspace (which they don't own) to trigger forbidden.
    $otherUser->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($otherUser)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->image('logo.jpg'),
    ]);

    $response->assertForbidden();
});

test('delete workspace logo requires authentication', function () {
    $response = $this->delete(route('app.workspace.delete-logo'));

    $response->assertRedirect(route('login'));
});

test('delete workspace logo succeeds', function () {
    // Upload first
    $this->actingAs($this->user)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->image('logo.jpg', 200, 200),
    ]);

    $this->workspace->refresh();
    expect($this->workspace->has_logo)->toBeTrue();

    // Delete
    $response = $this->actingAs($this->user)->delete(route('app.workspace.delete-logo'));

    $response->assertRedirect();

    $this->workspace->refresh();
    expect($this->workspace->has_logo)->toBeFalse();
});

test('delete workspace logo requires authorization', function () {
    $otherUser = User::factory()->create([]);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkspace->members()->attach($otherUser->id, ['role' => Role::Member->value]);
    $otherUser->update(['current_workspace_id' => $otherWorkspace->id]);
    $otherUser->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $otherUser->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($otherUser)->delete(route('app.workspace.delete-logo'));

    $response->assertForbidden();
});

// Destroy tests
test('destroy workspace requires authentication', function () {
    $response = $this->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertRedirect(route('login'));
});

test('destroy workspace deletes the workspace', function () {
    Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $workspaceId = $this->workspace->id;

    $response = $this->actingAs($this->user)->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertRedirect(route('app.workspaces.index'));
    expect(Workspace::find($workspaceId))->toBeNull();
});

test('destroy workspace clears current workspace if deleting current', function () {
    Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)->delete(route('app.workspaces.destroy', $this->workspace));

    $this->user->refresh();
    expect($this->user->current_workspace_id)->toBeNull();
});

test('destroy workspace reassigns current to another joined workspace', function () {
    $other = Workspace::factory()->create(['user_id' => $this->user->id]);
    $other->members()->attach($this->user->id, ['role' => Role::Member->value]);

    $this->actingAs($this->user)->delete(route('app.workspaces.destroy', $this->workspace));

    $this->user->refresh();
    expect($this->user->current_workspace_id)->toBe($other->id);
});

test('destroy workspace is blocked when it is the only workspace', function () {
    config(['trypost.self_hosted' => false]);
    $this->user->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
    $workspaceId = $this->workspace->id;

    $response = $this->actingAs($this->user)->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertSessionHas('flash.error', __('workspaces.cannot_delete_last'));
    expect(Workspace::find($workspaceId))->not->toBeNull();
});

test('destroy workspace allows deleting the only workspace in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);
    $workspaceId = $this->workspace->id;

    $response = $this->actingAs($this->user)->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertRedirect(route('app.workspaces.index'));
    expect(Workspace::find($workspaceId))->toBeNull();
});

test('destroy workspace returns 403 for non-owner', function () {
    $otherUser = User::factory()->create([]);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkspace->members()->attach($otherUser->id, ['role' => Role::Member->value]);
    $otherUser->update(['current_workspace_id' => $otherWorkspace->id]);
    $otherUser->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($otherUser)->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertForbidden();
});

// Autofill brand tests
test('autofillBrand returns metadata without persisting anything', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    Http::fake([
        'example.com/*' => Http::response(
            '<html><head><title>Acme</title><meta name="description" content="We sell rockets." /></head><body></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
        'example.com' => Http::response(
            '<html><head><title>Acme</title><meta name="description" content="We sell rockets." /></head><body></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $initialWorkspaceCount = Workspace::count();

    $response = $this->actingAs($user)
        ->postJson(route('app.workspaces.autofill'), ['url' => 'https://example.com']);

    $response->assertOk();
    $response->assertJsonStructure(['name', 'brand_description', 'content_language', 'logo_url']);

    expect(Workspace::count())->toBe($initialWorkspaceCount);
});

test('autofillBrand is always allowed even without a subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create(['trial_ends_at' => null]);
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    expect($account->subscribed(Account::SUBSCRIPTION_NAME))->toBeFalse();

    Http::fake([
        'example.com/*' => Http::response(
            '<html><head><title>Acme</title><meta name="description" content="We sell rockets." /></head><body></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
        'example.com' => Http::response(
            '<html><head><title>Acme</title><meta name="description" content="We sell rockets." /></head><body></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $this->actingAs($user)
        ->postJson(route('app.workspaces.autofill'), ['url' => 'https://example.com'])
        ->assertOk();
});

test('autofillBrand never records AI usage even when the LLM runs', function () {
    config(['trypost.self_hosted' => false]);
    config()->set('services.gemini.api_key', 'fake-key');
    config()->set('ai.default', 'gemini');

    Http::fake([
        'example.com' => Http::response(
            '<html lang="en"><head><title>Acme</title><meta name="description" content="Terse." /></head><body><main><p>Acme ships rockets fast.</p></main></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    BrandAnalyzer::fake([
        ['description' => 'Acme ships rockets fast.', 'language' => 'en'],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.workspaces.autofill'), ['url' => 'https://example.com'])
        ->assertOk();

    expect(AiUsageLog::count())->toBe(0);
});

test('autofillBrand validates url is required', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);

    $this->actingAs($user)
        ->postJson(route('app.workspaces.autofill'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

// Brand-aware store tests
test('store persists brand fields and redirects to /accounts', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($user)->post(route('app.workspaces.store'), [
        'name' => 'Acme Inc',
        'brand_website' => 'https://acme.example',
        'brand_description' => 'We sell rockets.',
        'brand_voice_traits' => ['third_person', 'no_hype'],
        'content_language' => 'en',
    ]);

    $response->assertRedirect(route('app.accounts'));

    $workspace = Workspace::where('name', 'Acme Inc')->sole();
    expect($workspace->name)->toBe('Acme Inc');
    expect($workspace->brand_website)->toBe('https://acme.example');
    expect($workspace->brand_description)->toBe('We sell rockets.');
});

test('store persists a newly supported non-default content language', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $this->actingAs($user)->post(route('app.workspaces.store'), [
        'name' => 'Beispiel GmbH',
        'content_language' => 'de',
    ])->assertSessionHasNoErrors();

    // 'de' is not the DB default ('en'), so this proves the field is written.
    expect(Workspace::where('name', 'Beispiel GmbH')->sole()->content_language)->toBe('de');
});

test('store rejects an unsupported content language', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $this->actingAs($user)->post(route('app.workspaces.store'), [
        'name' => 'Bad Lang',
        'content_language' => 'sv',
    ])->assertSessionHasErrors(['content_language']);
});

test('store redirects additional workspace to /accounts', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    // First workspace already exists
    $existing = Workspace::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
    $existing->members()->attach($user->id, ['role' => Role::Member->value]);

    $response = $this->actingAs($user)->post(route('app.workspaces.store'), [
        'name' => 'Second Workspace',
    ]);

    $response->assertRedirect(route('app.accounts'));
    expect(Workspace::where('account_id', $account->id)->count())->toBe(2);
});

test('store blocks a second workspace without an active subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    // The account already owns one workspace and has no subscription, so a
    // direct POST must not bootstrap a second (billable) workspace.
    $existing = Workspace::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
    $existing->members()->attach($user->id, ['role' => Role::Member->value]);

    $response = $this->actingAs($user)->post(route('app.workspaces.store'), [
        'name' => 'Second Workspace',
    ]);

    $response->assertRedirect(route('app.billing.index'));
    expect(Workspace::where('account_id', $account->id)->count())->toBe(1);
});

test('store attaches logo when logo_url is provided', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $logoAttacher = $this->mock(LogoAttacher::class);
    $logoAttacher->shouldReceive('attach')->once();

    $this->actingAs($user)->post(route('app.workspaces.store'), [
        'name' => 'Acme',
        'logo_url' => 'https://example.com/logo.png',
    ])->assertRedirect();
});

test('update settings attaches the autofilled logo when logo_url is provided', function () {
    $logoAttacher = $this->mock(LogoAttacher::class);
    $logoAttacher->shouldReceive('attach')->once();

    $this->actingAs($this->user)
        ->from(route('app.workspace.brand'))
        ->put(route('app.workspace.settings.update'), [
            'name' => $this->workspace->name,
            'logo_url' => 'https://example.com/logo.png',
        ])->assertRedirect(route('app.workspace.brand'))
        ->assertSessionHasNoErrors();
});

test('update settings does not touch the logo when no logo_url is provided', function () {
    $logoAttacher = $this->mock(LogoAttacher::class);
    $logoAttacher->shouldReceive('attach')->never();

    $this->actingAs($this->user)
        ->put(route('app.workspace.settings.update'), [
            'name' => $this->workspace->name,
        ])->assertRedirect();
});
