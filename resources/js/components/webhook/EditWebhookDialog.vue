<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconCheck, IconChevronDown, IconSearch } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';

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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update } from '@/routes/app/webhooks';

import { webhookEventGroups } from './webhook-events';

interface WebhookItem {
    id: string;
    endpoint: string;
    events: string[];
}

const props = defineProps<{
    webhook: WebhookItem;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    endpoint: props.webhook.endpoint,
    events: [...props.webhook.events],
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.endpoint = props.webhook.endpoint;
        form.events = [...props.webhook.events];
        form.clearErrors();
    }
});

const triggerLabel = computed(() => {
    if (form.events.length === 0) {
        return trans('webhooks.create.events_placeholder');
    }

    return transChoice('webhooks.create.events_selected', form.events.length, {
        count: String(form.events.length),
    });
});

const submit = () => {
    form.put(update.url(props.webhook), {
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ $t('webhooks.edit.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('webhooks.edit.description') }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="edit-endpoint">{{ $t('webhooks.create.endpoint') }}</Label>
                    <Input
                        id="edit-endpoint"
                        v-model="form.endpoint"
                        data-testid="edit-webhook-endpoint"
                    />
                    <InputError :message="form.errors.endpoint" />
                </div>

                <div class="grid gap-2">
                    <Label>{{ $t('webhooks.create.events') }}</Label>
                    <Combobox v-model="form.events" multiple>
                        <ComboboxAnchor as-child>
                            <ComboboxTrigger as-child>
                                <Button
                                    variant="outline"
                                    class="w-full justify-between"
                                    type="button"
                                >
                                    {{ triggerLabel }}
                                    <IconChevronDown class="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                </Button>
                            </ComboboxTrigger>
                        </ComboboxAnchor>
                        <ComboboxList class="w-full">
                            <div class="relative">
                                <ComboboxInput :placeholder="trans('webhooks.create.search_events')" />
                                <span class="absolute inset-y-0 start-0 flex items-center justify-center px-3">
                                    <IconSearch class="size-4 text-muted-foreground" />
                                </span>
                            </div>
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
                                >
                                    <span class="min-w-0 flex-1 truncate">{{ event }}</span>
                                    <ComboboxItemIndicator>
                                        <IconCheck class="ml-auto h-4 w-4" />
                                    </ComboboxItemIndicator>
                                </ComboboxItem>
                            </ComboboxGroup>
                        </ComboboxList>
                    </Combobox>
                    <InputError :message="form.errors.events" />
                </div>

                <DialogFooter>
                    <Button
                        type="submit"
                        data-testid="edit-webhook-submit"
                        :disabled="form.processing || form.events.length === 0"
                    >
                        {{ $t('webhooks.edit.submit') }}
                    </Button>
                    <Button
                        variant="secondary"
                        type="button"
                        data-testid="cancel-edit-webhook"
                        @click="open = false"
                    >
                        {{ $t('webhooks.edit.cancel') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
