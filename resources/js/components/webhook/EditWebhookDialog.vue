<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
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
                <WebhookFormFields
                    v-model:endpoint="form.endpoint"
                    v-model:events="form.events"
                    endpoint-id="edit-endpoint"
                    endpoint-test-id="edit-webhook-endpoint"
                    events-test-id="edit-webhook-events"
                    :errors="form.errors"
                />

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
