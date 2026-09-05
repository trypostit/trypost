<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import PageHeader from '@/components/PageHeader.vue';
import DestinationPicker from '@/components/repurpose/DestinationPicker.vue';
import RepurposeFlow from '@/components/repurpose/RepurposeFlow.vue';
import RepurposeItemList from '@/components/repurpose/RepurposeItemList.vue';
import RepurposeStatusCard from '@/components/repurpose/RepurposeStatusCard.vue';
import RepurposeSummary from '@/components/repurpose/RepurposeSummary.vue';
import SourceFormatCard from '@/components/repurpose/SourceFormatCard.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, update } from '@/routes/app/repurposes';
import type { ChannelAccount } from '@/types/channel';
import type {
    DestinationFormat,
    FlowNode,
    Repurpose,
    RepurposeDestination,
    RepurposeItem,
    RepurposeSourceFormat,
    SourceFormatOption,
} from '@/types/repurpose';

const props = defineProps<{
    repurpose: Repurpose;
    sourceAccounts: ChannelAccount[];
    destinationAccounts: ChannelAccount[];
    items: { data: RepurposeItem[] };
    sourceFormats: SourceFormatOption[];
    destinationFormats: Record<string, DestinationFormat[]>;
}>();

const form = useForm<{ source_format: RepurposeSourceFormat; destinations: RepurposeDestination[] }>({
    source_format: props.repurpose.source_format,
    destinations: props.repurpose.destinations ?? [],
});

const currentFormatLabel = computed(
    () => props.sourceFormats.find((option) => option.value === form.source_format)?.label ?? '',
);

const sourceNode = computed<FlowNode>(() => ({
    platform: props.repurpose.source_account?.platform ?? '',
    label: props.repurpose.source_account?.display_name,
    username: props.repurpose.source_account?.username,
    format: currentFormatLabel.value,
}));

const destinationNodes = computed<FlowNode[]>(() =>
    form.destinations.flatMap((destination) => {
        const account = props.destinationAccounts.find((item) => item.id === destination.social_account_id);

        if (!account) {
            return [];
        }

        return [
            {
                platform: account.platform,
                label: account.display_name,
                username: account.username,
                format: props.destinationFormats[account.id]?.find(
                    (format) => format.value === destination.content_type,
                )?.label,
            },
        ];
    }),
);

const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const save = () => {
    form.put(update.url(props.repurpose.id), { preserveScroll: true });
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
            <PageHeader
                :title="repurpose.source_account?.display_name ?? $t('repurposes.show.title')"
                :description="$t('repurposes.show.description')"
            />

            <RepurposeFlow :source="sourceNode" :destinations="destinationNodes" size="lg" />

            <Tabs default-value="configuration">
                <TabsList>
                    <TabsTrigger value="configuration" data-testid="tab-configuration">
                        {{ $t('repurposes.tabs.configuration') }}
                    </TabsTrigger>
                    <TabsTrigger value="activity" data-testid="tab-activity">
                        {{ $t('repurposes.tabs.activity') }}
                    </TabsTrigger>
                    <TabsTrigger value="settings" data-testid="tab-settings">
                        {{ $t('repurposes.tabs.settings') }}
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="configuration" class="space-y-6">
                    <RepurposeSummary
                        :source-account="repurpose.source_account"
                        :source-format="form.source_format"
                        :format-label="currentFormatLabel"
                        :destinations="form.destinations"
                        :destination-accounts="destinationAccounts"
                    />

                    <RepurposeStatusCard :repurpose="repurpose" />

                    <SourceFormatCard
                        v-model="form.source_format"
                        :account="repurpose.source_account"
                        :formats="sourceFormats"
                    />

                    <Card>
                        <CardHeader>
                            <CardTitle>{{ $t('repurposes.destinations.title') }}</CardTitle>
                            <CardDescription>{{ $t('repurposes.destinations.description') }}</CardDescription>
                        </CardHeader>

                        <CardContent class="space-y-4">
                            <DestinationPicker
                                v-model="form.destinations"
                                :accounts="destinationAccounts"
                                :formats="destinationFormats"
                            />

                            <Button data-testid="save-destinations" :disabled="form.processing" @click="save">
                                {{ $t('repurposes.destinations.save') }}
                            </Button>
                        </CardContent>
                    </Card>

                </TabsContent>

                <TabsContent value="activity">
                    <RepurposeItemList :items="items.data ?? []" />
                </TabsContent>

                <TabsContent value="settings">
                    <Card>
                        <CardHeader>
                            <CardTitle>{{ $t('repurposes.danger.title') }}</CardTitle>
                            <CardDescription>{{ $t('repurposes.danger.description') }}</CardDescription>
                        </CardHeader>

                        <CardContent>
                            <Button variant="destructive" data-testid="delete-repurpose" @click="handleDelete">
                                <IconTrash class="size-4" />
                                {{ $t('repurposes.danger.delete') }}
                            </Button>
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>
        </div>

        <ConfirmDeleteModal ref="confirmDeleteModal" />
    </AppLayout>
</template>
