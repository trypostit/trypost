<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconPencil,
    IconRefresh,
    IconSend,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import EditWebhookDialog from '@/components/webhook/EditWebhookDialog.vue';
import RotateSecretDialog from '@/components/webhook/RotateSecretDialog.vue';
import {
    webhookEventDescriptionKey,
    webhookEventLabelKey,
} from '@/components/webhook/webhook-events';
import WebhookActionsMenu from '@/components/webhook/WebhookActionsMenu.vue';
import WebhookLogViewer from '@/components/webhook/WebhookLogViewer.vue';
import WebhookSigningSecret from '@/components/webhook/WebhookSigningSecret.vue';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { destroy, index, sendTest } from '@/routes/app/webhooks';
import {
    webhookEndpointParts,
    type WebhookLog,
    type WebhookWithSecret,
} from '@/types/webhook';
import { webhookStatusDot } from '@/types/webhook-status';

const props = defineProps<{
    webhook: WebhookWithSecret;
    logs: { data: WebhookLog[] };
}>();

const endpoint = computed(() => webhookEndpointParts(props.webhook.endpoint));

const editDialogOpen = ref(false);

const openEditDialog = (): void => {
    editDialogOpen.value = true;
};

const rotateSecretDialogOpen = ref(false);

const openRotateSecretDialog = (): void => {
    rotateSecretDialogOpen.value = true;
};

const sendingTest = ref(false);

const sendTestEvent = (): void => {
    sendingTest.value = true;

    router.post(
        sendTest.url(props.webhook),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                sendingTest.value = false;
            },
        },
    );
};

const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);

const openDelete = (): void => {
    confirmDeleteModal.value?.open({
        url: destroy.url(props.webhook),
    });
};
</script>

<template>
    <Head :title="$t('webhooks.title')" />

    <SettingsLayout full-width>
        <div
            class="flex flex-1 flex-col lg:min-h-0 lg:overflow-hidden"
            data-testid="webhook-show-page"
        >
            <header class="flex flex-col gap-6 px-4 pt-6 pb-6 md:px-8 md:pt-8">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <Link
                            :href="index.url()"
                            class="mb-3 inline-flex items-center gap-1.5 rounded-md text-sm text-muted-foreground transition-control hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                            data-testid="webhook-back"
                        >
                            <IconArrowLeft class="size-4 rtl:rotate-180" />
                            {{ $t('webhooks.title') }}
                        </Link>
                        <h1
                            class="truncate font-heading text-xl leading-tight font-medium text-foreground"
                            :title="webhook.endpoint"
                            data-testid="webhook-endpoint"
                        >
                            <span class="font-emphasis">{{ endpoint.host }}</span
                            ><span class="text-muted-foreground">{{
                                endpoint.path
                            }}</span>
                        </h1>
                        <p
                            class="mt-1 flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground"
                            data-testid="webhook-meta"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    :class="[
                                        'size-2 shrink-0 rounded-full',
                                        webhookStatusDot(webhook.status),
                                    ]"
                                    data-testid="webhook-status-dot"
                                />
                                <span data-testid="webhook-status">{{
                                    $t(`webhooks.status.${webhook.status}`)
                                }}</span>
                            </span>
                            <span aria-hidden="true">·</span>
                            <span>{{
                                $tChoice(
                                    'webhooks.events_count',
                                    webhook.events.length,
                                    { count: String(webhook.events.length) },
                                )
                            }}</span>
                            <span aria-hidden="true">·</span>
                            <span data-testid="webhook-last-sent">
                                {{ $t('webhooks.table.last_sent') }}:
                                {{
                                    webhook.last_sent_at
                                        ? date.diffForHumans(webhook.last_sent_at)
                                        : $t('webhooks.never')
                                }}
                            </span>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button
                            class="max-sm:w-8 max-sm:px-0"
                            :loading="sendingTest"
                            :aria-label="$t('webhooks.actions.send_test')"
                            data-testid="send-test-webhook"
                            @click="sendTestEvent"
                        >
                            <IconSend class="size-4" />
                            <span class="sr-only sm:not-sr-only">{{
                                $t('webhooks.actions.send_test')
                            }}</span>
                        </Button>
                        <WebhookActionsMenu
                            :webhook="webhook"
                            @edit="openEditDialog"
                            @rotate="openRotateSecretDialog"
                            @delete="openDelete"
                        />
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <section
                        class="flex min-w-0 flex-col gap-3 rounded-xl border border-border bg-card p-4"
                        data-testid="webhook-events"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h2
                                class="text-sm leading-5 font-emphasis text-foreground"
                            >
                                {{ $t('webhooks.create.events') }}
                            </h2>
                            <Button
                                variant="ghost"
                                size="sm"
                                data-testid="edit-webhook-events"
                                @click="openEditDialog"
                            >
                                <IconPencil class="size-4" />
                                {{ $t('webhooks.show.edit') }}
                            </Button>
                        </div>
                        <ul class="flex flex-wrap gap-1.5">
                            <li
                                v-for="event in webhook.events"
                                :key="event"
                                class="rounded-md bg-muted px-2 py-1 text-sm text-foreground"
                                :title="$t(webhookEventDescriptionKey(event))"
                                :data-testid="`webhook-event-${event}`"
                            >
                                {{ $t(webhookEventLabelKey(event)) }}
                            </li>
                        </ul>
                    </section>

                    <section
                        class="flex min-w-0 flex-col gap-3 rounded-xl border border-border bg-card p-4"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h2
                                class="text-sm leading-5 font-emphasis text-foreground"
                            >
                                {{ $t('webhooks.show.signing_secret') }}
                            </h2>
                            <Button
                                variant="ghost"
                                size="sm"
                                data-testid="rotate-secret-button"
                                @click="openRotateSecretDialog"
                            >
                                <IconRefresh class="size-4" />
                                {{ $t('webhooks.rotate.submit') }}
                            </Button>
                        </div>
                        <WebhookSigningSecret :secret="webhook.signing_secret" />
                    </section>
                </div>
            </header>

            <WebhookLogViewer
                :key="webhook.id"
                :webhook-id="webhook.id"
                :logs="logs.data ?? []"
                :sending-test="sendingTest"
                @send-test="sendTestEvent"
            />
        </div>
    </SettingsLayout>

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
</template>
