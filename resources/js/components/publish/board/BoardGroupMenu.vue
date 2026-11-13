<script setup lang="ts">
import { IconCheck, IconChevronDown, IconLayoutKanban } from '@tabler/icons-vue';
import { ref } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import type { BoardGroup } from '@/types/board';

defineProps<{
    groups: BoardGroup[];
}>();

const group = defineModel<BoardGroup>({ required: true });

const belowSm = useBelowBreakpoint('sm');
const sheetOpen = ref(false);

const openSheet = (): void => {
    sheetOpen.value = true;
};

const select = (value: BoardGroup): void => {
    sheetOpen.value = false;

    if (value !== group.value) {
        group.value = value;
    }
};

const chooseFromMenu = (value: unknown): void => {
    select(value as BoardGroup);
};

const rowClass =
    'flex h-11 w-full items-center gap-2 rounded-md px-2 text-start text-base text-foreground transition-control hover:bg-accent focus-visible:bg-accent focus-visible:outline-none';
</script>

<template>
    <div class="contents">
        <template v-if="belowSm">
            <Button
                variant="ghost"
                class="shrink-0 gap-1.5 px-2"
                :aria-label="`${$t('posts.board.group_by')}: ${$t(`posts.board.groups.${group}`)}`"
                aria-haspopup="dialog"
                :aria-expanded="sheetOpen"
                data-testid="board-group-trigger"
                @click="openSheet"
            >
                <IconLayoutKanban class="size-4 text-muted-foreground" />
                {{ $t(`posts.board.groups.${group}`) }}
                <IconChevronDown class="size-4 text-muted-foreground" />
            </Button>
            <BottomSheet
                v-model:open="sheetOpen"
                :title="$t('posts.board.group_by')"
                :show-header="false"
                handle
                test-id="board-group-sheet"
                content-class="px-3 pb-6"
            >
                <p class="px-2 pt-1 pb-1.5 text-xs text-muted-foreground">
                    {{ $t('posts.board.group_by') }}
                </p>
                <button
                    v-for="option in groups"
                    :key="option"
                    type="button"
                    :aria-pressed="option === group"
                    :class="rowClass"
                    :data-testid="`board-group-option-${option}`"
                    @click="select(option)"
                >
                    {{ $t(`posts.board.groups.${option}`) }}
                    <IconCheck
                        v-if="option === group"
                        class="ms-auto size-4"
                    />
                </button>
            </BottomSheet>
        </template>

        <DropdownMenu v-else>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    class="shrink-0 gap-1.5 px-2 data-[state=open]:bg-accent"
                    data-testid="board-group-trigger"
                >
                    <IconLayoutKanban class="size-4 text-muted-foreground" />
                    <span class="text-muted-foreground">{{
                        $t('posts.board.group_by')
                    }}</span>
                    {{ $t(`posts.board.groups.${group}`) }}
                    <IconChevronDown class="size-4 text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                class="w-48"
                data-testid="board-group-content"
            >
                <DropdownMenuRadioGroup
                    :model-value="group"
                    @update:model-value="chooseFromMenu"
                >
                    <DropdownMenuRadioItem
                        v-for="option in groups"
                        :key="option"
                        :value="option"
                        :data-testid="`board-group-option-${option}`"
                    >
                        <template #indicator-icon>
                            <IconCheck class="size-4" />
                        </template>
                        {{ $t(`posts.board.groups.${option}`) }}
                    </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
