<script setup lang="ts">
import { IconChevronDown, IconChevronUp } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Input } from '@/components/ui/input';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { ContentType } from '@/types/content-type';

interface Props {
    socialAccount: { display_label: string } | null;
    contentType: string;
    meta: Record<string, any>;
    disabled?: boolean;
    previewOnly?: boolean;
}

const props = withDefaults(defineProps<Props>(), { disabled: false, previewOnly: false });
const emit = defineEmits<{
    'update:contentType': [value: string];
    'update:meta': [value: Record<string, any>];
}>();

const open = ref(true);
const variants = [
    { value: ContentType.GoogleBusinessProfileStandard, label: 'Update' },
    { value: ContentType.GoogleBusinessProfileEvent, label: 'Event' },
    { value: ContentType.GoogleBusinessProfileOffer, label: 'Offer' },
    { value: ContentType.GoogleBusinessProfileAlert, label: 'Alert' },
];
const ctaTypes = [
    ['', 'No button'], ['BOOK', 'Book'], ['ORDER', 'Order'], ['SHOP', 'Shop'],
    ['LEARN_MORE', 'Learn more'], ['SIGN_UP', 'Sign up'], ['CALL', 'Call'],
];
const weekdays = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
const isEvent = computed(() => [ContentType.GoogleBusinessProfileEvent, ContentType.GoogleBusinessProfileOffer].includes(props.contentType as any));
const isOffer = computed(() => props.contentType === ContentType.GoogleBusinessProfileOffer);
const isAlert = computed(() => props.contentType === ContentType.GoogleBusinessProfileAlert);

const update = (key: string, value: any) => emit('update:meta', { ...props.meta, [key]: value === '' ? null : value });
const toggleWeekday = (day: string) => {
    const current = (props.meta.recurrence_days_of_week ?? []) as string[];
    update('recurrence_days_of_week', current.includes(day) ? current.filter((item) => item !== day) : [...current, day]);
};
</script>

