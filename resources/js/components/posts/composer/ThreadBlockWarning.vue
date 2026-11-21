<script setup lang="ts">
import { IconAlertTriangle } from '@tabler/icons-vue';
import { computed } from 'vue';

import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { filled } from '@/lib/helpers';
import { threadBlockKey, type ReplyPermission } from '@/lib/threadReplies';
import type { ContentTypeValue } from '@/types/content-type';

/**
 * Why a card's thread cannot go out (`threadBlockKey()`), at the top of the
 * card: its post type stands alone, or its channel lacks the scope its
 * network asks for replies. Someone who manages channels gets a link that
 * saves the post and reconnects; anyone else is told who can.
 */
const props = withDefaults(
    defineProps<{
        contentType?: ContentTypeValue | null;
        meta?: Record<string, any> | null;
        repliesPermission?: ReplyPermission | null;
        disabled?: boolean;
    }>(),
    {
        contentType: null,
        meta: null,
        repliesPermission: null,
        disabled: false,
    },
);

const emit = defineEmits<{ reconnect: [] }>();

const { canManageAccounts } = useWorkspaceAbilities();

const warningKey = computed(() =>
    threadBlockKey(
        props.contentType,
        props.meta,
        props.repliesPermission,
        canManageAccounts.value,
    ),
);

/** Only the permission's own message offers the link, shown to someone who manages channels. */
const canReconnect = computed((): boolean => {
    if (warningKey.value !== props.repliesPermission?.message) {
        return false;
    }

    return filled(warningKey.value);
});

const reconnect = (): void => emit('reconnect');
</script>

<template>
    <div
        v-if="warningKey"
        role="status"
        class="flex items-start gap-2 rounded-md bg-warning/15 px-3 py-1.5 text-sm"
        data-testid="thread-block-warning"
    >
        <IconAlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
        <span>
            {{ $t(warningKey) }}
            <button
                v-if="canReconnect"
                type="button"
                class="font-medium underline underline-offset-2 hover:text-foreground/80 disabled:pointer-events-none disabled:opacity-50"
                data-testid="thread-block-reconnect"
                :disabled="disabled"
                @click="reconnect"
            >
                {{ $t('posts.form.thread.save_and_reconnect') }}</button
            >
        </span>
    </div>
</template>
