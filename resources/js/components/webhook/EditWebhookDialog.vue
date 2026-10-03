<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { update } from '@/routes/app/webhooks';

import WebhookFormFields from './WebhookFormFields.vue';

interface WebhookItem {
    id: string;
    endpoint: string;
    events: string[];
}

const props = defineProps<{
    webhook: WebhookItem;
}>();

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const form = useForm({
    endpoint: props.webhook.endpoint,
    events: [...props.webhook.events],
});

const canSubmit = computed(
    () => form.endpoint.trim() !== '' && form.events.length > 0,
);

watch(open, (isOpen) => {
    if (isOpen) {
        form.endpoint = props.webhook.endpoint;
        form.events = [...props.webhook.events];
        form.clearErrors();
    }
});

const submit = (): void => {
    if (!canSubmit.value || form.processing) {
        return;
    }

    form.put(update.url(props.webhook), {
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-testid="edit-webhook-dialog"
            class="gap-0 p-0 sm:max-w-lg"
        >
            <form @submit.prevent="submit">
                <div class="flex flex-col gap-1.5 px-8 pt-7 pb-5">
                    <DialogTitle class="font-sans text-base font-emphasis">
                        {{ $t('webhooks.edit.title') }}
                    </DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        {{ $t('webhooks.edit.description') }}
                    </DialogDescription>
                </div>

                <div class="px-8 pb-6">
                    <WebhookFormFields
                        v-model:endpoint="form.endpoint"
                        v-model:events="form.events"
                        endpoint-id="edit-endpoint"
                        endpoint-test-id="edit-webhook-endpoint"
                        events-test-id="edit-webhook-events"
                        :errors="form.errors"
                    />
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-border px-8 py-4"
                >
                    <Button
                        variant="ghost"
                        type="button"
                        data-testid="cancel-edit-webhook"
                        @click="closeDialog"
                    >
                        {{ $t('webhooks.edit.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        data-testid="edit-webhook-submit"
                        :loading="form.processing"
                        :disabled="!canSubmit || form.processing"
                    >
                        {{ $t('webhooks.edit.submit') }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
