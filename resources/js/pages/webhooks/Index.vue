<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IconCopy, IconDots, IconEye, IconTrash, IconWebhook } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { copyToClipboard } from '@/lib/utils';
import { destroy, show } from '@/routes/app/webhooks';

interface WebhookItem {
    id: string;
    endpoint: string;
    events: string[];
    status: string;
    last_sent_at: string | null;
}

defineProps<{
    webhooks: WebhookItem[];
}>();

const createDialogOpen = ref(false);
const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const statusVariant = (status: string) => (status === 'enabled' ? 'default' : 'secondary');
</script>

<template>
    <Head :title="$t('webhooks.title')" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <div class="flex items-center justify-between gap-4">
                <PageHeader
                    :title="$t('webhooks.title')"
                    :description="$t('webhooks.description')"
                    :total="webhooks.length"
                />

                <Button data-testid="create-webhook-button" @click="createDialogOpen = true">
                    {{ $t('webhooks.new') }}
                </Button>
            </div>

            <div v-if="webhooks.length > 0" class="rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ $t('webhooks.table.endpoint') }}</TableHead>
                            <TableHead>{{ $t('webhooks.table.events') }}</TableHead>
                            <TableHead>{{ $t('webhooks.table.status') }}</TableHead>
                            <TableHead>{{ $t('webhooks.table.last_sent') }}</TableHead>
                            <TableHead class="w-10" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="webhook in webhooks" :key="webhook.id">
                            <TableCell class="font-medium">
                                <Link :href="show.url(webhook)" class="hover:underline">
                                    {{ webhook.endpoint }}
                                </Link>
                            </TableCell>
                            <TableCell>
                                <span class="text-sm text-muted-foreground">
                                    {{
                                        transChoice(
                                            'webhooks.events_count',
                                            webhook.events.length,
                                            { count: String(webhook.events.length) },
                                        )
                                    }}
                                </span>
                            </TableCell>
                            <TableCell>
                                <Badge :variant="statusVariant(webhook.status)">
                                    {{ $t(`webhooks.status.${webhook.status}`) }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                <span v-if="webhook.last_sent_at">{{
                                    date.diffForHumans(webhook.last_sent_at)
                                }}</span>
                                <span v-else>{{ $t('webhooks.never') }}</span>
                            </TableCell>
                            <TableCell>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                            data-testid="row-actions-trigger"
                                        >
                                            <IconDots class="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem as-child>
                                            <Link :href="show.url(webhook)">
                                                <IconEye class="size-4" />
                                                {{ $t('webhooks.actions.view') }}
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            data-testid="copy-id-button"
                                            @click="
                                                copyToClipboard(
                                                    webhook.id,
                                                    trans('webhooks.copied.id'),
                                                )
                                            "
                                        >
                                            <IconCopy class="size-4" />
                                            {{ $t('webhooks.actions.copy_id') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            data-testid="delete-webhook-button"
                                            @click="
                                                confirmDeleteModal?.open({
                                                    url: destroy.url(webhook),
                                                    confirmText: webhook.endpoint,
                                                })
                                            "
                                        >
                                            <IconTrash class="size-4" />
                                            {{ $t('webhooks.actions.delete') }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <EmptyState
                v-else
                :icon="IconWebhook"
                :title="$t('webhooks.empty_title')"
                :description="$t('webhooks.empty_description')"
            />

            <CreateWebhookDialog v-model:open="createDialogOpen" />
            <ConfirmDeleteModal
                ref="confirmDeleteModal"
                :title="$t('webhooks.delete.title')"
                :description="$t('webhooks.delete.description')"
                :action="$t('webhooks.delete.confirm')"
            />
        </div>
    </AppLayout>
</template>
