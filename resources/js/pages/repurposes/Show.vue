<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconAlertTriangle } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import ChannelConfigurator from '@/components/ChannelConfigurator.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import PublishModeCard from '@/components/repurpose/PublishModeCard.vue';
import RepurposeFlow from '@/components/repurpose/RepurposeFlow.vue';
import RepurposeItemList from '@/components/repurpose/RepurposeItemList.vue';
import RepurposeLifecycle from '@/components/repurpose/RepurposeLifecycle.vue';
import RepurposeSummary from '@/components/repurpose/RepurposeSummary.vue';
import SourceFormatCard from '@/components/repurpose/SourceFormatCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { usePageErrors } from '@/composables/usePageErrors';
import AppLayout from '@/layouts/AppLayout.vue';
import { MediaType } from '@/lib/mediaType';
import { destroy, update } from '@/routes/app/repurposes';
import type { PinterestBoard } from '@/types';
import type { Channel, ChannelAccount, ChannelTikTokCreatorInfo } from '@/types/channel';
import type { MediaItem } from '@/types/media';
import type {
    FlowNode,
    PublishModeOption,
    Repurpose,
    RepurposeDestination,
    RepurposeItem,
    RepurposePublishMode,
    RepurposeSourceFormat,
    SourceFormatOption,
} from '@/types/repurpose';
import { repurposeStatusVariant } from '@/types/repurpose-status';

const props = defineProps<{
    repurpose: Repurpose;
    sourceAccounts: ChannelAccount[];
    destinationAccounts: ChannelAccount[];
    items: { data: RepurposeItem[] };
    sourceFormats: SourceFormatOption[];
    publishModes: PublishModeOption[];
    recommendedFormats: Record<string, string>;
    platformConfigs: Record<string, { publishConfig?: Record<string, any> }>;
    pinterestBoards: Record<string, { boards: PinterestBoard[]; truncated: boolean }>;
    tiktokCreatorInfos: Record<string, ChannelTikTokCreatorInfo | null>;
}>();

const availableAccountIds = new Set(props.destinationAccounts.map((account) => account.id));

const form = useForm<{
    source_social_account_id: string;
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

/**
 * The destinations are configured before there is anything to publish, so the
 * media rules would read "no files" and warn about every video-only format. A
 * repurpose always attaches exactly one video, which is what they get to judge.
 */
const plannedMedia = computed<MediaItem[]>(() => [
    { id: 'repurpose-video', url: '', type: MediaType.Video },
]);

/** Whatever the source becomes cannot also receive, so it leaves the list. */
const destinationAccounts = computed(() =>
    props.destinationAccounts.filter((account) => account.id !== form.source_social_account_id),
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
        const index = form.destinations.findIndex((item) => item.social_account_id === account.id);
        const destination = form.destinations[index];

        return {
            id: account.id,
            platform: account.platform,
            displayName: account.display_name,
            username: account.username ?? null,
            avatarUrl: account.avatar_url,
            socialAccount: account,
            contentType: destination?.content_type ?? props.recommendedFormats[account.id] ?? '',
            meta: destination?.meta ?? {},
            boards: props.pinterestBoards?.[account.id]?.boards ?? [],
            boardsTruncated: props.pinterestBoards?.[account.id]?.truncated ?? false,
            creatorInfo: props.tiktokCreatorInfos?.[account.id] ?? null,
            publishConfig: props.platformConfigs?.[account.id]?.publishConfig ?? {},
            contentTypeError: errors.value[`destinations.${index}.content_type`],
        };
    }),
);

const selectedAccountIds = computed(() => form.destinations.map((destination) => destination.social_account_id));

