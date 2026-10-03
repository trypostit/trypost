<script setup lang="ts">
import {
    IconChevronDown,
    IconCompass,
    IconFolderPlus,
    IconPlus,
} from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const emit = defineEmits<{
    add: [];
    explore: [];
    newCollection: [];
}>();
</script>

<template>
    <ButtonGroup>
        <Button
            variant="outline"
            class="max-sm:w-8 max-sm:px-0"
            :aria-label="$t('create.feeds.new')"
            data-testid="feeds-new"
            @click="emit('add')"
        >
            <IconPlus class="size-4" />
            <span class="max-sm:sr-only">{{ $t('create.feeds.new') }}</span>
        </Button>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="outline"
                    size="icon"
                    class="data-[state=open]:bg-accent"
                    :aria-label="$t('create.feeds.more_options')"
                    data-testid="feeds-new-menu"
                >
                    <IconChevronDown class="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                class="w-52"
                @close-auto-focus.prevent
            >
                <DropdownMenuItem
                    data-testid="feeds-explore"
                    @click="emit('explore')"
                >
                    <IconCompass class="size-4" />
                    {{ $t('create.feeds.explore') }}
                </DropdownMenuItem>
                <DropdownMenuItem
                    data-testid="feeds-new-collection"
                    @click="emit('newCollection')"
                >
                    <IconFolderPlus class="size-4" />
                    {{ $t('create.feeds.new_collection') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </ButtonGroup>
</template>
