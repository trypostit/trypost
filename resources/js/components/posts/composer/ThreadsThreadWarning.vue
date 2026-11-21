<script setup lang="ts">
import { IconAlertTriangle } from '@tabler/icons-vue';
import { computed } from 'vue';

import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import {
    THREAD_REPLIES_PERMISSION_KEY,
    threadBlockKey,
} from '@/lib/threadReplies';
import type { ContentTypeValue } from '@/types/content-type';

/**
 * Why a Threads card's thread cannot go out (a ghost post, or a channel
 * without the replies permission), with the way to fix it: a user who manages
 * channels saves and reconnects, anyone else is told an admin needs to.
 */
const props = withDefaults(
    defineProps<{
        contentType?: ContentTypeValue | null;
        meta?: Record<string, any>;
        repliesAllowed?: boolean;
        disabled?: boolean;
    }>(),
    {
        contentType: null,
        meta: () => ({}),
        repliesAllowed: undefined,
        disabled: false,
    },
);

const emit = defineEmits<{ reconnect: [] }>();

const { canManageAccounts } = useWorkspaceAbilities();

const blockKey = computed(() =>
    threadBlockKey(props.contentType, props.meta, props.repliesAllowed),
);

const canReconnect = computed((): boolean => {
    if (blockKey.value !== THREAD_REPLIES_PERMISSION_KEY) {
        return false;
    }

    return canManageAccounts.value;
});

const warningKey = computed((): string | null => {
    if (blockKey.value !== THREAD_REPLIES_PERMISSION_KEY) {
        return blockKey.value;
    }

    if (canManageAccounts.value) {
        return blockKey.value;
    }

    return 'posts.form.thread.replies_permission_admin';
});

const reconnect = (): void => emit('reconnect');
</script>

<template>
    <div
        v-if="warningKey"
        role="status"
        class="flex items-start gap-2 rounded-md bg-warning/15 px-3 py-1.5 text-sm"
        data-testid="threads-thread-warning"
    >
        <IconAlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
        <span>
            {{ $t(warningKey) }}
            <button
                v-if="canReconnect"
                type="button"
                class="font-medium underline underline-offset-2 hover:text-foreground/80 disabled:pointer-events-none disabled:opacity-50"
                data-testid="threads-thread-reconnect"
                :disabled="disabled"
                @click="reconnect"
            >
                {{ $t('posts.form.thread.save_and_reconnect') }}</button
            >
        </span>
    </div>
</template>
