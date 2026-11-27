<script setup lang="ts">
import { Head, InfiniteScroll, Link } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconArrowRight,
    IconChevronRight,
    IconPlus,
    IconRepeat,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import EmptyState from '@/components/EmptyState.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import CreateRepurposeDialog from '@/components/repurpose/CreateRepurposeDialog.vue';
import RepurposesEmptyIllustration from '@/components/repurpose/RepurposesEmptyIllustration.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { show } from '@/routes/app/repurposes';
import type { ChannelAccount } from '@/types/channel';
import type { Repurpose } from '@/types/repurpose';
import { repurposeStatusVariant } from '@/types/repurpose-status';

const props = defineProps<{
    repurposes: { data: Repurpose[] };
    sourceAccounts: ChannelAccount[];
    destinationAccounts: ChannelAccount[];
}>();

const createDialogOpen = ref(false);

const { canManageAccounts } = useWorkspaceAbilities();
const { open: openConnectDialog } = useConnectChannelDialog();

const hasSourceAccounts = computed(() => props.sourceAccounts.length > 0);

const connectChannel = (): void => {
    openConnectDialog();
};

const startBlank = () => {
    createDialogOpen.value = true;
};

const MAX_DESTINATION_AVATARS = 4;

const destinationAccountsOf = (repurpose: Repurpose): ChannelAccount[] =>
    repurpose.destinations.flatMap((destination) => {
        const account = props.destinationAccounts.find(
            (item) => item.id === destination.social_account_id,
        );

        return account ? [account] : [];
    });

const sourceCaption = (repurpose: Repurpose): string => {
    const format = trans(`repurposes.formats.${repurpose.source_format}`);

    return repurpose.source_account
        ? `${format} · ${getPlatformLabel(repurpose.source_account.platform)}`
        : format;
};
</script>

