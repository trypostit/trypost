<script setup lang="ts">
import { IconCircleCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

const props = defineProps<{
    repliedCount: number;
    totalCount: number;
}>();

const everyCommentReplied = computed(
    () => props.totalCount > 0 && props.repliedCount >= props.totalCount,
);
</script>

<template>
    <div
        class="flex min-w-0 items-center gap-3 text-xs leading-[18px] text-foreground max-md:text-sm max-md:leading-[21px]"
        data-testid="inbox-status-line"
    >
        <span v-if="everyCommentReplied" class="flex min-w-0 items-center gap-1.5 max-md:shrink-0">
            <IconCircleCheck class="size-4 shrink-0 text-success-text" />
            <span class="truncate">{{
                $tChoice('inbox.conversation.all_replied', totalCount, {
                    count: String(totalCount),
                })
            }}</span>
        </span>
        <span v-else class="truncate max-md:shrink-0">
            <span class="font-medium">{{
                $t('inbox.conversation.replied_part', {
                    replied: String(repliedCount),
                })
            }}</span>
            {{ ' ' }}
            <span class="text-muted-foreground">{{
                $t('inbox.conversation.total_part', {
                    total: String(totalCount),
                })
            }}</span>
        </span>
    </div>
</template>
