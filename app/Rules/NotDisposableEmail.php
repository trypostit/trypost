<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class NotDisposableEmail implements ValidationRule
{
    /**
     * The most common disposable email domains used for account farming. Covers
     * the bulk of the abuse without false positives; the list is extensible via
     * config (trypost.security.extra_disposable_domains).
     *
     * @var list<string>
     */
    private const DISPOSABLE_DOMAINS = [
        '10minutemail.com',
        '10minutemail.net',
        '33mail.com',
        'anonaddy.me',
        'burnermail.io',
        'byom.de',
        'dispostable.com',
        'dropmail.me',
        'emailondeck.com',
        'fakeinbox.com',
        'fakemail.net',
        'getairmail.com',
        'getnada.com',
        'guerrillamail.biz',
        'guerrillamail.com',
        'guerrillamail.de',
        'guerrillamail.info',
        'guerrillamail.net',
        'guerrillamail.org',
        'inboxkitten.com',
        'jetable.org',
        'linshiyouxiang.net',
        'mail-temp.com',
        'mail.tm',
        'mail7.io',
        'mailcatch.com',
        'maildrop.cc',
        'mailinator.com',
        'mailnesia.com',
        'mailsac.com',
        'mintemail.com',
        'mohmal.com',
        'moakt.com',
        'mytemp.email',
        'nada.email',
        'onetimemail.org',
        'sharklasers.com',
        'spam4.me',
        'spamgourmet.com',
        'temp-mail.io',
        'temp-mail.org',
        'tempail.com',
        'tempinbox.com',
        'tempmail.dev',
        'tempmail.plus',
        'tempmailo.com',
        'tempr.email',
        'throwawaymail.com',
        'tmail.io',
        'tmpmail.net',
        'trash-mail.com',
        'trashmail.com',
        'trashmail.de',
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
        'zohomail.wtf',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower(trim((string) $value));

        if (! str_contains($email, '@')) {
            return;
        }

        $domain = Str::afterLast($email, '@');

        $blocked = array_merge(
            self::DISPOSABLE_DOMAINS,
            (array) config('trypost.security.extra_disposable_domains', []),
        );

        if (in_array($domain, $blocked, true)) {
            $fail(__('auth.register.disposable_email'));
        }
    }
}
