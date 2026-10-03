import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import { timeFormat, userTimezone } from '@/preferences';

/**
 * A dayjs instance bound to the current language. `dayjs.locale()` is global and
 * not reactive, so reading the ref here is what makes a computed re-run when the
 * language changes instead of serving the previous one from cache.
 */
const localized = (value?: dayjs.ConfigType) =>
    dayjs(value).locale(activeLocale.value.toLowerCase());

/**
 * The dayjs token for a clock time in the user's chosen format. Every time the
 * app shows goes through here, so the Preferences setting reaches all of them.
 */
const timeToken = (): string =>
    timeFormat.value === '12h' ? 'h:mm A' : 'HH:mm';

/** The signed-in user's time zone (`users.timezone`), never the browser's. */
const getUserTimezone = (): string => userTimezone.value;

/**
 * Now as a wall clock in the user's zone, parsed like `localized(value)`, so a
 * local datetime compares against it in the same zone.
 */
const userNow = () =>
    localized(dayjs().tz(getUserTimezone()).format('YYYY-MM-DDTHH:mm:ss'));

/** Resolve scheduled local datetime for platform previews, else now. */
const resolvePreviewPostedAt = (postedAt?: string | null) => {
    if (postedAt) {
        const parsed = localized(postedAt);
        if (parsed.isValid()) {
            return parsed;
        }
    }

    return userNow();
};

