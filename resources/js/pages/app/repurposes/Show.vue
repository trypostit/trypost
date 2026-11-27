<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconArrowLeft,
    IconCircleCheck,
    IconLoader2,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onUnmounted, ref, watch } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import ChannelConfigurator from '@/components/ChannelConfigurator.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import RepurposeHealthBanner from '@/components/repurpose/RepurposeHealthBanner.vue';
import RepurposeItemList from '@/components/repurpose/RepurposeItemList.vue';
import RepurposeLifecycle from '@/components/repurpose/RepurposeLifecycle.vue';
import RepurposesEmptyIllustration from '@/components/repurpose/RepurposesEmptyIllustration.vue';
import RepurposeSummary from '@/components/repurpose/RepurposeSummary.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePageErrors } from '@/composables/usePageErrors';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import debounce from '@/debounce';
import AppLayout from '@/layouts/AppLayout.vue';
import { MediaType } from '@/lib/mediaType';
import { getPlatformMetaIssue } from '@/lib/platformMeta';
import {
    destroy,
    index as repurposesIndex,
    update,
} from '@/routes/app/repurposes';
import type { PinterestBoard } from '@/types';
import type {
    Channel,
    ChannelAccount,
    ChannelTikTokCreatorInfo,
} from '@/types/channel';
import type { MediaItem } from '@/types/media';
import type {
    PublishModeOption,
    Repurpose,
    RepurposeDestination,
    RepurposeItem,
    RepurposePublishMode,
    RepurposeSourceFormat,
    SourceFormatOption,
} from '@/types/repurpose';
import { repurposeStatusVariant } from '@/types/repurpose-status';
import { SocialAccountStatus } from '@/types/social-account-status';

const props = defineProps<{
    repurpose: Repurpose;
    sourceAccounts: ChannelAccount[];
    destinationAccounts: ChannelAccount[];
    items: { data: RepurposeItem[] };
    sourceFormats: SourceFormatOption[];
    publishModes: PublishModeOption[];
    recommendedFormats: Record<string, string>;
    platformConfigs: Record<string, { publishConfig?: Record<string, any> }>;
    pinterestBoards: Record<
        string,
        { boards: PinterestBoard[]; truncated: boolean }
    >;
    tiktokCreatorInfos: Record<string, ChannelTikTokCreatorInfo | null>;
}>();

const availableAccountIds = new Set(
    props.destinationAccounts.map((account) => account.id),
);

const form = useForm<{
    source_social_account_id: string | null;
    source_format: RepurposeSourceFormat;
    publish_mode: RepurposePublishMode;
    destinations: RepurposeDestination[];
}>({
    source_social_account_id: props.repurpose.source_social_account_id,
    source_format: props.repurpose.source_format,
    publish_mode: props.repurpose.publish_mode,
    destinations: (props.repurpose.destinations ?? []).filter((destination) =>
        availableAccountIds.has(destination.social_account_id),
    ),
});

const errors = usePageErrors();

const plannedMedia = computed<MediaItem[]>(() => [
    { id: 'repurpose-video', url: '', type: MediaType.Video },
]);

const destinationAccounts = computed(() =>
    props.destinationAccounts.filter(
        (account) => account.id !== form.source_social_account_id,
    ),
);

watch(
    () => form.source_social_account_id,
    (accountId) => {
        form.destinations = form.destinations.filter(
            (destination) => destination.social_account_id !== accountId,
        );
    },
);

const channels = computed<Channel[]>(() =>
    destinationAccounts.value.map((account) => {
        const index = form.destinations.findIndex(
            (item) => item.social_account_id === account.id,
        );
        const destination = form.destinations[index];

        return {
            id: account.id,
            platform: account.platform,
            issue:
                index === -1
                    ? null
                    : getPlatformMetaIssue(
                          account.platform,
                          destination.meta ?? {},
                      ),
            displayName: account.display_name,
            username: account.username ?? null,
            avatarUrl: account.avatar_url,
            socialAccount: account,
            contentType:
                destination?.content_type ??
                props.recommendedFormats[account.id] ??
                '',
            meta: destination?.meta ?? {},
            boards: props.pinterestBoards?.[account.id]?.boards ?? [],
            boardsTruncated:
                props.pinterestBoards?.[account.id]?.truncated ?? false,
            creatorInfo: props.tiktokCreatorInfos?.[account.id] ?? null,
            publishConfig:
                props.platformConfigs?.[account.id]?.publishConfig ?? {},
            contentTypeError:
                errors.value[`destinations.${index}.content_type`],
        };
    }),
);

const selectedAccountIds = computed(() =>
    form.destinations.map((destination) => destination.social_account_id),
);

