<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import Breadcrumbs from '@/components/Breadcrumbs.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Badge } from '@/components/ui/badge';
import EditWebhookDialog from '@/components/webhook/EditWebhookDialog.vue';
import RotateSecretDialog from '@/components/webhook/RotateSecretDialog.vue';
import WebhookActionsMenu from '@/components/webhook/WebhookActionsMenu.vue';
import WebhookLogViewer from '@/components/webhook/WebhookLogViewer.vue';
import WebhookOverview from '@/components/webhook/WebhookOverview.vue';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, index } from '@/routes/app/webhooks';
import type { BreadcrumbItem } from '@/types';
import type { WebhookLog, WebhookWithSecret } from '@/types/webhook';
import { webhookStatusVariant } from '@/types/webhook-status';

const props = defineProps<{
    webhook: WebhookWithSecret;
    logs: { data: WebhookLog[] };
}>();

const endpointLabel = computed((): string => {
    try {
        const url = new URL(props.webhook.endpoint);

        return url.host + (url.pathname === '/' ? '' : url.pathname);
    } catch {
        return props.webhook.endpoint;
    }
});

const breadcrumbs = computed((): BreadcrumbItem[] => [
    { title: trans('webhooks.title'), href: index.url() },
    { title: endpointLabel.value },
]);

const editDialogOpen = ref(false);
const rotateSecretDialogOpen = ref(false);
const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);

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
        <template #header>
            <div class="flex min-w-0 items-center gap-3">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
                <Badge
                    :variant="webhookStatusVariant(webhook.status)"
                    class="shrink-0"
                >
                    {{ $t(`webhooks.status.${webhook.status}`) }}
                </Badge>
            </div>
        </template>

        <template #header-actions>
            <div class="flex items-center gap-2">
                <span
                    v-if="webhook.last_sent_at"
                    class="hidden truncate text-sm text-muted-foreground md:block"
                    data-testid="webhook-last-sent"
                >
                    {{
                        $t('webhooks.show.last_sent', {
                            time: date.diffForHumans(webhook.last_sent_at),
                        })
                    }}
                </span>
                <div
                    v-if="webhook.last_sent_at"
                    class="hidden h-6 w-px bg-border md:block"
                />
                <WebhookActionsMenu
                    :webhook="webhook"
                    @edit="editDialogOpen = true"
                    @rotate="rotateSecretDialogOpen = true"
                    @delete="openDelete"
                />
            </div>
        </template>

        <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
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
    </AppLayout>
</template>
