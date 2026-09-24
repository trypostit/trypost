<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { store } from '@/routes/app/webhooks';

import WebhookFormFields from './WebhookFormFields.vue';

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

const submit = () => {
    form.post(store.url(), {
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            data-testid="create-webhook-sheet"
            class="w-full gap-0 sm:max-w-lg"
        >
            <SheetHeader class="border-b px-6 py-5 pr-12">
                <SheetTitle>{{ $t('webhooks.create.title') }}</SheetTitle>
                <SheetDescription>
                    {{ $t('webhooks.create.description') }}
                </SheetDescription>
            </SheetHeader>
            <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-6">
                    <WebhookFormFields
                        v-model:endpoint="form.endpoint"
                        v-model:events="form.events"
                        endpoint-id="create-endpoint"
                        endpoint-test-id="create-webhook-endpoint"
                        events-test-id="create-webhook-events"
                        :errors="form.errors"
                    />
                </div>

                <SheetFooter class="flex-row justify-end border-t px-6 py-4">
                    <Button
                        variant="secondary"
                        type="button"
                        data-testid="cancel-create-webhook"
                        @click="open = false"
                    >
                        {{ $t('webhooks.create.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        data-testid="create-webhook-submit"
                        :disabled="form.processing || form.events.length === 0"
                    >
                        {{ $t('webhooks.create.submit') }}
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