export default {
    timeToken,

    /**
     * A wall-clock "HH:mm" (a posting slot, a picked time) in the user's format.
     */
    formatClockTime(time: string): string {
        return localized(`2000-01-01T${time}`).format(timeToken());
    },

    /**
     * The label of an hour option ("00"–"23") in a time picker: "09" on a
     * 24-hour clock, "9 AM" on a 12-hour one.
     */
    formatHourOption(hour: string): string {
        return timeFormat.value === '12h'
            ? localized(`2000-01-01T${hour}:00`).format('h A')
            : hour;
    },

    /**
     * The hour part of a split hour/minute picker: "09" or "9". Pair it with
     * `formatMeridiem` after the minutes on a 12-hour clock.
     */
    formatHourPart(hour: string): string {
        return timeFormat.value === '12h'
            ? localized(`2000-01-01T${hour}:00`).format('h')
            : hour;
    },

    /** "AM" / "PM" for an hour on a 12-hour clock, empty on a 24-hour one. */
    formatMeridiem(hour: string): string {
        return timeFormat.value === '12h'
            ? localized(`2000-01-01T${hour}:00`).format('A')
            : '';
    },

    formatDate(date: string | null | undefined) {
        if (!date) return '-';
        return dayjs.utc(date).tz(getUserTimezone()).format('LL');
    },

    /**
     * Format a calendar date (expiry, due date, etc.) without shifting the day
     * across timezones. Use for values stored as UTC midnight that represent a
     * chosen calendar day rather than an exact instant.
     */
    formatDateOnly(date: string | null | undefined) {
        if (!date) {
            return '-';
        }

        return dayjs.utc(date).format('LL');
    },

    formatDateTime(date: string | null | undefined) {
        if (!date) {
            return '—';
        }

        return dayjs
            .utc(date)
            .tz(getUserTimezone())
            .locale(activeLocale.value.toLowerCase())
            .format(`LL ${timeToken()}`);
    },

    formatDateShort(date: string | null | undefined) {
        if (!date) return '—';
        return dayjs
            .utc(date)
            .tz(getUserTimezone())
            .locale(activeLocale.value.toLowerCase())
            .format('D MMM');
    },

    /**
     * Format a local (already timezone-converted) datetime string.
     * Use for datetime-local values and other non-UTC inputs.
     */
    formatLocalDateTime(date: string | null | undefined) {
        if (!date) {
            return '—';
        }

        return dayjs(date).format(`ll ${timeToken()}`);
    },

    /**
     * A local datetime as short month + day + clock time ("Oct 1, 00:47"), with
     * the year only when it is not the current one.
     */
    formatLocalMonthDayTime(value: string): string {
        const instant = localized(value);
        const day = new Intl.DateTimeFormat(activeLocale.value, {
            month: 'short',
            day: 'numeric',
            ...(instant.year() === userNow().year() ? {} : { year: 'numeric' }),
        }).format(instant.toDate());

        return `${day}, ${instant.format(timeToken())}`;
    },

    /**
     * Format a local date-only value (YYYY-MM-DD or Date) with the active locale.
     */
    formatLocalDate(date: string | Date | null | undefined) {
        if (!date) {
            return '—';
        }

        return localized(date).format('LL');
    },

    /**
     * Short day + month for chart axes (day-first so locales keep natural order).
     */
    formatMonthDay(date: string | Date) {
        return localized(date).format('D MMM');
    },

    formatDayMonthYear(date: string | Date) {
        return localized(date).format('D MMM YYYY');
    },

    /**
     * Short month + day + year for chart tooltips (locale-aware via L).
     */
    formatMonthDayYear(date: string | Date) {
        return localized(date).format('L');
    },

    formatPreviewDate(postedAt?: string | null) {
        return resolvePreviewPostedAt(postedAt).format('ll');
    },

    /**
     * @param justNowLabel Localized fallback when no schedule is set (e.g. common.just_now).
     */
    formatPreviewPostedAt(postedAt?: string | null, justNowLabel?: string) {
        if (!postedAt && justNowLabel) {
            return justNowLabel;
        }

        return resolvePreviewPostedAt(postedAt).from(userNow());
    },

    /**
     * @param todayLabel Localized same-day prefix (e.g. common.date_range_picker.today).
     */
    formatDiscordPreview(postedAt?: string | null, todayLabel?: string) {
        const instant = resolvePreviewPostedAt(postedAt);

        if (instant.isSame(userNow(), 'day')) {
            return todayLabel
                ? `${todayLabel} · ${instant.format(timeToken())}`
                : instant.format(timeToken());
        }

        return instant.format(`ll ${timeToken()}`);
    },

    formatTime(date: string | null | undefined) {
        if (!date) return '-';
        return dayjs.utc(date).tz(getUserTimezone()).format(timeToken());
    },

    /**
     * Local time (e.g. "4:21 PM") of a UTC instant in an explicit time zone.
     */
    formatTimeInTimezone(date: string, timezone: string) {
        return dayjs
            .utc(date)
            .tz(timezone)
            .locale(activeLocale.value.toLowerCase())
            .format(timeToken());
    },

    /**
     * Date and time (e.g. "October 1, 2026 4:21 PM") of a UTC instant in an
     * explicit time zone.
     */
    formatDateTimeInTimezone(date: string, timezone: string) {
        return dayjs
            .utc(date)
            .tz(timezone)
            .locale(activeLocale.value.toLowerCase())
            .format(`LL ${timeToken()}`);
    },

    formatDateTimeForApi(date: string) {
        // Convert from user timezone to UTC for API
        return dayjs
            .tz(date, getUserTimezone())
            .utc()
            .format('YYYY-MM-DD HH:mm:ss');
    },

    formatDateTimeForDatePicker(date: string) {
        // Convert from UTC to user timezone for display
        if (!date) return dayjs().tz(getUserTimezone());
        return dayjs.utc(date).tz(getUserTimezone());
    },

    diffForHumans(date: string) {
        return dayjs().to(dayjs.utc(date));
    },

    formatTimelineDate(dateStr: string) {
        const d = dayjs(dateStr);
        return {
            day: d.date(),
            month: d.format('MMM'),
            year: d.year(),
        };
    },

    formatDuration(duration: string) {
        // Duration comes in format "HH:mm:ss"
        const [hours, minutes] = duration.split(':');
        const h = parseInt(hours, 10);
        const m = parseInt(minutes, 10);

        if (h > 0) {
            return `${h}h ${m}min`;
        }
        return `${m}min`;
    },

    formatMedicalRecordDuration(startAt: string, duration: string | null) {
        const startTime = this.formatTime(startAt);

        if (!duration) {
            return startTime;
        }

        const formattedDuration = this.formatDuration(duration);
        return `${startTime} (${formattedDuration})`;
    },

    /**
     * Converte horário de appointment de UTC para timezone do usuário
     * Específico para agenda onde date e time vêm separados do banco
     * @param date - Data no formato YYYY-MM-DD
     * @param time - Horário no formato HH:mm:ss
     * @returns Horário formatado no timezone do usuário (HH:mm)
     */
    convertAppointmentTimeToUserTimezone(date: string, time: string): string {
        return dayjs
            .utc(`${date} ${time}`)
            .tz(getUserTimezone())
            .format('HH:mm');
    },

    /**
     * Converte horário de appointment de UTC para timezone do usuário e retorna objeto dayjs
     * Útil para cálculos de posicionamento na agenda
     * @param date - Data no formato YYYY-MM-DD
     * @param time - Horário no formato HH:mm:ss
     * @returns Objeto dayjs no timezone do usuário
     */
    getAppointmentDateTimeInUserTimezone(date: string, time: string) {
        return dayjs.utc(`${date} ${time}`).tz(getUserTimezone());
    },

    /**
     * Formata o nome do mês e ano
     * @param month - Número do mês (1-12)
     * @param year - Ano (ex: 2025)
     * @returns String formatada (ex: "Fev/2025")
     */
    formatMonthYear(month: number, year: number): string {
        return localized(new Date(year, month - 1, 1)).format('MMM YYYY');
    },

    formatShortMonth(month: number): string {
        return localized(new Date(2026, month - 1, 1)).format('MMM');
    },

    formatAge(birthDate: string): string {
        return dayjs().from(dayjs(birthDate), true);
    },

    /**
     * Formata tempo decorrido em segundos para formato de stopwatch HH:MM:SS
     * @param seconds - Tempo decorrido em segundos
     * @returns String formatada (ex: "02:30:45", "00:05:12")
     */
    formatStopwatch(seconds: number): string {
        return dayjs.duration(seconds, 'seconds').format('HH:mm:ss');
    },

    /**
     * Calcula tempo decorrido entre uma data UTC e o momento atual no timezone do usuário
     * @param startDateTime - Data/hora de início em UTC (ISO string)
     * @returns Tempo decorrido em segundos
     */
    getElapsedSeconds(startDateTime: string): number {
        const startTime = dayjs.utc(startDateTime).tz(getUserTimezone());
        const now = dayjs().tz(getUserTimezone());
        return now.diff(startTime, 'seconds');
    },

    /**
     * Formata a data de build da aplicação no timezone do usuário
     * @param date - Data/hora em ISO string (UTC)
     * @returns String formatada (ex: "31/12/2025 14:25")
     */
    formatBuildDate(date: string): string {
        return dayjs.utc(date).tz(getUserTimezone()).format(`L ${timeToken()}`);
    },

    /** The signed-in user's time zone (`users.timezone`), never the browser's. */
    getUserTimezone,

    /**
     * Formata uma data para o formato YYYY-MM-DD (usado em DatePicker)
     * Evita problemas de timezone ao não criar objeto Date
     * @param date - Data no formato YYYY-MM-DD ou ISO string
     * @returns Data no formato YYYY-MM-DD
     */
    formatDateForInput(date: string): string {
        return dayjs(date).format('YYYY-MM-DD');
    },

    /**
     * Converte uma data UTC para o formato esperado por inputs HTML `datetime-local`
     * (YYYY-MM-DDTHH:mm:00) no timezone do usuário.
     * @param date - Data em UTC (ISO string) ou nulo
     * @returns String no formato YYYY-MM-DDTHH:mm:00 ou string vazia quando não houver data
     */
    formatUtcForDateTimeLocalInput(date: string | null | undefined): string {
        if (!date) return '';
        return dayjs
            .utc(date)
            .tz(getUserTimezone())
            .format('YYYY-MM-DDTHH:mm:00');
    },

    /**
     * The "YYYY-MM-DDTHH:mm" wall clock of a UTC instant in a zone.
     */
    utcToWallClock(value: string, timezone: string): string {
        return dayjs.utc(value).tz(timezone).format('YYYY-MM-DDTHH:mm');
    },

    /**
     * The next full hour in a zone, as a "YYYY-MM-DDTHH:mm" wall clock.
     */
    nextFullHour(timezone: string): string {
        return dayjs()
            .tz(timezone)
            .add(1, 'hour')
            .startOf('hour')
            .format('YYYY-MM-DDTHH:mm');
    },

    /**
     * The UTC instant (ISO 8601) of a "YYYY-MM-DDTHH:mm" wall clock in a zone.
     */
    wallClockToUtc(value: string, timezone: string): string {
        return dayjs.tz(value, timezone).utc().format();
    },

    /**
     * Formata minutos para formato legível
     * @param minutes - Número de minutos
     * @returns String formatada (ex: "1h", "1h 41min", "30min")
     */
    formatMinutes(minutes: number): string {
        const duration = dayjs.duration(minutes, 'minutes');
        const h = duration.hours();
        const m = duration.minutes();

        if (h > 0 && m > 0) {
            return `${h}h ${m}min`;
        }
        if (h > 0) {
            return `${h}h`;
        }
        return `${m}min`;
    },

    /**
     * Format seconds as a clock (m:ss, or h:mm:ss past one hour).
     * For media badges and stopwatches (e.g. "5:30", "1:05:30").
     */
    formatClock(seconds: number): string {
        const d = dayjs.duration(Math.round(seconds), 'seconds');

        return d.asHours() >= 1 ? d.format('H:mm:ss') : d.format('m:ss');
    },

    /**
     * Format seconds as short words (e.g. "45s", "5min", "5min 30s").
     */
    formatDurationWords(seconds: number): string {
        const s = Math.round(seconds);
        if (s < 60) {
            return `${s}s`;
        }

        const m = Math.floor(s / 60);
        const rem = s % 60;

        return rem === 0 ? `${m}min` : `${m}min ${rem}s`;
    },

    /**
     * Format a duration in milliseconds (e.g. "—", "500ms", "1.5s", "2m 30s").
     */
    formatDurationMs(ms: number | null | undefined): string {
        if (ms == null) {
            return '—';
        }
        if (ms < 1000) {
            return `${Math.round(ms)}ms`;
        }

        const seconds = ms / 1000;
        if (seconds < 60) {
            return `${seconds.toFixed(1)}s`;
        }

        const minutes = Math.floor(seconds / 60);

        return `${minutes}m ${Math.round(seconds % 60)}s`;
    },
};
