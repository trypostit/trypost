<script setup lang="ts">
import { IconCheck, IconSearch } from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/app/ChannelAvatar.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { filterBySearch } from '@/lib/app/search';
import type { ComposerAccount } from '@/types/app/post-composition';

const props = defineProps<{
    accounts: ComposerAccount[];
    selectedIds: string[];
}>();

const search = defineModel<string>('search', { required: true });

const emit = defineEmits<{
    (event: 'toggle', account: ComposerAccount): void;
    (event: 'toggle-all', accounts: ComposerAccount[]): void;
}>();

const visibleAccounts = computed(() =>
    filterBySearch(props.accounts, search.value, (account) => [
        account.display_name,
        account.username,
        getPlatformLabel(account.platform),
    ]),
);

const isSelected = (account: ComposerAccount): boolean =>
    props.selectedIds.includes(account.id);

const allSelected = (): boolean => visibleAccounts.value.every(isSelected);
</script>

<template>
    <div class="space-y-3">
        <div
            class="flex h-8 items-center gap-2 rounded-lg border border-input px-2 transition-control focus-within:border-primary-text"
        >
            <IconSearch
                class="size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <input
                v-model="search"
                type="search"
                data-testid="composer-account-search"
                :aria-label="$t('posts.channel_search_placeholder')"
                :placeholder="$t('posts.channel_search_placeholder')"
                class="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-subtle-foreground"
            />
        </div>
        <div>
            <div
                class="flex items-center justify-between px-2 py-2 text-sm text-foreground"
            >
                <span>{{ $t('posts.edit.tabs.channels') }}</span>
                <button
                    v-if="visibleAccounts.length"
                    type="button"
                    class="rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                    data-testid="composer-select-all"
                    @click="emit('toggle-all', visibleAccounts)"
                >
                    {{
                        allSelected()
                            ? $t('posts.composer.deselect_all')
                            : $t('posts.composer.select_all')
                    }}
                </button>
            </div>
            <div class="max-h-72 space-y-1 overflow-y-auto">
                <button
                    v-for="account in visibleAccounts"
                    :key="account.id"
                    type="button"
                    :data-testid="`composer-account-option-${account.id}`"
                    :aria-pressed="isSelected(account)"
                    class="flex h-12 w-full items-center gap-3 rounded-lg p-2 text-left text-sm transition-control hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                    @click="emit('toggle', account)"
                >
                    <ChannelAvatar
                        :status="account.status"
                        :account-id="account.id"
                        :platform="account.platform"
                        :src="account.avatar_url"
                        :verified="account.verified_badge"
                        :name="account.display_name || account.username"
                        ring="popover"
                    />
                    <span class="min-w-0 flex-1 truncate">{{
                        account.display_name || account.username
                    }}</span>
                    <span
                        class="flex size-4 shrink-0 items-center justify-center rounded-sm border transition-control"
                        :class="
                            isSelected(account)
                                ? 'border-primary-strong bg-primary-strong text-primary-strong-foreground'
                                : 'border-input bg-background'
                        "
                    >
                        <IconCheck
                            v-if="isSelected(account)"
                            class="size-3"
                            stroke-width="3"
                        />
                    </span>
                </button>
                <p
                    v-if="!visibleAccounts.length"
                    class="px-2 py-4 text-center text-sm text-muted-foreground"
                >
                    {{ $t('posts.no_channels') }}
                </p>
            </div>
        </div>
    </div>
</template>
