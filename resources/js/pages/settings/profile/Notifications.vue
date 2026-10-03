<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

import NotificationPreferenceController from '@/actions/App/Http/Controllers/App/Settings/NotificationPreferenceController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import SettingsLayout from '@/layouts/SettingsLayout.vue';

type PreferenceField =
    | 'post_published'
    | 'post_failed'
    | 'account_disconnected'
    | 'post_note_added'
    | 'collaboration';

type Preferences = Record<PreferenceField, boolean>;

const props = defineProps<{
    preferences: Preferences;
}>();

const fields: PreferenceField[] = [
    'post_published',
    'post_failed',
    'account_disconnected',
    'post_note_added',
    'collaboration',
];

const values = ref<Preferences>(
    Object.fromEntries(
        fields.map((field) => [field, Boolean(props.preferences[field])]),
    ) as Preferences,
);
const savedField = ref<PreferenceField | null>(null);
const http = useHttp<Partial<Preferences>>({});
let queue: Promise<void> = Promise.resolve();

/**
 * Each switch saves on its own. Saves run one after another so a quick second
 * toggle never cancels the first; a failed save puts the switch back.
 */
const toggle = (field: PreferenceField, value: boolean): void => {
    const previous = values.value[field];
    values.value = { ...values.value, [field]: value };
    savedField.value = null;

    queue = queue.then(async () => {
        try {
            await http
                .transform(() => ({ [field]: value }))
                .patch(NotificationPreferenceController.update.url());
            savedField.value = field;
        } catch {
            values.value = { ...values.value, [field]: previous };
            toast.error(trans('settings.notifications.save_failed'));
        }
    });
};
</script>

<template>
    <Head :title="$t('settings.notifications.title')" />

    <SettingsLayout :title="$t('settings.notifications.title')">
        <SettingsSection
            :title="$t('settings.notifications.heading')"
            :description="$t('settings.notifications.description')"
        >
            <div class="flex flex-col gap-6" data-testid="notifications-page">
                <div
                    v-for="field in fields"
                    :key="field"
                    class="flex items-start justify-between gap-4"
                >
                    <div class="flex max-w-[440px] min-w-0 flex-col gap-2">
                        <Label
                            :for="field"
                            class="text-sm leading-tight font-emphasis"
                        >
                            {{ $t(`settings.notifications.${field}`) }}
                        </Label>
                        <p class="text-sm text-muted-foreground">
                            {{ $t(`settings.notifications.${field}_description`) }}
                        </p>
                    </div>
                    <Switch
                        :id="field"
                        :model-value="values[field]"
                        :data-testid="`notifications-${field}`"
                        class="shrink-0"
                        @update:model-value="toggle(field, Boolean($event))"
                    />
                </div>
            </div>

            <span
                v-if="savedField"
                :data-testid="`notifications-saved-${savedField}`"
                class="sr-only"
            />
        </SettingsSection>
    </SettingsLayout>
</template>
