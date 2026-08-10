import dayjs from '@/dayjs';

/**
 * Obtém o timezone do usuário
 * Tenta pegar do Inertia page props primeiro, senão usa o timezone do browser
 */
function getUserTimezone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
}

/** Resolve scheduled local datetime for platform previews, else now. */
const resolvePreviewPostedAt = (postedAt?: string | null) => {
    if (postedAt) {
        const parsed = dayjs(postedAt);
        if (parsed.isValid()) {
            return parsed;
        }
    }

    return dayjs();
};

/** X / Bluesky style: `4:21 PM · Aug 5, 2026` (locale-aware). */
const formatAbsolutePreviewPostedAt = (postedAt?: string | null) => {
    const instant = resolvePreviewPostedAt(postedAt);

    return `${instant.format('LT')} · ${instant.format('ll')}`;
};

export default {
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

        return dayjs.utc(date).tz(getUserTimezone()).format('LLL');
    },

    /**
     * Format a local (already timezone-converted) datetime string.
     * Use for datetime-local values and other non-UTC inputs.
     */
    formatLocalDateTime(date: string | null | undefined) {
        if (!date) {
            return '—';
        }

        return dayjs(date).format('lll');
    },

    /**
     * Format a local date-only value (YYYY-MM-DD or Date) with the active locale.
     */
    formatLocalDate(date: string | Date | null | undefined) {
        if (!date) {
            return '—';
        }

        return dayjs(date).format('LL');
    },

    /**
     * Short day + month for chart axes (day-first so locales keep natural order).
     */
    formatMonthDay(date: string | Date) {
        return dayjs(date).format('D MMM');
    },

    /**
     * Short month + day + year for chart tooltips (locale-aware via L).
     */
    formatMonthDayYear(date: string | Date) {
        return dayjs(date).format('L');
    },

    formatXPreview(postedAt?: string | null) {
        return formatAbsolutePreviewPostedAt(postedAt);
    },

    formatBlueskyPreview(postedAt?: string | null) {
        return formatAbsolutePreviewPostedAt(postedAt);
    },

    formatMastodonPreview(postedAt?: string | null) {
        return resolvePreviewPostedAt(postedAt).format('lll');
    },

    /**
     * @param justNowLabel Localized fallback when no schedule is set (e.g. common.just_now).
     */
    formatFacebookPreview(postedAt?: string | null, justNowLabel?: string) {
        if (! postedAt && justNowLabel) {
            return justNowLabel;
        }

        return resolvePreviewPostedAt(postedAt).fromNow();
    },

    /**
     * @param todayLabel Localized same-day prefix (e.g. common.date_range_picker.today).
     */
    formatDiscordPreview(postedAt?: string | null, todayLabel?: string) {
        const instant = resolvePreviewPostedAt(postedAt);

        if (instant.isSame(dayjs(), 'day')) {
            return todayLabel
                ? `${todayLabel} · ${instant.format('LT')}`
                : instant.format('LT');
        }

        return instant.format('lll');
    },

    formatTime(date: string | null | undefined) {
        if (!date) return '-';
        return dayjs.utc(date).tz(getUserTimezone()).format('HH:mm');
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
        const localDate = dayjs
            .utc(date)
            .tz(getUserTimezone())
            .format('YYYY-MM-DD HH:mm:ss');
        return dayjs().to(dayjs(localDate));
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
        return dayjs(new Date(year, month - 1, 1)).format('MMM YYYY');
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
        return dayjs.utc(date).tz(getUserTimezone()).format('L LT');
    },

    /**
     * Obtém o timezone do usuário
     * Tenta pegar do Inertia page props primeiro, senão usa o timezone do browser
     * @returns Timezone do usuário
     */
    getUserTimezone,

    /**
     * Abreviação do timezone do usuário para exibição (ex.: "GMT-3")
     * @returns Abreviação do timezone
     */
    getTimezoneAbbr(): string {
        return dayjs().format('z');
    },

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
        return dayjs.utc(date).tz(getUserTimezone()).format('YYYY-MM-DDTHH:mm:00');
    },

    /**
     * Inverso de `formatUtcForDateTimeLocalInput`: recebe uma string vinda de um
     * input `datetime-local` (no timezone do usuário) e devolve uma ISO 8601 em
     * UTC pronta para enviar à API. Retorna `null` quando não houver data.
     * @param date - String do input (YYYY-MM-DDTHH:mm[:ss]) ou nulo
     * @returns ISO 8601 em UTC (ex.: 2026-05-19T19:40:00Z) ou null
     */
    formatLocalDateTimeForApi(date: string | null | undefined): string | null {
        if (!date) return null;
        return dayjs.tz(date, getUserTimezone()).utc().format();
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
