<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    IconDotsVertical,
    IconEye,
    IconPlus,
    IconTrash,
    IconWebhook,
} from '@tabler/icons-vue';
import { ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import CreateWebhookDialog from '@/components/webhook/CreateWebhookDialog.vue';
import WebhooksEmptyIllustration from '@/components/webhooks/WebhooksEmptyIllustration.vue';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { destroy, show } from '@/routes/app/webhooks';
import { webhookEndpointParts, type Webhook } from '@/types/webhook';
import { webhookStatusDot } from '@/types/webhook-status';

defineProps<{
    webhooks: Webhook[];
}>();

const createDialogOpen = ref(false);

const openCreateDialog = (): void => {
    createDialogOpen.value = true;
};

const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);

const openWebhook = (webhook: Webhook) => {
    router.visit(show.url(webhook));
};

const handleDelete = (webhook: Webhook) => {
    confirmDeleteModal.value?.open({
        url: destroy.url(webhook),
    });
};
</script>

<template>
    <Head :title="$t('webhooks.title')" />

    <SettingsLayout
        :title="$t('webhooks.title')"
        :description="$t('webhooks.description')"
        :centered="webhooks.length === 0"
    >
        <template #actions>
            <Button
                data-testid="create-webhook-button"
                @click="openCreateDialog"
            >
                <IconPlus class="size-4" />
                {{ $t('webhooks.new') }}
            </Button>
        </template>

        <EmptyState
            v-if="webhooks.length === 0"
            :title="$t('webhooks.empty_title')"
            :description="$t('webhooks.empty_description')"
            data-testid="webhooks-empty"
        >
            <template #illustration>
                <WebhooksEmptyIllustration />
            </template>
            <template #action>
                <Button
                    data-testid="webhooks-empty-create"
                    @click="openCreateDialog"
                >
                    <IconPlus class="size-4" />
                    {{ $t('webhooks.new') }}
                </Button>
            </template>
        </EmptyState>

        <ul
            v-else
            id="webhooks-body"
            class="flex flex-col gap-2"
            data-testid="webhooks-scroll"
        >
            <SettingsListRow
                v-for="webhook in webhooks"
                :key="webhook.id"
                interactive
                :data-testid="`webhook-row-${webhook.id}`"
                @click="openWebhook(webhook)"
            >
                <template #media>
                    <span
                        class="relative inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                    >
                        <IconWebhook class="size-5" />
                        <span
                            :class="[
                                'absolute -end-0.5 -bottom-0.5 size-3 rounded-full ring-2 ring-card',
                                webhookStatusDot(webhook.status),
                            ]"
                            :data-testid="`webhook-status-dot-${webhook.id}`"
                        />
                    </span>
                </template>
                <p
                    class="truncate text-sm leading-tight"
                    :title="webhook.endpoint"
                    :data-testid="`webhook-endpoint-${webhook.id}`"
                >
                    <span class="font-emphasis text-foreground">{{
                        webhookEndpointParts(webhook.endpoint).host
                    }}</span
                    ><span class="text-muted-foreground">{{
                        webhookEndpointParts(webhook.endpoint).path
                    }}</span>
                </p>
                <p
                    class="flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground"
                >
                    <span
                        :data-testid="`webhook-status-${webhook.id}`"
                        >{{ $t(`webhooks.status.${webhook.status}`) }}</span
                    >
                    <span aria-hidden="true">·</span>
                    <span>{{
                        $tChoice(
                            'webhooks.events_count',
                            webhook.events.length,
                            { count: String(webhook.events.length) },
                        )
                    }}</span>
                    <span aria-hidden="true">·</span>
                    <span>
                        {{ $t('webhooks.table.last_sent') }}:
                        {{
                            webhook.last_sent_at
                                ? date.diffForHumans(webhook.last_sent_at)
                                : $t('webhooks.never')
                        }}
                    </span>
                </p>
                <template #actions>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="shrink-0 text-muted-foreground data-[state=open]:bg-accent"
                                :aria-label="$t('webhooks.row_actions')"
                                data-testid="row-actions-trigger"
                                @click.stop
                            >
                                <IconDotsVertical class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" @click.stop>
                            <DropdownMenuItem
                                :data-testid="`view-webhook-${webhook.id}`"
                                @click="openWebhook(webhook)"
                            >
                                <IconEye class="size-4" />
                                {{ $t('webhooks.actions.view') }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                data-testid="delete-webhook-button"
                                @click="handleDelete(webhook)"
                            >
                                <IconTrash class="size-4" />
                                {{ $t('webhooks.actions.delete') }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </SettingsListRow>
        </ul>
    </SettingsLayout>

    <CreateWebhookDialog v-model:open="createDialogOpen" />
    <ConfirmDeleteModal
        ref="confirmDeleteModal"
        :title="$t('webhooks.delete.title')"
        :description="$t('webhooks.delete.description')"
        :action="$t('webhooks.delete.confirm')"
        :cancel="$t('webhooks.delete.cancel')"
    />
</template>
