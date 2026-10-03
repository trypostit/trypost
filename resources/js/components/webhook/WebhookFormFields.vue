<script setup lang="ts">
import { IconWorld } from '@tabler/icons-vue';

import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import WebhookEventsPicker from './WebhookEventsPicker.vue';

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
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="grid gap-2">
            <Label :for="props.endpointId">{{
                $t('webhooks.create.endpoint')
            }}</Label>
            <div class="relative">
                <IconWorld
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    :id="props.endpointId"
                    v-model="endpoint"
                    inputmode="url"
                    autocomplete="off"
                    class="pl-8"
                    :aria-invalid="props.errors.endpoint ? true : undefined"
                    :aria-describedby="`${props.endpointId}-help`"
                    :data-testid="props.endpointTestId"
                    :placeholder="$t('webhooks.create.endpoint_placeholder')"
                />
            </div>
            <p
                :id="`${props.endpointId}-help`"
                class="text-sm text-muted-foreground"
                :data-testid="`${props.endpointTestId}-help`"
            >
                {{ $t('webhooks.create.endpoint_help') }}
            </p>
            <InputError :message="props.errors.endpoint" />
        </div>

        <WebhookEventsPicker
            v-model="events"
            :id-prefix="props.endpointId"
            :test-id="props.eventsTestId"
            :error="props.errors.events"
        />
    </div>
</template>
