<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconEye, IconTrash, IconWebhook } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import CreateWebhookDialog from '@/components/webhook/CreateWebhookDialog.vue';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, show } from '@/routes/app/webhooks';
import type { Webhook } from '@/types/webhook';
import { webhookStatusVariant } from '@/types/webhook-status';

defineProps<{
    webhooks: Webhook[];
}>();

const createDialogOpen = ref(false);
const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const openWebhook = (webhook: Webhook) => {
    router.visit(show.url(webhook));
};

const handleDelete = (webhook: Webhook) => {
    confirmDeleteModal.value?.open({
        url: destroy.url(webhook),
        confirmText: trans('common.confirm_modal.delete_keyword'),
    });
};
</script>

<template>
    <Head :title="$t('webhooks.title')" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <PageHeader
                :title="$t('webhooks.title')"
                :description="$t('webhooks.description')"
            />

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                <Button data-testid="create-webhook-button" @click="createDialogOpen = true">
                    {{ $t('webhooks.new') }}
                </Button>
            </div>

            <EmptyState
                v-if="webhooks.length === 0"
                :icon="IconWebhook"
                :title="$t('webhooks.empty_title')"
                :description="$t('webhooks.empty_description')"
            />

            <div v-else>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ $t('webhooks.table.endpoint') }}</TableHead>
                            <TableHead>{{ $t('webhooks.table.events') }}</TableHead>
                            <TableHead>{{ $t('webhooks.table.status') }}</TableHead>
                            <TableHead>{{ $t('webhooks.table.last_sent') }}</TableHead>
                            <TableHead class="text-right" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="webhook in webhooks"
                            :key="webhook.id"
                            class="cursor-pointer"
                            @click="openWebhook(webhook)"
                        >
                            <TableCell class="max-w-[160px] font-medium sm:max-w-md">
                                <p class="truncate">{{ webhook.endpoint }}</p>
                            </TableCell>
                            <TableCell>
                                {{
                                    transChoice(
                                        'webhooks.events_count',
                                        webhook.events.length,
                                        { count: String(webhook.events.length) },
                                    )
                                }}
                            </TableCell>
                            <TableCell>
                                <Badge :variant="webhookStatusVariant(webhook.status)">
                                    {{ $t(`webhooks.status.${webhook.status}`) }}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <span v-if="webhook.last_sent_at">{{
                                    date.diffForHumans(webhook.last_sent_at)
                                }}</span>
                                <span v-else>{{ $t('webhooks.never') }}</span>
                            </TableCell>
                            <TableCell class="text-right" @click.stop>
                                <div class="flex justify-end gap-2">
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="$t('webhooks.actions.view')"
                                        data-testid="row-actions-trigger"
                                        @click="openWebhook(webhook)"
                                    >
                                        <IconEye class="size-4" />
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        class="size-8 bg-rose-100 hover:bg-rose-200"
                                        :aria-label="$t('webhooks.actions.delete')"
                                        data-testid="delete-webhook-button"
                                        @click="handleDelete(webhook)"
                                    >
                                        <IconTrash class="size-4 text-rose-700" />
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <CreateWebhookDialog v-model:open="createDialogOpen" />
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
