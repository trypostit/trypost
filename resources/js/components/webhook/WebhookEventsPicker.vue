<script setup lang="ts">
import { computed } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

import {
    allWebhookEvents,
    webhookEventDescriptionKey,
    webhookEventGroups,
    webhookEventLabelKey,
} from './webhook-events';

const events = defineModel<string[]>({ required: true });

const props = defineProps<{
    idPrefix: string;
    testId: string;
    error?: string;
}>();

const allSelected = computed(() =>
    allWebhookEvents.every((event) => events.value.includes(event)),
);

const eventSlug = (event: string): string => event.replaceAll('.', '-');

const toggleEvent = (event: string): void => {
    events.value = events.value.includes(event)
        ? events.value.filter((selectedEvent) => selectedEvent !== event)
        : [...events.value, event];
};

const toggleAll = (): void => {
    events.value = allSelected.value ? [] : [...allWebhookEvents];
};
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-baseline gap-2">
                <Label :id="`${props.idPrefix}-events-label`">{{
                    $t('webhooks.create.events')
                }}</Label>
                <span
                    class="text-sm whitespace-nowrap text-muted-foreground"
                    :data-testid="`${props.testId}-count`"
                    >{{
                        $tChoice(
                            'webhooks.create.events_count_selected',
                            events.length,
                            {
                                count: String(events.length),
                                total: String(allWebhookEvents.length),
                            },
                        )
                    }}</span
                >
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="shrink-0"
                :data-testid="`${props.testId}-toggle-all`"
                @click="toggleAll"
            >
                {{
                    allSelected
                        ? $t('posts.composer.deselect_all')
                        : $t('posts.composer.select_all')
                }}
            </Button>
        </div>

        <div
            role="group"
            :aria-labelledby="`${props.idPrefix}-events-label`"
            :data-testid="props.testId"
            class="max-h-80 overflow-y-auto rounded-xl border border-border px-1.5 pb-1.5"
        >
            <div v-for="group in webhookEventGroups" :key="group.labelKey">
                <p
                    class="sticky top-0 z-10 bg-background px-2.5 pt-3 pb-1 text-xs font-medium text-muted-foreground"
                >
                    {{ $t(group.labelKey) }}
                </p>
                <label
                    v-for="event in group.events"
                    :key="event"
                    :for="`${props.idPrefix}-${eventSlug(event)}`"
                    :data-testid="`${props.testId}-${eventSlug(event)}`"
                    class="flex cursor-pointer items-start gap-3 rounded-lg px-2.5 py-2 transition-control hover:bg-accent"
                >
                    <Checkbox
                        :id="`${props.idPrefix}-${eventSlug(event)}`"
                        class="mt-0.5"
                        :data-testid="`${props.testId}-${eventSlug(event)}-checkbox`"
                        :model-value="events.includes(event)"
                        @update:model-value="toggleEvent(event)"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm leading-5 text-foreground">{{
                            $t(webhookEventLabelKey(event))
                        }}</span>
                        <span
                            class="block text-sm leading-5 text-muted-foreground"
                            :data-testid="`${props.testId}-${eventSlug(event)}-description`"
                            >{{ $t(webhookEventDescriptionKey(event)) }}</span
                        >
                    </span>
                </label>
            </div>
        </div>

        <InputError :message="props.error" />
    </div>
</template>