<template>
    <Head :title="$t('repurposes.title')" />

    <AppLayout full-width>
        <template #header>
            <HeaderTitle :title="$t('repurposes.title')" :icon="IconRepeat" />
        </template>

        <template #header-actions>
            <Button
                variant="outline"
                data-testid="create-repurpose-button"
                @click="startBlank"
            >
                <IconPlus aria-hidden="true" />
                {{ $t('repurposes.new') }}
            </Button>
        </template>

        <div
            class="flex h-full min-w-0 flex-1 flex-col gap-6 px-4 pt-2 pb-10 md:px-8"
        >

            <EmptyState
                v-if="repurposes.data.length === 0"
                :title="$t('repurposes.empty.title')"
                :description="$t('repurposes.empty.description')"
                data-testid="repurposes-empty"
            >
                <template #illustration>
                    <RepurposesEmptyIllustration />
                </template>
                <template v-if="hasSourceAccounts" #action>
                    <Button
                        data-testid="repurposes-empty-create"
                        @click="startBlank"
                    >
                        <IconPlus aria-hidden="true" />
                        {{ $t('repurposes.new') }}
                    </Button>
                </template>
                <template v-else-if="canManageAccounts" #action>
                    <Button
                        data-testid="repurposes-empty-connect"
                        @click="connectChannel"
                    >
                        <IconPlus aria-hidden="true" />
                        {{ $t('channels.connect') }}
                    </Button>
                </template>
            </EmptyState>

            <InfiniteScroll
                v-else
                data="repurposes"
                items-element="#repurposes-body"
                preserve-url
            >
                <ul
                    id="repurposes-body"
                    class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
                    data-testid="repurposes-table"
                >
                    <li
                        v-for="repurpose in repurposes.data"
                        :key="repurpose.id"
                    >
                        <Link
                            :href="show.url(repurpose.id)"
                            class="group grid min-w-0 items-center gap-x-6 gap-y-3 px-4 py-3.5 transition-control hover:bg-accent focus-visible:bg-accent focus-visible:outline-none md:grid-cols-[minmax(0,1fr)_11rem_auto] lg:grid-cols-[minmax(0,1fr)_12rem_24rem]"
                            :data-testid="`repurpose-row-${repurpose.id}`"
                        >
                            <div
                                class="flex min-w-0 items-center gap-3"
                            >
                                <ChannelAvatar
                                    v-if="repurpose.source_account"
                                    :platform="repurpose.source_account.platform"
                                    :name="repurpose.source_account.display_name"
                                    :src="repurpose.source_account.avatar_url"
                                    :status="repurpose.source_account.status"
                                    :size="40"
                                    ring="card"
                                    :data-testid="`repurpose-source-${repurpose.id}`"
                                />
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-sm font-emphasis text-foreground"
                                    >
                                        {{
                                            repurpose.source_account
                                                ?.display_name ??
                                            $t('repurposes.flow.no_source')
                                        }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ sourceCaption(repurpose) }}
                                    </p>
                                </div>
                            </div>

                            <div
                                class="flex min-w-0 items-center gap-3"
                                :data-testid="`repurpose-destinations-${repurpose.id}`"
                            >
                                <IconArrowRight
                                    class="size-4 shrink-0 text-subtle-foreground rtl:rotate-180"
                                    aria-hidden="true"
                                />
                                <div
                                    v-if="destinationAccountsOf(repurpose).length"
                                    class="flex items-center gap-2"
                                >
                                    <ChannelAvatar
                                        v-for="account in destinationAccountsOf(
                                            repurpose,
                                        ).slice(0, MAX_DESTINATION_AVATARS)"
                                        :key="account.id"
                                        :platform="account.platform"
                                        :name="account.display_name"
                                        :src="account.avatar_url"
                                        :size="28"
                                        ring="card"
                                        :title="account.display_name"
                                    />
                                    <span
                                        v-if="
                                            destinationAccountsOf(repurpose)
                                                .length > MAX_DESTINATION_AVATARS
                                        "
                                        class="text-xs text-muted-foreground tabular-nums"
                                        >+{{
                                            destinationAccountsOf(repurpose)
                                                .length - MAX_DESTINATION_AVATARS
                                        }}</span
                                    >
                                </div>
                                <span
                                    v-else
                                    class="truncate text-xs text-muted-foreground"
                                    >{{ $t('repurposes.flow.no_destinations') }}</span
                                >
                            </div>

                            <div
                                class="flex min-w-0 items-center justify-end gap-4"
                            >
                                <p
                                    class="hidden text-xs text-muted-foreground tabular-nums md:block"
                                >
                                    {{ $t('repurposes.table.published') }}
                                    <span class="text-foreground">{{
                                        repurpose.published_items_count ?? 0
                                    }}</span>
                                    <span aria-hidden="true"> · </span>
                                    {{ $t('repurposes.table.last_polled') }}
                                    <span class="text-foreground">{{
                                        repurpose.last_polled_at
                                            ? date.diffForHumans(
                                                  repurpose.last_polled_at,
                                              )
                                            : '—'
                                    }}</span>
                                </p>
                                <span class="flex items-center gap-1.5">
                                    <IconAlertTriangle
                                        v-if="repurpose.paused_reason"
                                        class="size-4 text-amber-500"
                                        :title="
                                            $t(
                                                'repurposes.health.stopped_itself',
                                            )
                                        "
                                        data-testid="repurpose-stopped-itself"
                                    />
                                    <Badge
                                        :variant="
                                            repurposeStatusVariant(
                                                repurpose.status,
                                            )
                                        "
                                        class="h-6 px-2"
                                    >
                                        {{
                                            $t(
                                                `repurposes.status.${repurpose.status}`,
                                            )
                                        }}
                                    </Badge>
                                </span>
                                <IconChevronRight
                                    class="size-4 shrink-0 text-subtle-foreground transition-transform group-hover:translate-x-0.5 rtl:rotate-180"
                                    aria-hidden="true"
                                />
                            </div>
                        </Link>
                    </li>
                </ul>
            </InfiniteScroll>
        </div>

        <CreateRepurposeDialog
            v-model:open="createDialogOpen"
            :source-accounts="sourceAccounts"
        />
    </AppLayout>
</template>
