<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';

const props = withDefaults(
    defineProps<{
        socialAccountIds?: string[];
    }>(),
    { socialAccountIds: () => [] },
);

const { canCreatePost } = useWorkspaceAbilities();

const newPost = (): void =>
    openPostComposer({ socialAccountIds: props.socialAccountIds });
</script>

<template>
    <Button
        v-if="canCreatePost"
        variant="outline"
        class="max-sm:w-8 max-sm:px-0"
        :aria-label="$t('posts.publish.new_post')"
        data-testid="posts-new-post"
        @click="newPost"
    >
        <IconPlus class="size-4" />
        <span class="max-sm:sr-only">{{ $t('posts.publish.new_post') }}</span>
    </Button>
</template>
