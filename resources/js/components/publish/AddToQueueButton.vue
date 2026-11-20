<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconListNumbers } from '@tabler/icons-vue';
import { ref } from 'vue';

import QueueSetupIllustration from '@/components/publish/QueueSetupIllustration.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { schedulePostCard } from '@/composables/usePostCardActions';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { settings as channelSettings } from '@/routes/app/channels';
import type { PostCard, PublishSocialAccount } from '@/types/publish';

const props = defineProps<{
    post: PostCard;
    account: PublishSocialAccount;
}>();

const { canManageAccounts } = useWorkspaceAbilities();

const queueSetupOpen = ref(false);

const addToQueue = (): void => {
    schedulePostCard(props.post.id, 'queue_next');
};

const closeQueueSetup = (): void => {
    queueSetupOpen.value = false;
};

const openPostingTimes = (): void => {
    router.visit(channelSettings.url(props.account.id));
};
</script>

<template>
    <Tooltip v-if="account.has_posting_schedule">
        <TooltipTrigger as-child>
            <Button
                variant="outline"
                :data-testid="`post-add-to-queue-${post.id}`"
                @click="addToQueue"
            >
                <IconListNumbers class="size-4" />
                {{ $t('posts.publish.actions.add_to_queue') }}
            </Button>
        </TooltipTrigger>
        <TooltipContent :data-testid="`post-add-to-queue-tooltip-${post.id}`">
            {{ $t('posts.publish.actions.add_to_queue_hint') }}
        </TooltipContent>
    </Tooltip>
    <Popover v-else v-model:open="queueSetupOpen">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                :data-testid="`post-add-to-queue-${post.id}`"
            >
                <IconListNumbers class="size-4" />
                {{ $t('posts.publish.actions.add_to_queue') }}
            </Button>
        </PopoverTrigger>
        <PopoverContent
            side="top"
            align="end"
            class="w-80 space-y-3"
            :data-testid="`post-queue-setup-${post.id}`"
        >
            <QueueSetupIllustration
                :data-testid="`post-queue-setup-illustration-${post.id}`"
            />
            <p class="text-sm font-semibold">
                {{ $t('posts.publish.actions.queue_setup.title') }}
            </p>
            <p class="text-sm text-muted-foreground">
                {{
                    canManageAccounts
                        ? $t('posts.publish.actions.queue_setup.body')
                        : $t('posts.publish.actions.queue_setup.body_member')
                }}
            </p>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :data-testid="`post-queue-setup-not-now-${post.id}`"
                    @click="closeQueueSetup"
                >
                    <span data-single-line>{{
                        $t('posts.publish.actions.queue_setup.not_now')
                    }}</span>
                </Button>
                <Button
                    v-if="canManageAccounts"
                    type="button"
                    size="sm"
                    :data-testid="`post-queue-setup-action-${post.id}`"
                    @click="openPostingTimes"
                >
                    <span data-single-line>{{
                        $t('posts.publish.actions.queue_setup.action')
                    }}</span>
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
