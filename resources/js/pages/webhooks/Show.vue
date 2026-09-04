<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EditWebhookDialog from '@/components/webhook/EditWebhookDialog.vue';
import RotateSecretDialog from '@/components/webhook/RotateSecretDialog.vue';
import WebhookLogViewer from '@/components/webhook/WebhookLogViewer.vue';
import WebhookOverview from '@/components/webhook/WebhookOverview.vue';
import WebhookShowHeader from '@/components/webhook/WebhookShowHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy } from '@/routes/app/webhooks';
import type { WebhookLog, WebhookWithSecret } from '@/types/webhook';

const props = defineProps<{
    webhook: WebhookWithSecret;
    logs: { data: WebhookLog[] };
}>();

const editDialogOpen = ref(false);
const rotateSecretDialogOpen = ref(false);
const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const openDelete = () => {
    confirmDeleteModal.value?.open({
        url: destroy.url(props.webhook),
        confirmText: trans('common.confirm_modal.delete_keyword'),
    });
};
</script>

<template>
    <Head :title="$t('webhooks.title')" />

    <AppLayout full-width>
        <div class="flex min-h-0 flex-1 flex-col bg-background">
            <WebhookShowHeader
                :webhook="webhook"
                @edit="editDialogOpen = true"
                @rotate="rotateSecretDialogOpen = true"
                @delete="openDelete"
            />

            <div
                class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:p-6 lg:overflow-hidden"
            >
                <WebhookOverview :webhook="webhook" />

                <WebhookLogViewer
                    :key="webhook.id"
                    :webhook-id="webhook.id"
                    :logs="logs.data ?? []"
                />
            </div>

            <EditWebhookDialog v-model:open="editDialogOpen" :webhook="webhook" />
            <RotateSecretDialog
                v-model:open="rotateSecretDialogOpen"
                :webhook-id="webhook.id"
            />
            <ConfirmDeleteModal
                ref="confirmDeleteModal"
                :title="$t('webhooks.delete.title')"
                :description="$t('webhooks.delete.description')"
                :action="$t('webhooks.delete.confirm')"
                :cancel="$t('webhooks.delete.cancel')"
            />
        </div>
    </AppLayout>
</template>
