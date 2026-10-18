<script setup lang="ts">
import { IconCircleCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInboxResolutions } from '@/composables/inbox/useInboxResolutions';
import type { InboxTab } from '@/types/inbox';

const props = withDefaults(
    defineProps<{
        channelIds: string[];
        conversationId: string | null;
        tab?: InboxTab;
    }>(),
    { tab: 'comments' },
);

const { resolveMany } = useInboxResolutions();

const isMentions = computed(() => props.tab === 'mentions');

const labelKey = computed(() =>
    ({ comments: 'label', mentions: 'mentions_label', messages: 'messages_label' })[props.tab],
);

const allKey = computed(() => ({ comments: 'all', mentions: 'mentions_all', messages: 'messages_all' })[props.tab]);

const olderKey = computed(
    () => ({ comments: 'older', mentions: 'mentions_older', messages: 'messages_older' })[props.tab],
);

const conversationKey = computed(() => (props.tab === 'messages' ? 'messages_conversation' : 'conversation'));

const resolveAll = (): void => {
    resolveMany('all', { channelIds: props.channelIds, tab: props.tab });
};

const resolveOlder = (): void => {
    resolveMany('older', { channelIds: props.channelIds, tab: props.tab });
};

const resolveConversation = (): void => {
    if (props.conversationId) {
        resolveMany('conversation', { conversationId: props.conversationId });
    }
};
</script>

<template>
    <InboxTooltip :label="$t(`inbox.resolve_menu.${labelKey}`)" wrap>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0 data-[state=open]:bg-accent"
                :aria-label="$t(`inbox.resolve_menu.${labelKey}`)"
                data-testid="inbox-bulk-resolve"
            >
                <IconCircleCheck class="size-4 text-muted-foreground" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                data-testid="inbox-resolve-all"
                @select="resolveAll"
            >
                {{ $t(`inbox.resolve_menu.${allKey}`) }}
            </DropdownMenuItem>
            <DropdownMenuItem
                data-testid="inbox-resolve-older"
                @select="resolveOlder"
            >
                {{ $t(`inbox.resolve_menu.${olderKey}`) }}
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="conversationId && !isMentions"
                data-testid="inbox-resolve-conversation"
                @select="resolveConversation"
            >
                {{ $t(`inbox.resolve_menu.${conversationKey}`) }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
    </InboxTooltip>
</template>
