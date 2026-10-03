<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\User\TimeFormat;
use App\Models\User;
use App\Support\Timezone;
use Carbon\CarbonInterface;

/**
 * Renders an instant for one email recipient: in their time zone, on their
 * 12- or 24-hour clock, with the date words of the language the email is sent
 * in. Every email that shows a time goes through here.
 */
class RecipientTime
{
    public static function timezone(User $recipient): string
    {
        return Timezone::normalize($recipient->timezone);
    }

    public static function clock(CarbonInterface $at, User $recipient): string
    {
        return self::local($at, $recipient)->isoFormat(self::clockFormat($recipient));
    }

    public static function dateTime(CarbonInterface $at, User $recipient): string
    {
        $local = self::local($at, $recipient);

        return "{$local->isoFormat('LL')} {$local->isoFormat(self::clockFormat($recipient))}";
    }

    private static function local(CarbonInterface $at, User $recipient): CarbonInterface
    {
        return $at->copy()->setTimezone(self::timezone($recipient))->locale(app()->getLocale());
    }

    private static function clockFormat(User $recipient): string
    {
        return $recipient->time_format === TimeFormat::TwelveHour ? 'h:mm A' : 'HH:mm';
    }
}
