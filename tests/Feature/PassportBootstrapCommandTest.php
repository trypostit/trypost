<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PassportSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

beforeEach(function () {
    ensurePassportKeyPermissions();
});

function passportPersonalAccessClients()
{
    return Client::query()
        ->where('revoked', false)
        ->where(function ($query): void {
            $query->whereNull('provider')->orWhere('provider', 'users');
        })
        ->get()
        ->filter(fn (Client $client): bool => $client->hasGrantType('personal_access'))
        ->values();
}

function ensurePassportKeyPermissions(): void
{
    foreach ([storage_path('oauth-private.key'), storage_path('oauth-public.key')] as $path) {
        if (is_file($path)) {
            chmod($path, 0600);
        }
    }
}

function useTemporaryPassportKeys(): void
{
    static $keyPath;

    if (! $keyPath) {
        $keyPath = sys_get_temp_dir().'/trypost-passport-test-keys';

        if (! is_dir($keyPath)) {
            mkdir($keyPath, 0700, true);
        }

        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            throw new RuntimeException('Could not generate temporary Passport private key.');
        }

        openssl_pkey_export($privateKey, $privateKeyContents);

        $publicKeyDetails = openssl_pkey_get_details($privateKey);

        if (! is_array($publicKeyDetails) || ! isset($publicKeyDetails['key'])) {
            throw new RuntimeException('Could not generate temporary Passport public key.');
        }

        file_put_contents($keyPath.'/oauth-private.key', $privateKeyContents);
        file_put_contents($keyPath.'/oauth-public.key', $publicKeyDetails['key']);
        chmod($keyPath.'/oauth-private.key', 0600);
        chmod($keyPath.'/oauth-public.key', 0600);
    }

    Passport::loadKeysFrom($keyPath);
}

it('creates the users personal access client when missing', function () {
    Client::query()->delete();

    expect(passportPersonalAccessClients())->toHaveCount(0);

    $this->artisan('trypost:bootstrap-passport')
        ->expectsOutputToContain('Passport personal access client is ready.')
        ->assertSuccessful();

    $clients = passportPersonalAccessClients();

    expect($clients)->toHaveCount(1);
    expect($clients->first()->revoked)->toBeFalse();
});

it('does not create duplicate personal access clients on repeated runs', function () {
    $this->artisan('trypost:bootstrap-passport')->assertSuccessful();
    $this->artisan('trypost:bootstrap-passport')->assertSuccessful();

    expect(passportPersonalAccessClients())->toHaveCount(1);
});

it('does not revoke or replace an existing personal access client', function () {
    $this->artisan('trypost:bootstrap-passport')->assertSuccessful();

    $client = passportPersonalAccessClients()->first();

    $this->artisan('trypost:bootstrap-passport')->assertSuccessful();

    expect(passportPersonalAccessClients())->toHaveCount(1);
    expect($client->refresh()->revoked)->toBeFalse();
    expect(passportPersonalAccessClients()->first()->id)->toBe($client->id);
});

it('keeps existing tokens valid after repeated bootstrap runs', function () {
    $this->artisan('trypost:bootstrap-passport')->assertSuccessful();
    useTemporaryPassportKeys();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $token = $user->createToken('Existing')->token;
    AccessToken::find($token->id)
        ->forceFill(['workspace_id' => $workspace->id])
        ->saveQuietly();

    $this->artisan('trypost:bootstrap-passport')->assertSuccessful();

    $token = $token->refresh();

    expect($token->revoked)->toBeFalse();
    expect($token->client_id)->toBe(passportPersonalAccessClients()->first()->id);
});

it('allows creating api keys after passport bootstrap', function () {
    $this->artisan('trypost:bootstrap-passport')->assertSuccessful();
    useTemporaryPassportKeys();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post(route('app.api-keys.store'), ['name' => 'My API Key'])
        ->assertRedirect();

    $tokens = AccessToken::where('user_id', $user->id)
        ->where('workspace_id', $workspace->id)
        ->get();

    expect($tokens)->toHaveCount(1);
    expect($tokens->first()->revoked)->toBeFalse();
});

it('creates a new valid client when the only personal access client is revoked', function () {
    Client::query()->delete();

    $revoked = app(ClientRepository::class)->createPersonalAccessGrantClient('Revoked client', 'users');
    $revoked->forceFill(['revoked' => true])->save();
    $revokedSecret = $revoked->secret;

    $this->artisan('trypost:bootstrap-passport')
        ->expectsOutputToContain('Passport personal access client is ready.')
        ->assertSuccessful();

    $clients = passportPersonalAccessClients();

    expect($clients)->toHaveCount(1);
    expect($clients->first()->id)->not->toBe($revoked->id);
    expect($revoked->refresh()->revoked)->toBeTrue();
    expect($revoked->refresh()->secret)->toBe($revokedSecret);
});

it('returns a clear failure when passport bootstrap fails', function () {
    $this->app->bind(PassportSeeder::class, fn () => new class extends PassportSeeder
    {
        public function run(ClientRepository $clients): void
        {
            throw new RuntimeException('simulated seeder failure');
        }
    });

    $this->artisan('trypost:bootstrap-passport')
        ->expectsOutputToContain('Passport bootstrap failed: simulated seeder failure')
        ->assertFailed();
});

it('does not regenerate passport keys', function () {
    $privateKey = storage_path('oauth-private.key');
    $publicKey = storage_path('oauth-public.key');

    file_put_contents($privateKey, 'existing-private-key');
    file_put_contents($publicKey, 'existing-public-key');

    $this->artisan('trypost:bootstrap-passport')->assertSuccessful();

    expect(file_get_contents($privateKey))->toBe('existing-private-key');
    expect(file_get_contents($publicKey))->toBe('existing-public-key');
});

it('registers the bootstrap command once', function () {
    $commands = collect(Artisan::all())->keys()
        ->filter(fn (string $name): bool => $name === 'trypost:bootstrap-passport')
        ->values();

    expect($commands)->toHaveCount(1);
});

it('does not print the client secret', function () {
    Client::query()->delete();

    Artisan::call('trypost:bootstrap-passport');

    $output = Artisan::output();
    $client = passportPersonalAccessClients()->sole();

    expect($output)->not->toContain($client->secret);
});
