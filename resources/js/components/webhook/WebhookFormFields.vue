<script setup lang="ts">
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label :for="props.endpointId">{{ $t('webhooks.create.endpoint') }}</Label>
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
            <Combobox v-model="events" multiple>
                <ComboboxAnchor as-child>
                    <ComboboxTrigger as-child>
                        <Button
                            variant="outline"
                            class="w-full justify-between"
                            :data-testid="props.eventsTestId"
                            type="button"
                        >
                            {{ triggerLabel }}
                            <IconChevronDown class="ml-2 h-4 w-4 shrink-0 opacity-50" />
                        </Button>
                    </ComboboxTrigger>
                </ComboboxAnchor>
                <ComboboxList class="w-full">
                    <ComboboxInput :placeholder="trans('webhooks.create.search_events')" />
                    <ComboboxEmpty>{{ $t('webhooks.create.no_events') }}</ComboboxEmpty>
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
                            <span class="min-w-0 flex-1 truncate">{{ webhookEventLabel(event) }}</span>
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
