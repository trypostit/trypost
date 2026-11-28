import type { TimeFormat, WeekStart } from '@/preferences';

export interface DetectedPreferences {
    timezone: string;
    week_starts_on: WeekStart;
    time_format: TimeFormat | null;
}

const SUNDAY_FIRST_ZONES = [
    'Asia/Tokyo',
    'Asia/Seoul',
    'Asia/Jerusalem',
    'Asia/Manila',
    'Asia/Taipei',
    'Asia/Hong_Kong',
    'Asia/Macau',
    'Asia/Kolkata',
    'Asia/Bangkok',
    'Asia/Jakarta',
    'Asia/Singapore',
    'Asia/Karachi',
    'Africa/Johannesburg',
    'Pacific/Honolulu',
    'Pacific/Guam',
];

const MONDAY_FIRST_AMERICAS = [
    'America/Argentina/',
    'America/Buenos_Aires',
    'America/Montevideo',
    'America/Santiago',
    'America/Punta_Arenas',
    'America/La_Paz',
    'America/Guayaquil',
    'America/Nuuk',
    'America/Cayenne',
    'America/Martinique',
    'America/Guadeloupe',
    'America/Paramaribo',
];

type WeekInfo = { firstDay?: number };

const browserWeekInfo = (language: string): WeekInfo | null => {
    try {
        const locale = new Intl.Locale(language) as Intl.Locale & {
            getWeekInfo?: () => WeekInfo;
            weekInfo?: WeekInfo;
        };

        return locale.getWeekInfo?.() ?? locale.weekInfo ?? null;
    } catch {
        return null;
    }
};

const isSundayFirstZone = (timezone: string): boolean =>
    SUNDAY_FIRST_ZONES.includes(timezone) ||
    (timezone.startsWith('America/') &&
        !MONDAY_FIRST_AMERICAS.some((prefix) => timezone.startsWith(prefix)));

export const detectWeekStart = (language: string, timezone: string): WeekStart => {
    const firstDay = browserWeekInfo(language)?.firstDay;

    if (firstDay !== undefined) {
        return firstDay === 7 ? 'sunday' : 'monday';
    }

    return isSundayFirstZone(timezone) ? 'sunday' : 'monday';
};

export const detectTimeFormat = (language: string): TimeFormat | null => {
    try {
        const cycle = new Intl.DateTimeFormat(language, { hour: 'numeric' })
            .resolvedOptions().hourCycle;

        if (cycle === undefined) {
            return null;
        }

        return cycle === 'h11' || cycle === 'h12' ? '12h' : '24h';
    } catch {
        return null;
    }
};

export const detectBrowserLanguage = (
    codes: string[],
    browserLanguages: readonly string[] = navigator.languages ?? [navigator.language],
): string | null => {
    const lower = codes.map((code) => code.toLowerCase());
    const base = (tag: string): string => tag.toLowerCase().split('-')[0];

    for (const tag of browserLanguages) {
        const exact = lower.indexOf(tag.toLowerCase());

        if (exact !== -1) {
            return codes[exact];
        }

        const sameBase = lower.findIndex((code) => base(code) === base(tag));

        if (sameBase !== -1) {
            return codes[sameBase];
        }
    }

    return null;
};

export const detectPreferences = (): DetectedPreferences => {
    const language = navigator.language || 'en-US';
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';

    return {
        timezone,
        week_starts_on: detectWeekStart(language, timezone),
        time_format: detectTimeFormat(language),
    };
};
