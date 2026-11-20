<script setup lang="ts">
import { useEventListener } from '@vueuse/core';
import { nextTick, ref, watch } from 'vue';

import CommandPaletteContent from '@/components/command-palette/CommandPaletteContent.vue';
import InviteMemberDialog from '@/components/members/InviteMemberDialog.vue';
import { CommandDialog } from '@/components/ui/command';
import { useWorkspaceStorage } from '@/composables/useBrowserStorage';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { isPostComposerOpen } from '@/composables/useGlobalPostComposer';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import type { CommandPaletteEntry } from '@/types/command-palette';

const RECENT_LIMIT = 3;

const { isOpen, close, toggle } = useCommandPalette();
const { canManageTeam } = useWorkspaceAbilities();

const inviteMemberDialogOpen = ref(false);

const recent = useWorkspaceStorage('commandPalette.recent', []);

const recentIds = ref<string[]>([]);

watch(isOpen, (open) => {
    if (open) {
        recentIds.value = recent.value;
    }
});

const remember = (id: string): void => {
    recent.value = [id, ...recent.value.filter((stored) => stored !== id)].slice(
        0,
        RECENT_LIMIT * 4,
    );
};

const onSelect = (entry: CommandPaletteEntry): void => {
    remember(entry.id);
    close();
    nextTick(() => entry.run());
};

const openInviteMember = (): void => {
    inviteMemberDialogOpen.value = true;
};

const onOpenChange = (open: boolean): void => {
    isOpen.value = open;
};

useEventListener(window, 'keydown', (event: KeyboardEvent) => {
    if (
        event.key.toLowerCase() !== 'k' ||
        !(event.metaKey || event.ctrlKey) ||
        event.altKey ||
        event.shiftKey ||
        event.repeat
    ) {
        return;
    }

    if (isPostComposerOpen.value && !isOpen.value) {
        return;
    }

    event.preventDefault();
    toggle();
});
</script>

<template>
    <CommandDialog
        :open="isOpen"
        :title="$t('command_palette.title')"
        :description="$t('command_palette.description')"
        highlight-on-hover
        class="top-[12dvh] flex max-h-none w-[680px] translate-y-0 flex-col gap-0 overflow-hidden rounded-2xl border-0 bg-popover p-0 shadow-xl ring-1 ring-border-strong sm:max-w-[680px] dark:border-0"
        command-class="rounded-none bg-transparent"
        data-testid="command-palette"
        @update:open="onOpenChange"
    >
        <CommandPaletteContent
            :recent-ids="recentIds"
            :invite-member="openInviteMember"
            @select="onSelect"
        />
    </CommandDialog>
    <InviteMemberDialog
        v-if="canManageTeam"
        v-model:open="inviteMemberDialogOpen"
    />
</template>
