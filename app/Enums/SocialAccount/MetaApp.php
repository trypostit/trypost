<?php

declare(strict_types=1);

namespace App\Enums\SocialAccount;

/**
 * The Meta developer apps a self-hosted install runs, each with its own secret
 * and its own Deauthorize and Data Deletion callback settings: the Facebook app
 * (Facebook Login, used for Pages and for Instagram through a Page), the
 * Instagram app (Instagram API with Instagram Login) and the Threads app.
 */
enum MetaApp: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Threads = 'threads';

    /**
     * The app secret Meta signs this app's callbacks with.
     */
    public function secret(): string
    {
        return (string) config("services.{$this->value}.client_secret");
    }

    /**
     * The channel platforms this app authorizes.
     *
     * @return list<Platform>
     */
    public function platforms(): array
    {
        return match ($this) {
            self::Facebook => [Platform::Facebook, Platform::InstagramFacebook],
            self::Instagram => [Platform::Instagram],
            self::Threads => [Platform::Threads],
        };
    }
}
