<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { InboxReviewReplyState } from '@/types/inbox';

defineProps<{
    state: InboxReviewReplyState;
}>();
</script>

<template>
    <Badge
        v-if="state !== 'approved'"
        :variant="state === 'rejected' ? 'destructive-subtle' : 'secondary'"
        :data-state="state"
        data-testid="inbox-review-reply-state"
    >
        {{
            state === 'rejected'
                ? $t('inbox.review.state.rejected')
                : $t('inbox.review.state.pending')
        }}
    </Badge>
</template>
