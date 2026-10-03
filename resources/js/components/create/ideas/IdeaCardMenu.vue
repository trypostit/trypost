<script setup lang="ts">
import {
    IconArrowsExchange,
    IconCopyPlus,
    IconDots,
    IconSquareCheck,
    IconTrash,
} from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { columnKey, type IdeaCard, type IdeaStage } from '@/types/idea';

defineProps<{
    card: IdeaCard;
    stages: IdeaStage[];
}>();

const emit = defineEmits<{
    select: [];
    move: [stageId: string | null];
    duplicate: [];
    delete: [];
}>();

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <DropdownMenu v-model:open="open">
        <DropdownMenuTrigger as-child>
            <Button
                variant="outline"
                size="icon-xs"
                class="bg-card data-[state=open]:bg-accent"
                :aria-label="
                    $t('create.ideas.actions_for', {
                        title: card.title || $t('create.ideas.untitled'),
                    })
                "
                :data-testid="`idea-card-menu-${card.id}`"
                @click.stop
            >
                <IconDots class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-52" @click.stop>
            <DropdownMenuItem
                :data-testid="`idea-select-${card.id}`"
                @click="emit('select')"
            >
                <IconSquareCheck class="size-4" />
                {{ $t('create.ideas.select') }}
            </DropdownMenuItem>
            <DropdownMenuSub>
                <DropdownMenuSubTrigger :data-testid="`idea-move-${card.id}`">
                    <IconArrowsExchange class="size-4" />
                    {{ $t('create.ideas.move_to_stage') }}
                </DropdownMenuSubTrigger>
                <DropdownMenuSubContent class="max-h-72 w-52 overflow-y-auto">
                    <DropdownMenuItem
                        :disabled="card.idea_stage_id === null"
                        :data-testid="`idea-move-to-${card.id}-${columnKey(null)}`"
                        @click="emit('move', null)"
                    >
                        {{ $t('create.ideas.unassigned') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="stage in stages"
                        :key="stage.id"
                        :disabled="card.idea_stage_id === stage.id"
                        :data-testid="`idea-move-to-${card.id}-${stage.id}`"
                        @click="emit('move', stage.id)"
                    >
                        <span class="truncate">{{ stage.name }}</span>
                    </DropdownMenuItem>
                </DropdownMenuSubContent>
            </DropdownMenuSub>
            <DropdownMenuItem
                :data-testid="`idea-duplicate-${card.id}`"
                @click="emit('duplicate')"
            >
                <IconCopyPlus class="size-4" />
                {{ $t('create.ideas.duplicate') }}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                variant="destructive"
                :data-testid="`idea-delete-${card.id}`"
                @click="emit('delete')"
            >
                <IconTrash class="size-4" />
                {{ $t('create.ideas.delete') }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
