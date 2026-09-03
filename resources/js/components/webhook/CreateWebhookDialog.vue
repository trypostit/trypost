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
import { store } from '@/routes/app/webhooks';

import { webhookEventGroups } from './webhook-events';

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    endpoint: '',
    events: [] as string[],
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
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
    form.post(store.url(), {
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
                <DialogTitle>{{ $t('webhooks.create.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('webhooks.create.description') }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="create-endpoint">{{ $t('webhooks.create.endpoint') }}</Label>
                    <Input
                        id="create-endpoint"
                        v-model="form.endpoint"
                        data-testid="create-webhook-endpoint"
                        :placeholder="trans('webhooks.create.endpoint_placeholder')"
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
                                    data-testid="create-webhook-events"
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
                        data-testid="create-webhook-submit"
                        :disabled="form.processing || form.events.length === 0"
                    >
                        {{ $t('webhooks.create.submit') }}
                    </Button>
                    <Button
                        variant="secondary"
                        type="button"
                        data-testid="cancel-create-webhook"
                        @click="open = false"
                    >
                        {{ $t('webhooks.create.cancel') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