const toggleDestination = (accountId: string) => {
    if (selectedAccountIds.value.includes(accountId)) {
        form.destinations = form.destinations.filter(
            (destination) => destination.social_account_id !== accountId,
        );

        return;
    }

    form.destinations = [
        ...form.destinations,
        {
            social_account_id: accountId,
            content_type: props.recommendedFormats[accountId] ?? '',
            meta: {},
        },
    ];
};

const updateDestination = (
    accountId: string,
    changes: Partial<RepurposeDestination>,
) => {
    form.destinations = form.destinations.map((destination) =>
        destination.social_account_id === accountId
            ? { ...destination, ...changes }
            : destination,
    );
};

const setDestinationContentType = (accountId: string, contentType: string) =>
    updateDestination(accountId, { content_type: contentType });

const setDestinationMeta = (accountId: string, meta: Record<string, any>) =>
    updateDestination(accountId, { meta });

const selectedSourceAccount = computed(
    () =>
        props.sourceAccounts.find(
            (account) => account.id === form.source_social_account_id,
        ) ?? props.repurpose.source_account,
);

const currentFormatLabel = computed(
    () =>
        props.sourceFormats.find(
            (option) => option.value === form.source_format,
        )?.label ?? '',
);

type RepurposeTab = 'configuration' | 'activity';

const REPURPOSE_TABS: RepurposeTab[] = ['configuration', 'activity'];

const activeTab = ref<RepurposeTab>('configuration');

const selectTab = (tab: RepurposeTab): void => {
    activeTab.value = tab;
};

const sourceAccountId = computed({
    get: () => form.source_social_account_id ?? undefined,
    set: (value: string | undefined) => {
        form.source_social_account_id = value ?? null;
    },
});

const sourceAccountOptions = computed(() =>
    props.sourceAccounts.map((account) => ({
        value: account.id,
        label: account.display_name,
        platform: account.platform,
        avatar: account.avatar_url,
        status: account.status ?? null,
        disconnected: account.status !== SocialAccountStatus.Connected,
    })),
);

const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);

const isSaving = ref(false);
const showSaved = ref(false);

const save = () => {
    if (isSaving.value) {
        debouncedSave();

        return;
    }

    isSaving.value = true;
    showSaved.value = false;

    form.put(update.url(props.repurpose.id), {
        preserveScroll: true,
        onSuccess: () => {
            showSaved.value = true;
            setTimeout(() => {
                showSaved.value = false;
            }, 2000);
        },
        onFinish: () => {
            isSaving.value = false;
        },
    });
};

const debouncedSave = debounce(save, 1500);

watch(
    () => [
        form.source_social_account_id,
        form.source_format,
        form.publish_mode,
        form.destinations,
    ],
    () => {
        showSaved.value = false;
        debouncedSave();
    },
    { deep: true },
);

onUnmounted(() => debouncedSave.cancel());

const blockedReason = computed<string | null>(() => {
    if (form.destinations.length === 0) {
        return trans('repurposes.errors.destinations_required');
    }

    const issues = channels.value
        .filter(
            (channel) =>
                selectedAccountIds.value.includes(channel.id) && channel.issue,
        )
        .map((channel) => `${channel.displayName}: ${channel.issue}`);

    return issues.length > 0 ? issues.join('\n') : null;
});

const handleDelete = () => {
    confirmDeleteModal.value?.open({
        url: destroy.url(props.repurpose.id),
    });
};
</script>

