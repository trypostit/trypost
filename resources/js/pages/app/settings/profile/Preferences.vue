<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    IconDeviceDesktop,
    IconMoon,
    IconSun,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import PreferencesController from '@/actions/App/Http/Controllers/App/Settings/PreferencesController';
import { updateLanguage } from '@/actions/App/Http/Controllers/App/Settings/ProfileController';
import LanguageSelect from '@/components/LanguageSelect.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import SettingsSelect, {
    type SettingsSelectOption,
} from '@/components/settings/SettingsSelect.vue';
import TimezoneSelect from '@/components/TimezoneSelect.vue';
import { Separator } from '@/components/ui/separator';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import {
    applyTheme,
    type DefaultPostAction,
    type Theme,
    type TimeFormat,
    type WeekStart,
} from '@/preferences';
import type { Language, User } from '@/types';
import type { TimezoneOption } from '@/types/posting-schedule';

defineProps<{
    timezones: TimezoneOption[];
}>();

type PreferenceField =
    | 'theme'
    | 'timezone'
    | 'time_format'
    | 'week_starts_on'
    | 'default_post_action'
    | 'locale';

const page = usePage();
const user = computed(() => page.props.auth.user as User);

const theme = ref<Theme>(user.value.theme);
const locale = ref<string>(page.props.locale as string);
const timezone = ref<string>(user.value.timezone);
const timeFormat = ref<TimeFormat>(user.value.time_format);
const weekStartsOn = ref<WeekStart>(user.value.week_starts_on);
const defaultPostAction = ref<DefaultPostAction>(
    user.value.default_post_action,
);
const savedField = ref<PreferenceField | null>(null);

const themeOptions: SettingsSelectOption[] = [
    {
        value: 'light',
        labelKey: 'settings.preferences.theme.light',
        icon: IconSun,
    },
    { value: 'dark', labelKey: 'settings.preferences.theme.dark', icon: IconMoon },
    {
        value: 'system',
        labelKey: 'settings.preferences.theme.system',
        icon: IconDeviceDesktop,
    },
];


const languages = computed<Language[]>(
    () => (page.props.languages as Language[] | undefined) ?? [],
);

const timeFormatOptions: SettingsSelectOption[] = [
    {
        value: '12h',
        labelKey: 'settings.preferences.time_format.twelve_hour',
    },
    {
        value: '24h',
        labelKey: 'settings.preferences.time_format.twenty_four_hour',
    },
];

const weekStartOptions = computed<SettingsSelectOption[]>(() =>
    (['sunday', 'monday'] as const).map((value, day) => ({
        value,
        label: dayjs()
            .locale(activeLocale.value.toLowerCase())
            .day(day)
            .format('dddd'),
    })),
);

const defaultPostActionOptions: SettingsSelectOption[] = [
    { value: 'next', labelKey: 'posts.composer.queue.next' },
    { value: 'now', labelKey: 'posts.composer.now' },
    { value: 'custom', labelKey: 'posts.composer.set_date_time' },
    { value: 'top', labelKey: 'posts.composer.queue.top' },
];

const save = (field: Exclude<PreferenceField, 'locale'>, value: string): void => {
    savedField.value = null;

    router.patch(
        PreferencesController.update.url(),
        { [field]: value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                savedField.value = field;
            },
        },
    );
};

const changeTheme = (value: string): void => {
    theme.value = value as Theme;
    applyTheme(theme.value);
    save('theme', value);
};

const changeLanguage = (value: string): void => {
    locale.value = value;
    savedField.value = null;

    router.put(
        updateLanguage.url(),
        { locale: value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                savedField.value = 'locale';
            },
        },
    );
};

const changeTimezone = (value: string): void => {
    timezone.value = value;
    save('timezone', value);
};

const changeTimeFormat = (value: string): void => {
    timeFormat.value = value as TimeFormat;
    save('time_format', value);
};

const changeWeekStart = (value: string): void => {
    weekStartsOn.value = value as WeekStart;
    save('week_starts_on', value);
};

const changeDefaultPostAction = (value: string): void => {
    defaultPostAction.value = value as DefaultPostAction;
    save('default_post_action', value);
};
</script>

<template>
    <Head :title="$t('settings.preferences.title')" />

    <SettingsLayout :title="$t('settings.preferences.title')">
        <div class="flex flex-col gap-8" data-testid="preferences-page">
            <SettingsRow
                :title="$t('settings.preferences.theme.heading')"
                :description="$t('settings.preferences.theme.description')"
            >
                <SettingsSelect
                    :model-value="theme"
                    :options="themeOptions"
                    :label="$t('settings.preferences.theme.heading')"
                    testid="preferences-theme"
                    @update:model-value="changeTheme"
                />
            </SettingsRow>

            <Separator />

            <SettingsRow
                :title="$t('settings.preferences.language.heading')"
                :description="$t('settings.preferences.language.description')"
            >
                <LanguageSelect
                    :model-value="locale"
                    :languages="languages"
                    :label="$t('settings.preferences.language.heading')"
                    testid="preferences-language"
                    @update:model-value="changeLanguage"
                />
            </SettingsRow>

            <Separator />

            <SettingsRow
                :title="$t('settings.preferences.timezone.heading')"
                :description="$t('settings.preferences.timezone.description')"
            >
                <TimezoneSelect
                    :model-value="timezone"
                    :options="timezones"
                    testid="preferences-timezone"
                    compact
                    @update:model-value="changeTimezone"
                />
            </SettingsRow>

            <Separator />

            <SettingsRow
                :title="$t('settings.preferences.time_format.heading')"
                :description="
                    $t('settings.preferences.time_format.description')
                "
            >
                <SettingsSelect
                    :model-value="timeFormat"
                    :options="timeFormatOptions"
                    :label="$t('settings.preferences.time_format.heading')"
                    testid="preferences-time-format"
                    @update:model-value="changeTimeFormat"
                />
            </SettingsRow>

            <Separator />

            <SettingsRow
                :title="$t('settings.preferences.week_start.heading')"
                :description="$t('settings.preferences.week_start.description')"
            >
                <SettingsSelect
                    :model-value="weekStartsOn"
                    :options="weekStartOptions"
                    :label="$t('settings.preferences.week_start.heading')"
                    testid="preferences-week-start"
                    @update:model-value="changeWeekStart"
                />
            </SettingsRow>

            <Separator />

            <SettingsRow
                :title="$t('settings.preferences.default_post_action.heading')"
                :description="
                    $t('settings.preferences.default_post_action.description')
                "
            >
                <SettingsSelect
                    :model-value="defaultPostAction"
                    :options="defaultPostActionOptions"
                    :label="
                        $t('settings.preferences.default_post_action.heading')
                    "
                    testid="preferences-default-post-action"
                    @update:model-value="changeDefaultPostAction"
                />
            </SettingsRow>

            <span
                v-if="savedField"
                :data-testid="`preferences-saved-${savedField}`"
                class="sr-only"
            />
        </div>
    </SettingsLayout>
</template>