<template>
    <div class="rounded-xl border-2 border-foreground bg-card shadow-2xs">
        <button type="button" class="flex w-full items-center justify-between gap-3 p-4 text-sm" @click="open = !open">
            <span class="flex min-w-0 items-center gap-2 font-bold">
                <img :src="getPlatformLogo('google-business-profile')" alt="" class="size-6 rounded" />
                Google Business Profile settings
                <span v-if="socialAccount" class="truncate font-medium text-foreground/60">· {{ socialAccount.display_label }}</span>
            </span>
            <IconChevronUp v-if="open" class="size-4" />
            <IconChevronDown v-else class="size-4" />
        </button>

        <div v-if="open" class="grid gap-5 border-t-2 border-foreground/10 p-4">
            <div class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">Post type</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="variant in variants"
                        :key="variant.value"
                        type="button"
                        class="rounded-full border-2 px-3 py-1 text-xs font-bold uppercase tracking-widest disabled:opacity-50"
                        :class="contentType === variant.value ? 'border-foreground bg-blue-100 dark:bg-blue-950' : 'border-foreground/30'"
                        :disabled="disabled"
                        @click="emit('update:contentType', variant.value)"
                    >{{ variant.label }}</button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="grid gap-2 text-sm font-semibold">
                    Button
                    <select class="h-10 rounded-md border-2 border-foreground/30 bg-background px-3" :value="meta.cta_action_type ?? ''" :disabled="disabled" @change="update('cta_action_type', ($event.target as HTMLSelectElement).value)">
                        <option v-for="[value, label] in ctaTypes" :key="value" :value="value">{{ label }}</option>
                    </select>
                </label>
                <label v-if="meta.cta_action_type && meta.cta_action_type !== 'CALL'" class="grid gap-2 text-sm font-semibold">
                    Button destination URL
                    <Input type="url" :model-value="meta.cta_url ?? ''" :disabled="disabled" @update:model-value="update('cta_url', $event)" />
                </label>
            </div>

            <template v-if="isEvent">
                <label class="grid gap-2 text-sm font-semibold">Event title<Input :model-value="meta.event_title ?? ''" :disabled="disabled" @update:model-value="update('event_title', $event)" /></label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-2 text-sm font-semibold">Starts<Input type="datetime-local" :model-value="meta.event_start_at ?? ''" :disabled="disabled" @update:model-value="update('event_start_at', $event)" /></label>
                    <label class="grid gap-2 text-sm font-semibold">Ends<Input type="datetime-local" :model-value="meta.event_end_at ?? ''" :disabled="disabled" @update:model-value="update('event_end_at', $event)" /></label>
                </div>
                <label class="grid gap-2 text-sm font-semibold">
                    Recurrence
                    <select class="h-10 rounded-md border-2 border-foreground/30 bg-background px-3" :value="meta.recurrence_pattern ?? ''" :disabled="disabled" @change="update('recurrence_pattern', ($event.target as HTMLSelectElement).value)">
                        <option value="">Does not repeat</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option>
                    </select>
                </label>
                <div v-if="meta.recurrence_pattern === 'weekly'" class="flex flex-wrap gap-2">
                    <button v-for="day in weekdays" :key="day" type="button" class="rounded-full border px-2 py-1 text-xs" :class="meta.recurrence_days_of_week?.includes(day) ? 'bg-blue-100 dark:bg-blue-950' : ''" :disabled="disabled" @click="toggleWeekday(day)">{{ day.slice(0, 3) }}</button>
                </div>
                <div v-if="meta.recurrence_pattern === 'monthly'" class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-2 text-sm font-semibold">Day of month<Input type="number" min="1" max="31" :model-value="meta.recurrence_day_of_month ?? ''" :disabled="disabled" @update:model-value="update('recurrence_day_of_month', $event)" /></label>
                    <label class="grid gap-2 text-sm font-semibold">Weekday occurrence<select class="h-10 rounded-md border-2 border-foreground/30 bg-background px-3" :value="meta.recurrence_day_of_week_occurrence ?? ''" :disabled="disabled" @change="update('recurrence_day_of_week_occurrence', ($event.target as HTMLSelectElement).value)"><option value="">Choose</option><option v-for="value in ['FIRST','SECOND','THIRD','FOURTH','FIFTH','LAST']" :key="value" :value="value">{{ value }}</option></select></label>
                </div>
                <label v-if="meta.recurrence_pattern" class="grid gap-2 text-sm font-semibold">Repeat until<Input type="datetime-local" :model-value="meta.recurrence_series_end_at ?? ''" :disabled="disabled" @update:model-value="update('recurrence_series_end_at', $event)" /></label>
            </template>

            <template v-if="isOffer">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-2 text-sm font-semibold">Coupon code<Input :model-value="meta.offer_coupon_code ?? ''" :disabled="disabled" @update:model-value="update('offer_coupon_code', $event)" /></label>
                    <label class="grid gap-2 text-sm font-semibold">Redemption URL<Input type="url" :model-value="meta.offer_redeem_url ?? ''" :disabled="disabled" @update:model-value="update('offer_redeem_url', $event)" /></label>
                </div>
                <label class="grid gap-2 text-sm font-semibold">Terms and conditions<textarea class="min-h-24 rounded-md border-2 border-foreground/30 bg-background p-3" :value="meta.offer_terms ?? ''" :disabled="disabled" @input="update('offer_terms', ($event.target as HTMLTextAreaElement).value)" /></label>
            </template>

            <label v-if="isAlert" class="grid gap-2 text-sm font-semibold">Alert type<select class="h-10 rounded-md border-2 border-foreground/30 bg-background px-3" :value="meta.alert_type ?? ''" :disabled="disabled" @change="update('alert_type', ($event.target as HTMLSelectElement).value)"><option value="">Choose</option><option value="COVID_19">COVID-19</option></select></label>
        </div>
    </div>
</template>
