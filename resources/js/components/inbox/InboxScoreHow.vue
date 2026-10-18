<script setup lang="ts">
import { IconChevronDown } from '@tabler/icons-vue';
import { ref } from 'vue';

import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

const isOpen = ref(false);
</script>

<template>
    <Collapsible v-model:open="isOpen">
        <CollapsibleTrigger
            class="flex cursor-pointer items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
            data-testid="inbox-score-how"
        >
            {{ $t('inbox.score.how') }}
            <IconChevronDown
                class="size-3.5 transition-transform"
                :class="{ 'rotate-180': isOpen }"
            />
        </CollapsibleTrigger>
        <CollapsibleContent
            class="flex flex-col gap-1.5 pt-1.5 text-xs text-muted-foreground"
            data-testid="inbox-score-how-content"
        >
            <p>{{ $t('inbox.score.how_body') }}</p>
            <p>{{ $t('inbox.score.how_details') }}</p>
        </CollapsibleContent>
    </Collapsible>
</template>
