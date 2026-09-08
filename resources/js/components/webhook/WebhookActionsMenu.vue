<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    IconCopy,
    IconDots,
    IconPencil,
    IconPlayerPause,
    IconPlayerPlay,
    IconRefresh,
    IconSend,
    IconTrash,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { copyToClipboard } from '@/lib/utils';
import { sendTest, update } from '@/routes/app/webhooks';
import type { Webhook } from '@/types/webhook';
import { WebhookStatus } from '@/types/webhook-status';

const props = defineProps<{
    webhook: Webhook;
}>();

const emit = defineEmits<{
    edit: [];
    rotate: [];
    delete: [];
}>();

const togglingStatus = ref(false);
const sendingTest = ref(false);

const sendTestEvent = () => {
    sendingTest.value = true;

    router.post(sendTest.url(props.webhook), {}, {
        preserveScroll: true,
        onFinish: () => {
            sendingTest.value = false;
        },
    });
};

const toggleStatus = () => {
    togglingStatus.value = true;

    router.put(
        update.url(props.webhook),
        {
            status:
                props.webhook.status === WebhookStatus.Enabled
                    ? WebhookStatus.Disabled
                    : WebhookStatus.Enabled,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingStatus.value = false;
            },
        },
    );
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="icon" data-testid="webhook-actions-trigger">
                <IconDots class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem data-testid="edit-webhook-button" @click="emit('edit')">
                <IconPencil class="size-4" />
                {{ $t('webhooks.actions.edit') }}
            </DropdownMenuItem>
            <DropdownMenuItem :disabled="togglingStatus" @click="toggleStatus">
                <IconPlayerPlay v-if="webhook.status !== WebhookStatus.Enabled" class="size-4" />
                <IconPlayerPause v-else class="size-4" />
                {{
                    webhook.status === WebhookStatus.Enabled
                        ? $t('webhooks.actions.disable')
                        : $t('webhooks.actions.enable')
                }}
            </DropdownMenuItem>
            <DropdownMenuItem @click="emit('rotate')">
                <IconRefresh class="size-4" />
                {{ $t('webhooks.actions.rotate') }}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                data-testid="send-test-webhook"
                :disabled="sendingTest"
                @click="sendTestEvent"
            >
                <IconSend class="size-4" />
                {{ $t('webhooks.actions.send_test') }}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                data-testid="copy-id-button"
                @click="copyToClipboard(webhook.id, trans('webhooks.copied.id'))"
            >
                <IconCopy class="size-4" />
                {{ $t('webhooks.actions.copy_id') }}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                variant="destructive"
                data-testid="delete-webhook-button"
                @click="emit('delete')"
            >
                <IconTrash class="size-4" />
                {{ $t('webhooks.actions.delete') }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