<template>
    <Head :title="$t('repurposes.show.title')" />

    <AppLayout full-width>
        <div
            class="flex min-w-0 flex-col px-4 pt-6 pb-18 md:px-8"
            data-testid="repurpose-page"
        >
            <div class="flex min-h-12 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-4">
                <div class="flex min-w-0 items-center gap-2 max-sm:flex-wrap max-sm:gap-y-3 sm:flex-1">
                    <Button
                        as-child
                        variant="ghost"
                        size="icon"
                        class="shrink-0"
                    >
                        <Link
                            :href="repurposesIndex.url()"
                            :aria-label="$t('repurposes.title')"
                            data-testid="repurpose-back"
                        >
                            <IconArrowLeft
                                class="size-4 text-muted-foreground rtl:rotate-180"
                            />
                        </Link>
                    </Button>
                    <div class="flex min-w-0 items-center gap-4 max-sm:contents">
                        <ChannelAvatar
                            v-if="selectedSourceAccount"
                            :platform="selectedSourceAccount.platform"
                            :src="selectedSourceAccount.avatar_url"
                            :name="selectedSourceAccount.display_name"
                            :status="selectedSourceAccount.status ?? null"
                            :account-id="selectedSourceAccount.id"
                            :size="44"
                            :data-platform="selectedSourceAccount.platform"
                            data-testid="repurpose-source-avatar"
                        />
                        <div class="min-w-0 max-sm:contents">
                            <div class="flex min-w-0 items-center gap-2 max-sm:flex-1">
                                <h1
                                    class="truncate font-heading text-xl leading-tight font-medium text-foreground"
                                >
                                    {{ $t('repurposes.show.title') }}
                                </h1>
                                <Badge
                                    :variant="
                                        repurposeStatusVariant(repurpose.status)
                                    "
                                    class="h-6 shrink-0 px-2"
                                    data-testid="repurpose-status"
                                >
                                    {{
                                        $t(
                                            `repurposes.status.${repurpose.status}`,
                                        )
                                    }}
                                </Badge>
                            </div>
                            <RepurposeSummary
                                class="max-sm:basis-full"
                                :source-account="selectedSourceAccount"
                                :format-label="currentFormatLabel"
                                :destinations="form.destinations"
                                :destination-accounts="destinationAccounts"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    <span
                        v-if="isSaving"
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        data-testid="repurpose-saving"
                    >
                        <IconLoader2 class="size-3.5 animate-spin" />
                        {{ $t('repurposes.show.saving') }}
                    </span>
                    <span
                        v-else-if="showSaved"
                        class="flex items-center gap-1.5 text-xs text-success-text"
                        data-testid="repurpose-saved"
                    >
                        <IconCircleCheck class="size-3.5" />
                        {{ $t('repurposes.show.saved') }}
                    </span>

                    <RepurposeLifecycle
                        :repurpose="repurpose"
                        :blocked-reason="blockedReason"
                        @delete="handleDelete"
                    />
                </div>
            </div>

            <div class="mt-4 flex flex-col gap-3 empty:hidden">
                <RepurposeHealthBanner
                    :repurpose="repurpose"
                    :accounts="destinationAccounts"
                />

                <p
                    v-if="repurpose.last_error"
                    class="flex items-start gap-2 rounded-lg bg-critical-subtle px-4 py-3 text-sm text-destructive-text"
                >
                    <IconAlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ repurpose.last_error }}
                </p>
            </div>

            <nav
                class="mt-2 flex h-12 shrink-0 items-end gap-4 border-b border-border-strong"
                role="tablist"
                :aria-label="$t('repurposes.show.title')"
            >
                <button
                    v-for="tab in REPURPOSE_TABS"
                    :key="tab"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab"
                    :data-state="activeTab === tab ? 'active' : 'inactive'"
                    :data-testid="`tab-${tab}`"
                    class="relative -mb-px inline-flex h-[45px] shrink-0 cursor-pointer items-center gap-2 px-2 text-sm font-medium transition-control after:absolute after:inset-x-0.5 after:bottom-0 after:h-px after:bg-primary-text after:opacity-0 aria-selected:after:opacity-100"
                    :class="
                        activeTab === tab
                            ? 'text-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="selectTab(tab)"
                >
                    {{ $t(`repurposes.tabs.${tab}`) }}
                </button>
            </nav>

            <div
                v-if="activeTab === 'configuration'"
                class="flex flex-col gap-8 pt-6 md:p-4 md:pt-8"
                role="tabpanel"
                data-testid="repurpose-configuration"
            >
                <section
                    class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                    data-testid="repurpose-source-card"
                >
                    <div class="min-w-0">
                        <h2
                            class="text-base leading-5 font-emphasis text-foreground"
                        >
                            {{ $t('repurposes.source.title') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('repurposes.source.description') }}
                        </p>
                    </div>
                    <div class="w-full min-w-0 md:w-72 md:shrink-0">
                        <div data-testid="source-account-select">
                            <SearchableSelect
                                v-model="sourceAccountId"
                                :options="sourceAccountOptions"
                                :placeholder="
                                    $t('repurposes.create.source_placeholder')
                                "
                                :search-placeholder="
                                    $t('repurposes.create.source_search')
                                "
                                :empty-text="
                                    $t('repurposes.create.source_empty')
                                "
                                :invalid="
                                    Boolean(form.errors.source_social_account_id)
                                "
                            >
                                <template #option="{ option, compact }">
                                    <ChannelAvatar
                                        v-if="compact"
                                        :platform="option.platform"
                                        :name="option.label"
                                        :src="option.avatar"
                                        :size="20"
                                    />
                                    <ChannelAvatar
                                        v-else
                                        :platform="option.platform"
                                        :name="option.label"
                                        :src="option.avatar"
                                        :status="option.status"
                                        :size="32"
                                        ring="popover"
                                        :data-testid="`source-option-${option.value}`"
                                    />

                                    <span v-if="compact" class="truncate">{{
                                        option.label
                                    }}</span>
                                    <span v-else class="min-w-0 text-start">
                                        <span
                                            class="block truncate text-sm leading-tight font-emphasis"
                                            >{{ option.label }}</span
                                        >
                                        <span
                                            v-if="option.disconnected"
                                            class="block truncate text-xs text-destructive-text"
                                            :data-testid="`source-option-disconnected-${option.value}`"
                                        >
                                            {{
                                                $t(
                                                    'repurposes.source.needs_reconnect',
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-else
                                            class="block truncate text-xs text-muted-foreground"
                                        >
                                            {{ getPlatformLabel(option.platform) }}
                                        </span>
                                    </span>
                                </template>
                            </SearchableSelect>
                        </div>
                        <InputError
                            class="mt-2"
                            :message="form.errors.source_social_account_id"
                        />
                    </div>
                </section>

                <hr class="border-border" />

                <section
                    class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                >
                    <div class="min-w-0">
                        <h2
                            class="text-base leading-5 font-emphasis text-foreground"
                        >
                            {{ $t('repurposes.source.watch_label') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('repurposes.source.watch_description') }}
                        </p>
                    </div>
                    <div class="w-full min-w-0 md:w-72 md:shrink-0">
                        <Select v-model="form.source_format">
                            <SelectTrigger
                                class="w-full"
                                data-testid="source-format-select"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in sourceFormats"
                                    :key="option.value"
                                    :value="option.value"
                                    :data-testid="`source-format-option-${option.value}`"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </section>

                <hr class="border-border" />

                <section
                    class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
                    data-testid="repurpose-publish-mode-card"
                >
                    <div class="min-w-0">
                        <h2
                            class="text-base leading-5 font-emphasis text-foreground"
                        >
                            {{ $t('repurposes.publish_mode.title') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('repurposes.publish_mode.description') }}
                        </p>
                    </div>
                    <RadioGroup
                        v-model="form.publish_mode"
                        class="w-full min-w-0 gap-3 md:w-72 md:shrink-0"
                        :aria-label="$t('repurposes.publish_mode.title')"
                    >
                        <label
                            v-for="option in publishModes"
                            :key="option.value"
                            class="flex cursor-pointer items-start gap-3"
                        >
                            <RadioGroupItem
                                :value="option.value"
                                class="mt-0.5"
                                :data-testid="`publish-mode-${option.value}`"
                            />
                            <span class="min-w-0">
                                <span
                                    class="block text-sm leading-5 font-emphasis text-foreground"
                                    >{{ option.label }}</span
                                >
                                <span
                                    class="block text-sm text-muted-foreground"
                                    >{{ option.description }}</span
                                >
                            </span>
                        </label>
                    </RadioGroup>
                </section>

                <hr class="border-border" />

                <section
                    class="flex flex-col gap-4"
                    data-testid="repurpose-destinations"
                >
                    <div class="min-w-0">
                        <h2
                            class="text-base leading-5 font-emphasis text-foreground"
                        >
                            {{ $t('repurposes.destinations.title') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('repurposes.destinations.description') }}
                        </p>
                    </div>

                    <ChannelConfigurator
                        v-if="channels.length > 0"
                        :channels="channels"
                        :media="plannedMedia"
                        :selected-ids="selectedAccountIds"
                        @toggle="toggleDestination"
                        @update:content-type="setDestinationContentType"
                        @update:meta="setDestinationMeta"
                    />
                    <p
                        v-else
                        class="rounded-xl border border-dashed border-border-strong px-4 py-6 text-center text-sm text-muted-foreground"
                        data-testid="destinations-empty"
                    >
                        {{ $t('repurposes.destinations.none_available') }}
                    </p>

                    <InputError
                        data-testid="destinations-error"
                        :message="form.errors.destinations"
                    />
                </section>
            </div>

            <div
                v-else
                class="pt-6 md:p-4 md:pt-8"
                role="tabpanel"
                data-testid="repurpose-activity"
            >
                <EmptyState
                    v-if="(items.data ?? []).length === 0"
                    :title="$t('repurposes.items.empty.title')"
                    :description="$t('repurposes.items.empty.description')"
                    data-testid="repurpose-activity-empty"
                >
                    <template #illustration>
                        <RepurposesEmptyIllustration />
                    </template>
                </EmptyState>

                <div
                    v-else
                    class="rounded-xl border border-border bg-card px-4 py-4"
                >
                    <RepurposeItemList :items="items.data ?? []" />
                </div>
            </div>
        </div>

        <ConfirmDeleteModal
            ref="confirmDeleteModal"
            :title="$t('repurposes.danger.title')"
            :description="$t('repurposes.danger.description')"
            :action="$t('repurposes.danger.delete')"
            :cancel="$t('common.cancel')"
        />
    </AppLayout>
</template>
