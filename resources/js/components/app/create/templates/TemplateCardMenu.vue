<script setup lang="ts">
import { IconCopyPlus, IconDots, IconPencil, IconTrash } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

defineProps<{
    id: string;
    title: string;
    editable: boolean;
    triggerTestId: string;
}>();

const emit = defineEmits<{
    edit: [];
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
                :aria-label="$t('create.templates.actions_for', { title })"
                :data-testid="triggerTestId"
                @click.stop
            >
                <IconDots class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-44" @click.stop>
            <DropdownMenuItem
                v-if="editable"
                :data-testid="`template-edit-${id}`"
                @click="emit('edit')"
            >
                <IconPencil class="size-4" />
                {{ $t('create.templates.edit') }}
            </DropdownMenuItem>
            <DropdownMenuItem
                :data-testid="`template-duplicate-${id}`"
                @click="emit('duplicate')"
            >
                <IconCopyPlus class="size-4" />
                {{ $t('create.templates.duplicate') }}
            </DropdownMenuItem>
            <template v-if="editable">
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    :data-testid="`template-delete-${id}`"
                    @click="emit('delete')"
                >
                    <IconTrash class="size-4" />
                    {{ $t('create.templates.delete') }}
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
