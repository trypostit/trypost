<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\ClientRepository;
use RuntimeException;

class PassportSeeder extends Seeder
{
    public function run(ClientRepository $clients): void
    {
        Cache::lock('passport:personal-access-client', 30)->block(10, function () use ($clients): void {
            try {
                $clients->personalAccessClient('users');

                return;
            } catch (RuntimeException) {
                // No client yet — fall through to create.
            }

            $clients->createPersonalAccessGrantClient(name: 'TryPost Personal Access Client');
        });
    }
}
