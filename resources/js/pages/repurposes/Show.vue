<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import PageHeader from '@/components/PageHeader.vue';
import DestinationPicker from '@/components/repurpose/DestinationPicker.vue';
import RepurposeItemList from '@/components/repurpose/RepurposeItemList.vue';
import RepurposeStatusCard from '@/components/repurpose/RepurposeStatusCard.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, update } from '@/routes/app/repurposes';
import type { ChannelAccount } from '@/types/channel';
import type { Repurpose, RepurposeDestination, RepurposeItem } from '@/types/repurpose';

const props = defineProps<{
    repurpose: Repurpose;
    sourceAccounts: ChannelAccount[];
    destinationAccounts: ChannelAccount[];
    items: { data: RepurposeItem[] };
}>();

/**
 * Only one short-video type per network is publishable, so the content type is
 * decided here rather than asked of the user.
 */
const contentTypes: Record<string, string> = {
    instagram: 'instagram_reel',
    'instagram-facebook': 'instagram_reel',
    facebook: 'facebook_reel',
    tiktok: 'tiktok_video',
    youtube: 'youtube_short',
};

const form = useForm<{ destinations: RepurposeDestination[] }>({
    destinations: props.repurpose.destinations ?? [],
});

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
                :title="repurpose.source_account?.display_label ?? $t('repurposes.show.title')"
                :description="$t('repurposes.show.description')"
            />

            <Tabs default-value="configuration">
                <TabsList>
                    <TabsTrigger value="configuration" data-testid="tab-configuration">
                        {{ $t('repurposes.tabs.configuration') }}
                    </TabsTrigger>
                    <TabsTrigger value="activity" data-testid="tab-activity">
                        {{ $t('repurposes.tabs.activity') }}
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="configuration" class="space-y-6">
                    <RepurposeStatusCard :repurpose="repurpose" />

                    <Card>
                        <CardHeader>
                            <CardTitle>{{ $t('repurposes.destinations.title') }}</CardTitle>
                            <CardDescription>{{ $t('repurposes.destinations.description') }}</CardDescription>
                        </CardHeader>

                        <CardContent class="space-y-4">
                            <DestinationPicker
                                v-model="form.destinations"
                                :accounts="destinationAccounts"
                                :content-types="contentTypes"
                            />

                            <Button data-testid="save-destinations" :disabled="form.processing" @click="save">
                                {{ $t('common.save') }}
                            </Button>
                        </CardContent>
                    </Card>

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

                <TabsContent value="activity">
                    <RepurposeItemList :items="items.data ?? []" />
                </TabsContent>
            </Tabs>
        </div>

        <ConfirmDeleteModal ref="confirmDeleteModal" />
    </AppLayout>
</template>
