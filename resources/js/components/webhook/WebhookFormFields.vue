<script setup lang="ts">
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxList,
    ComboboxTrigger,
} from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import { webhookEventGroups, webhookEventLabel } from './webhook-events';

const endpoint = defineModel<string>('endpoint', { required: true });
const events = defineModel<string[]>('events', { required: true });

const props = defineProps<{
    endpointId: string;
    endpointTestId: string;
    eventsTestId: string;
    eventsControl?: 'checkboxes' | 'combobox';
    errors: {
        endpoint?: string;
        events?: string;
    };
}>();

const triggerLabel = computed(() => {
    if (events.value.length === 0) {
        return trans('webhooks.create.events_placeholder');
    }

    return transChoice('webhooks.create.events_selected', events.value.length, {
        count: String(events.value.length),
    });
});

const updateEventSelection = (event: string, selected: boolean): void => {
    events.value = selected
        ? [...new Set([...events.value, event])]
        : events.value.filter((selectedEvent) => selectedEvent !== event);
};
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label :for="props.endpointId">{{
                $t('webhooks.create.endpoint')
            }}</Label>
            <Input
                :id="props.endpointId"
                v-model="endpoint"
                :data-testid="props.endpointTestId"
                :placeholder="trans('webhooks.create.endpoint_placeholder')"
            />
            <InputError :message="props.errors.endpoint" />
        </div>

        <div class="grid gap-2">
            <Label>{{ $t('webhooks.create.events') }}</Label>
            <div
                v-if="props.eventsControl === 'checkboxes'"
                :data-testid="props.eventsTestId"
                class="rounded-md border"
            >
                <div
                    v-for="group in webhookEventGroups"
                    :key="group.labelKey"
                    class="p-4"
                >
                    <p class="mb-3 text-sm font-medium">
                        {{ $t(group.labelKey) }}
                    </p>
                    <div class="grid gap-3">
                        <div
                            v-for="event in group.events"
                            :key="event"
                            class="flex items-center gap-3"
                        >
                            <Checkbox
                                :id="`${props.endpointId}-${event}`"
                                :data-testid="`${props.eventsTestId}-${event.replaceAll('.', '-')}`"
                                :model-value="events.includes(event)"
                                @update:model-value="
                                    (selected) =>
                                        updateEventSelection(
                                            event,
                                            selected === true,
                                        )
                                "
                            />
                            <Label
                                :for="`${props.endpointId}-${event}`"
                                class="cursor-pointer font-normal"
                            >
                                {{ webhookEventLabel(event) }}
                            </Label>
                        </div>
                    </div>
                </div>
            </div>
            <Combobox v-else v-model="events" multiple>
                <ComboboxAnchor as-child>
                    <ComboboxTrigger as-child>
                        <Button
                            variant="outline"
                            class="w-full justify-between"
                            :data-testid="props.eventsTestId"
                            type="button"
                        >
                            {{ triggerLabel }}
                            <IconChevronDown
                                class="ml-2 h-4 w-4 shrink-0 opacity-50"
                            />
                        </Button>
                    </ComboboxTrigger>
                </ComboboxAnchor>
                <ComboboxList class="w-full">
                    <ComboboxInput
                        :placeholder="trans('webhooks.create.search_events')"
                    />
                    <ComboboxEmpty>{{
                        $t('webhooks.create.no_events')
                    }}</ComboboxEmpty>
                    <ComboboxGroup
                        v-for="group in webhookEventGroups"
                        :key="group.labelKey"
                        :heading="trans(group.labelKey)"
                    >
                        <ComboboxItem
                            v-for="event in group.events"
                            :key="event"
                            :value="event"
                            :text-value="`${webhookEventLabel(event)} ${event}`"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                webhookEventLabel(event)
                            }}</span>
                            <ComboboxItemIndicator>
                                <IconCheck class="ml-auto h-4 w-4" />
                            </ComboboxItemIndicator>
                        </ComboboxItem>
                    </ComboboxGroup>
                </ComboboxList>
            </Combobox>
            <InputError :message="props.errors.events" />
        </div>
    </div>
</template>
