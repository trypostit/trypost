<script setup lang="ts">
import { IconTrashX } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { Badge } from '@/components/ui/badge';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { transform } from '@/lib/helpers';
import type { PostCard } from '@/types/publish';

const props = defineProps<{
    post: PostCard;
    testKey: string;
}>();

const network = computed(() =>
    transform(props.post.platform, getPlatformLabel, ''),
);
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="rounded-full focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :aria-label="
                    $t('posts.publish.deleted_on_network.title', { network })
                "
                :data-testid="`post-deleted-on-network-${testKey}`"
            >
                <Badge
                    variant="destructive-subtle"
                    class="h-6 cursor-pointer gap-1 px-2 [&>svg]:size-4"
                >
                    <IconTrashX />
                    {{ $t('posts.publish.deleted_on_network.badge') }}
                </Badge>
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="flex w-80 flex-col gap-4"
            :data-testid="`post-deleted-on-network-popover-${testKey}`"
        >
            <p class="text-sm font-emphasis text-foreground">
                {{ $t('posts.publish.deleted_on_network.title', { network }) }}
            </p>
            <div class="flex flex-col gap-2">
                <p
                    v-if="post.platform"
                    class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <PlatformBrandIcon :platform="post.platform" />
                    <span class="truncate">
                        {{ post.social_account?.display_label ?? network }}
                    </span>
                </p>
                <p
                    class="text-sm text-foreground"
                    :data-testid="`post-deleted-on-network-hint-${testKey}`"
                >
                    {{
                        $t(
                            post.can_delete
                                ? 'posts.publish.deleted_on_network.hint'
                                : 'posts.publish.deleted_on_network.hint_without_delete',
                            { network },
                        )
                    }}
                </p>
            </div>
        </PopoverContent>
    </Popover>
</template>