const toggleDestination = (accountId: string) => {
    if (selectedAccountIds.value.includes(accountId)) {
        form.destinations = form.destinations.filter((destination) => destination.social_account_id !== accountId);

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

const updateDestination = (accountId: string, changes: Partial<RepurposeDestination>) => {
    form.destinations = form.destinations.map((destination) =>
        destination.social_account_id === accountId ? { ...destination, ...changes } : destination,
    );
};

const setDestinationContentType = (accountId: string, contentType: string) =>
    updateDestination(accountId, { content_type: contentType });

const setDestinationMeta = (accountId: string, meta: Record<string, any>) =>
    updateDestination(accountId, { meta });

/** The account the header describes is the one being chosen, not the saved one. */
const selectedSourceAccount = computed(
    () =>
        props.sourceAccounts.find((account) => account.id === form.source_social_account_id)
        ?? props.repurpose.source_account,
);

const flowSource = computed<FlowNode>(() => ({
    platform: selectedSourceAccount.value?.platform ?? '',
    label: selectedSourceAccount.value?.display_name,
    username: selectedSourceAccount.value?.username,
}));

const flowDestinations = computed<FlowNode[]>(() =>
    form.destinations.flatMap((destination) => {
        const account = props.destinationAccounts.find((item) => item.id === destination.social_account_id);

        return account
            ? [{ platform: account.platform, label: account.display_name, username: account.username }]
            : [];
    }),
);

const currentFormatLabel = computed(
    () => props.sourceFormats.find((option) => option.value === form.source_format)?.label ?? '',
);

const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const save = () => {
    form.put(update.url(props.repurpose.id), {
        preserveScroll: true,
        onSuccess: () => toast.success(trans('repurposes.destinations.saved')),
        onError: () => toast.error(trans('repurposes.errors.action_failed')),
    });
};

const handleDelete = () => {
    confirmDeleteModal.value?.open({
        url: destroy.url(props.repurpose.id),
        confirmText: trans('common.confirm_modal.delete_keyword'),
    });
};
</script>

<template>
    <Head :title="$t('repurposes.show.title')" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <header class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center gap-3">
                            <h1
                                class="text-2xl font-semibold leading-tight text-foreground sm:text-4xl"
                                style="font-family: var(--font-display)"
                            >
                                {{ $t('repurposes.show.title') }}
                            </h1>

                            <Badge :variant="repurposeStatusVariant(repurpose.status)">
                                {{ $t(`repurposes.status.${repurpose.status}`) }}
                            </Badge>
                        </div>

                        <RepurposeSummary
                            :source-account="selectedSourceAccount"
                            :format-label="currentFormatLabel"
                            :destinations="form.destinations"
                            :destination-accounts="destinationAccounts"
                        />
                    </div>

                    <RepurposeLifecycle :repurpose="repurpose" @delete="handleDelete" />
                </div>

                <p
                    v-if="repurpose.last_error"
                    class="flex items-start gap-2 rounded-lg border-2 border-foreground bg-rose-50 p-2 text-xs font-semibold text-rose-700"
                >
                    <IconAlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                    {{ repurpose.last_error }}
                </p>

                <Card>
                    <CardContent class="py-6">
                        <RepurposeFlow :source="flowSource" :destinations="flowDestinations" size="lg" />
                    </CardContent>
                </Card>
            </header>

            <Tabs default-value="configuration">
                <TabsList>
                    <TabsTrigger value="configuration" data-testid="tab-configuration">
                        {{ $t('repurposes.tabs.configuration') }}
                    </TabsTrigger>
                    <TabsTrigger value="activity" data-testid="tab-activity">
                        {{ $t('repurposes.tabs.activity') }}
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="configuration">
                    <div class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] lg:items-start">
                        <div class="space-y-4 lg:sticky lg:top-6">
                            <SourceFormatCard
                                v-model:account="form.source_social_account_id"
                                v-model:format="form.source_format"
                                :accounts="sourceAccounts"
                                :formats="sourceFormats"
                                :error="form.errors.source_social_account_id"
                            />

                            <PublishModeCard v-model="form.publish_mode" :modes="publishModes" />
                        </div>

                        <div class="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>{{ $t('repurposes.destinations.title') }}</CardTitle>
                                    <CardDescription>{{ $t('repurposes.destinations.description') }}</CardDescription>
                                </CardHeader>

                                <CardContent>
                                    <ChannelConfigurator
                                        :channels="channels"
                                        :media="plannedMedia"
                                        :selected-ids="selectedAccountIds"
                                        @toggle="toggleDestination"
                                        @update:content-type="setDestinationContentType"
                                        @update:meta="setDestinationMeta"
                                    />
                                </CardContent>
                            </Card>

                            <div v-if="form.isDirty" class="sticky bottom-4 flex justify-end">
                                <Button data-testid="save-destinations" :disabled="form.processing" @click="save">
                                    {{ $t('repurposes.destinations.save') }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </TabsContent>

                <TabsContent value="activity">
                    <RepurposeItemList :items="items.data ?? []" />
                </TabsContent>


            </Tabs>
        </div>

        <ConfirmDeleteModal ref="confirmDeleteModal" />
    </AppLayout>
</template>
