<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCalendar,
    IconCrop,
    IconLibraryPhoto,
    IconLoader2,
    IconTrash,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import ImageCropperDialog from '@/components/ImageCropperDialog.vue';
import DiscordSettings from '@/components/posts/editor/DiscordSettings.vue';
import FacebookSettings from '@/components/posts/editor/FacebookSettings.vue';
import GoogleBusinessSettings from '@/components/posts/editor/GoogleBusinessSettings.vue';
import InstagramSettings from '@/components/posts/editor/InstagramSettings.vue';
import LinkedInSettings from '@/components/posts/editor/LinkedInSettings.vue';
import PinterestSettings from '@/components/posts/editor/PinterestSettings.vue';
import TikTokSettings from '@/components/posts/editor/TikTokSettings.vue';
import MediaPickerDialog from '@/components/posts/MediaPickerDialog.vue';
import PlatformPreview from '@/components/posts/previews/PlatformPreview.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { usePageErrors } from '@/composables/usePageErrors';
import {
    getContentTypeOptions,
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import {
    usePostComposition,
    type ComposerAccount,
    type ComposerInitialPost,
    type PostComposition,
} from '@/composables/usePostComposition';
import { useXLinkDefuser } from '@/composables/useXLinkDefuser';
import date from '@/date';
import { isImage } from '@/lib/mediaType';
import { storeChunked as assetsStoreChunked } from '@/routes/app/assets';
import { create as createPost } from '@/routes/app/posts';
import type { PinterestBoardsPayload } from '@/types';
import type { MediaItem } from '@/types/media';
import type { TikTokPrivacyLevelValue } from '@/types/tiktok-privacy';
import { uploadChunked } from '@/utils/chunkedUpload';

const props = withDefaults(
    defineProps<{
        open: boolean;
        socialAccounts: ComposerAccount[];
        initialPost?: ComposerInitialPost | null;
        initialDate?: string | null;
        submitting?: boolean;
        platformConfigs?: Record<string, any>;
        pinterestBoards?: Record<string, PinterestBoardsPayload>;
        tiktokCreatorInfos?: Record<
            string,
            {
                creator_nickname: string | null;
                creator_username: string | null;
                creator_avatar_url: string | null;
                privacy_level_options: TikTokPrivacyLevelValue[];
                comment_disabled: boolean;
                duet_disabled: boolean;
                stitch_disabled: boolean;
                max_video_post_duration_sec: number | null;
            }
        >;
    }>(),
    {
        initialPost: null,
        initialDate: null,
        submitting: false,
        platformConfigs: () => ({}),
        pinterestBoards: () => ({}),
        tiktokCreatorInfos: () => ({}),
    },
);

const emit = defineEmits<{
    (event: 'update:open', value: boolean): void;
    (event: 'submit', composition: PostComposition): void;
}>();

const composition = usePostComposition(
    () => props.socialAccounts,
    props.initialPost,
);
if (
    !props.initialPost &&
    props.initialDate &&
    /^\d{4}-\d{2}-\d{2}$/.test(props.initialDate)
) {
    composition.scheduledAt.value = `${props.initialDate}T09:00`;
}
const { contentFor } = useXLinkDefuser();
const errors = usePageErrors();
const step = ref<1 | 2>(props.initialPost ? 2 : 1);
const expandedAccountId = ref<string | null>(
    props.initialPost?.social_account_id ?? null,
);
const previewAccountId = ref<string | null>(
    props.initialPost?.social_account_id ?? null,
);
const mediaPicker = ref<InstanceType<typeof MediaPickerDialog> | null>(null);
const pickingForAccountId = ref<string | null>(null);
const cropping = ref(false);
const cropUploading = ref(false);
const cropTarget = ref<{
    accountId: string;
    index: number;
    media: MediaItem;
} | null>(null);
const cropError = ref(false);

const selectedAccounts = composition.selectedAccounts;
const previewAccount = computed(
    () =>
        selectedAccounts.value.find(
            (account) => account.id === previewAccountId.value,
        ) ?? selectedAccounts.value[0],
);
const previewDestination = computed(() =>
    previewAccount.value
        ? composition.resolvedDestination(previewAccount.value)
        : null,
);
const expandedAccount = computed(() =>
    selectedAccounts.value.find(
        (account) => account.id === expandedAccountId.value,
    ),
);
const expandedDestination = computed(() =>
    expandedAccount.value
        ? composition.resolvedDestination(expandedAccount.value)
        : null,
);
const expandedOverride = computed(() =>
    expandedAccountId.value
        ? (composition.overrides.value[expandedAccountId.value] ?? {})
        : {},
);
const canSubmit = computed(
    () =>
        selectedAccounts.value.length > 0 &&
        !props.submitting &&
        !cropUploading.value,
);
const canCrop = (item: MediaItem): boolean =>
    isImage(item) &&
    ['image/jpeg', 'image/png', 'image/webp'].includes(item.mime_type ?? '');
const videoDurationSec = computed(
    () =>
        Math.ceil(
            expandedDestination.value?.media.find(
                (item) => item.type === 'video',
            )?.meta?.duration ?? 0,
        ) || null,
);

const selectAccount = (account: ComposerAccount): void => {
    composition.toggleAccount(account.id);
    if (!composition.selectedAccountIds.value.includes(account.id)) {
        if (expandedAccountId.value === account.id)
            expandedAccountId.value = null;
        if (previewAccountId.value === account.id)
            previewAccountId.value =
                composition.selectedAccountIds.value[0] ?? null;
    } else {
        previewAccountId.value = account.id;
    }
};

const openMediaPicker = (accountId: string | null): void => {
    pickingForAccountId.value = accountId;
    mediaPicker.value?.open();
};

const onMediaPicked = (items: MediaItem[]): void => {
    if (pickingForAccountId.value) {
        const account = selectedAccounts.value.find(
            (selected) => selected.id === pickingForAccountId.value,
        );
        if (!account) return;

        composition.setOverride(pickingForAccountId.value, 'media', [
            ...composition.resolvedDestination(account).media,
            ...items,
        ]);
    } else {
        composition.media.value = [...composition.media.value, ...items];
    }
};

const removeMedia = (accountId: string | null, index: number): void => {
    if (accountId) {
        composition.setOverride(
            accountId,
            'media',
            (expandedDestination.value?.media ?? []).filter(
                (_, itemIndex) => itemIndex !== index,
            ),
        );
    } else {
        composition.media.value = composition.media.value.filter(
            (_, itemIndex) => itemIndex !== index,
        );
    }
};

const beginCrop = (accountId: string, index: number, item: MediaItem): void => {
    if (!canCrop(item)) return;
    cropError.value = false;
    cropTarget.value = { accountId, index, media: item };
    cropping.value = true;
};

const cropDimensions = computed(() => {
    const type = expandedDestination.value?.content_type ?? '';
    if (type.endsWith('_story') || type.endsWith('_reel'))
        return { width: 540, height: 960 };
    if (type === 'instagram_feed') return { width: 864, height: 1080 };
    if (type.startsWith('pinterest_')) return { width: 720, height: 1080 };
    return { width: 1080, height: 1080 };
});

const onCropped = async (file: File): Promise<void> => {
    const target = cropTarget.value;
    if (!target) return;
    cropUploading.value = true;
    cropError.value = false;
    try {
        const uploaded = await uploadChunked({
            file,
            url: assetsStoreChunked.url(),
            collection: 'assets',
        });
        if (!uploaded.id || !uploaded.path || !uploaded.url)
            throw new Error('Incomplete asset upload');
        const account = selectedAccounts.value.find(
            (candidate) => candidate.id === target.accountId,
        );
        if (!account) return;
        const items = [...composition.resolvedDestination(account).media];
        if (items[target.index]?.id !== target.media.id) return;
        items[target.index] = {
            id: uploaded.id,
            path: uploaded.path,
            url: uploaded.url,
            type: uploaded.type,
            mime_type: uploaded.mime_type ?? file.type,
            original_filename: uploaded.original_filename,
            size: uploaded.size,
            meta: {
                ...(target.media.meta ?? {}),
                width: cropDimensions.value.width,
                height: cropDimensions.value.height,
            },
        };
        composition.setOverride(target.accountId, 'media', items);
    } catch {
        cropError.value = true;
        toast.error(trans('posts.composer.crop_upload_failed'));
    } finally {
        cropUploading.value = false;
        cropTarget.value = null;
    }
};

const goToCustomization = (): void => {
    if (!selectedAccounts.value.length) return;
    step.value = 2;
    expandedAccountId.value ??= selectedAccounts.value[0].id;
    previewAccountId.value ??= selectedAccounts.value[0].id;
};

const submit = (status: PostComposition['status']): void => {
    if (!canSubmit.value) return;
    const payload = composition.materialize(status);
    if (status === 'scheduled') {
        payload.scheduled_at = date.formatLocalDateTimeForApi(
            composition.scheduledAt.value,
        );
        if (!payload.scheduled_at) return;
    }
    emit('submit', payload);
};

const close = (): void => emit('update:open', false);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="flex h-[min(90dvh,900px)] max-w-[calc(100%-1rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-6xl"
            :show-close-button="false"
            data-testid="post-composer-dialog"
        >
            <DialogHeader
                class="flex-row items-center justify-between border-b px-5 py-4"
            >
                <div class="flex items-center gap-3">
                    <Button
                        v-if="step === 2 && !initialPost"
                        type="button"
                        variant="ghost"
                        size="icon"
                        data-testid="composer-back"
                        @click="step = 1"
                        ><IconArrowLeft class="size-4"
                    /></Button>
                    <DialogTitle>{{
                        initialPost
                            ? $t('posts.edit.title')
                            : $t('posts.create.title')
                    }}</DialogTitle>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="!initialPost"
                        :href="createPost.url({ query: { ai: '1' } })"
                        class="text-sm font-semibold underline underline-offset-2"
                        >{{ $t('posts.create.ai_title') }}</Link
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="close"
                        >{{ $t('posts.edit.cancel') }}</Button
                    >
                </div>
            </DialogHeader>

            <div
                class="grid min-h-0 flex-1 md:grid-cols-[minmax(0,1fr)_minmax(280px,38%)]"
            >
                <div class="min-h-0 space-y-5 overflow-y-auto p-5">
                    <p
                        v-if="Object.keys(errors).length"
                        data-testid="composer-errors"
                        class="rounded-lg border border-destructive bg-destructive/10 p-3 text-sm text-destructive"
                    >
                        {{ Object.values(errors)[0] }}
                    </p>
                    <template v-if="step === 1">
                        <div>
                            <p class="mb-2 text-sm font-semibold">
                                {{ $t('posts.edit.platforms_dialog.title') }}
                            </p>
                            <div
                                class="flex flex-wrap gap-2"
                                data-testid="composer-accounts"
                            >
                                <button
                                    v-for="account in socialAccounts"
                                    :key="account.id"
                                    type="button"
                                    :data-testid="`composer-account-${account.id}`"
                                    :aria-pressed="
                                        composition.selectedAccountIds.value.includes(
                                            account.id,
                                        )
                                    "
                                    class="flex items-center gap-2 rounded-lg border-2 px-2 py-1 text-sm"
                                    :class="
                                        composition.selectedAccountIds.value.includes(
                                            account.id,
                                        )
                                            ? 'border-foreground bg-primary/15'
                                            : 'border-border'
                                    "
                                    @click="selectAccount(account)"
                                >
                                    <img
                                        :src="
                                            account.avatar_url ||
                                            getPlatformLogo(account.platform)
                                        "
                                        alt=""
                                        class="size-7 rounded-full object-cover"
                                    />
                                    <span>{{
                                        account.display_name || account.username
                                    }}</span>
                                    <img
                                        :src="getPlatformLogo(account.platform)"
                                        :alt="
                                            getPlatformLabel(account.platform)
                                        "
                                        class="size-4"
                                    />
                                </button>
                            </div>
                        </div>
                        <textarea
                            v-model="composition.content.value"
                            data-testid="composer-base-content"
                            class="min-h-40 w-full resize-y rounded-xl border-2 border-border bg-card p-4 outline-none focus:border-foreground"
                            :placeholder="
                                $t('posts.create.steps.prompt_placeholder')
                            "
                        />
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                data-testid="composer-base-media"
                                @click="openMediaPicker(null)"
                                ><IconLibraryPhoto class="size-4" />{{
                                    $t('posts.edit.media_picker.add')
                                }}</Button
                            >
                            <div class="mt-3 flex flex-wrap gap-3">
                                <div
                                    v-for="(item, index) in composition.media
                                        .value"
                                    :key="`${item.id}-${index}`"
                                    class="relative size-24 overflow-hidden rounded-lg border"
                                >
                                    <img
                                        v-if="isImage(item)"
                                        :src="item.url"
                                        alt=""
                                        class="size-full object-cover"
                                    />
                                    <span v-else class="p-2 text-xs">{{
                                        item.original_filename
                                    }}</span>
                                    <button
                                        type="button"
                                        class="absolute top-1 right-1 rounded bg-card p-1"
                                        :aria-label="$t('posts.edit.delete')"
                                        @click="removeMedia(null, index)"
                                    >
                                        <IconTrash class="size-3" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="space-y-2">
                            <button
                                v-for="account in selectedAccounts"
                                :key="account.id"
                                type="button"
                                :data-testid="`composer-expand-${account.id}`"
                                class="flex w-full items-center gap-3 rounded-xl border-2 p-3 text-left"
                                :class="
                                    expandedAccountId === account.id
                                        ? 'border-foreground bg-primary/10'
                                        : 'border-border'
                                "
                                @click="
                                    expandedAccountId = account.id;
                                    previewAccountId = account.id;
                                "
                            >
                                <img
                                    :src="
                                        account.avatar_url ||
                                        getPlatformLogo(account.platform)
                                    "
                                    alt=""
                                    class="size-9 rounded-full object-cover"
                                />
                                <span class="flex-1"
                                    ><strong class="block text-sm">{{
                                        account.display_name || account.username
                                    }}</strong
                                    ><small>{{
                                        getPlatformLabel(account.platform)
                                    }}</small></span
                                >
                                <span class="text-xs">{{
                                    expandedAccountId === account.id ? '−' : '+'
                                }}</span>
                            </button>
                        </div>
                        <div
                            v-if="expandedAccount && expandedDestination"
                            :key="expandedAccount.id"
                            class="space-y-4 rounded-xl border p-4"
                            data-testid="composer-customization"
                        >
                            <div
                                v-if="
                                    getContentTypeOptions(
                                        expandedAccount.platform,
                                    ).length > 1
                                "
                            >
                                <label
                                    class="mb-1 block text-sm font-semibold"
                                    :for="`composer-type-${expandedAccount.id}`"
                                    >{{
                                        $t('posts.create.steps.format_title')
                                    }}</label
                                >
                                <select
                                    :id="`composer-type-${expandedAccount.id}`"
                                    :value="expandedDestination.content_type"
                                    :data-testid="`composer-type-${expandedAccount.id}`"
                                    class="w-full rounded-lg border p-2"
                                    @change="
                                        composition.setOverride(
                                            expandedAccount.id,
                                            'content_type',
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option
                                        v-for="option in getContentTypeOptions(
                                            expandedAccount.platform,
                                        )"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ $t(option.labelKey) }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <div
                                    class="mb-1 flex items-center justify-between"
                                >
                                    <label
                                        class="text-sm font-semibold"
                                        :for="`composer-caption-${expandedAccount.id}`"
                                        >{{ $t('posts.edit.caption') }}</label
                                    ><Button
                                        v-if="
                                            Object.hasOwn(
                                                expandedOverride,
                                                'content',
                                            )
                                        "
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            composition.clearOverride(
                                                expandedAccount.id,
                                                'content',
                                            )
                                        "
                                        >{{
                                            $t('posts.composer.use_shared')
                                        }}</Button
                                    >
                                </div>
                                <textarea
                                    :id="`composer-caption-${expandedAccount.id}`"
                                    :value="expandedDestination.content"
                                    :data-testid="`composer-caption-${expandedAccount.id}`"
                                    class="min-h-28 w-full resize-y rounded-lg border p-3"
                                    @input="
                                        composition.setOverride(
                                            expandedAccount.id,
                                            'content',
                                            (
                                                $event.target as HTMLTextAreaElement
                                            ).value,
                                        )
                                    "
                                />
                                <p
                                    v-if="expandedAccount.platform === 'x'"
                                    data-testid="composer-x-count"
                                    class="text-right text-xs text-muted-foreground"
                                >
                                    {{
                                        contentFor(
                                            expandedDestination.content,
                                            expandedAccount.platform,
                                        ).length
                                    }}
                                </p>
                            </div>
                            <div>
                                <div
                                    class="mb-2 flex items-center justify-between"
                                >
                                    <span class="text-sm font-semibold">{{
                                        $t('posts.create.steps.media_title')
                                    }}</span
                                    ><Button
                                        v-if="
                                            Object.hasOwn(
                                                expandedOverride,
                                                'media',
                                            )
                                        "
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            composition.clearOverride(
                                                expandedAccount.id,
                                                'media',
                                            )
                                        "
                                        >{{
                                            $t('posts.composer.use_shared')
                                        }}</Button
                                    >
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    :data-testid="`composer-media-${expandedAccount.id}`"
                                    @click="openMediaPicker(expandedAccount.id)"
                                    ><IconLibraryPhoto class="size-4" />{{
                                        $t('posts.edit.media_picker.add')
                                    }}</Button
                                >
                                <div class="mt-3 flex flex-wrap gap-3">
                                    <div
                                        v-for="(
                                            item, index
                                        ) in expandedDestination.media"
                                        :key="`${item.id}-${index}`"
                                        class="relative size-28 overflow-hidden rounded-lg border"
                                    >
                                        <img
                                            v-if="isImage(item)"
                                            :src="item.url"
                                            alt=""
                                            class="size-full object-cover"
                                        />
                                        <span v-else class="p-2 text-xs">{{
                                            item.original_filename
                                        }}</span>
                                        <div
                                            class="absolute bottom-0 flex w-full justify-end gap-1 bg-card/90 p-1"
                                        >
                                            <button
                                                v-if="canCrop(item)"
                                                type="button"
                                                :data-testid="`composer-crop-${expandedAccount.id}-${index}`"
                                                aria-label="Crop"
                                                :disabled="cropUploading"
                                                @click="
                                                    beginCrop(
                                                        expandedAccount.id,
                                                        index,
                                                        item,
                                                    )
                                                "
                                            >
                                                <IconCrop class="size-4" />
                                            </button>
                                            <button
                                                type="button"
                                                :aria-label="
                                                    $t('posts.edit.delete')
                                                "
                                                @click="
                                                    removeMedia(
                                                        expandedAccount.id,
                                                        index,
                                                    )
                                                "
                                            >
                                                <IconTrash class="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <p
                                    v-if="cropError"
                                    class="mt-2 text-sm text-destructive"
                                >
                                    {{
                                        $t('posts.composer.crop_upload_failed')
                                    }}
                                </p>
                            </div>
                            <InstagramSettings
                                v-if="
                                    [
                                        'instagram',
                                        'instagram-facebook',
                                    ].includes(expandedAccount.platform)
                                "
                                :social-account="expandedAccount"
                                :content-type="expandedDestination.content_type"
                                :media="expandedDestination.media"
                                :meta="expandedDestination.meta"
                                @update:content-type="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'content_type',
                                        $event,
                                    )
                                "
                                @update:meta="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'meta',
                                        $event,
                                    )
                                "
                            />
                            <FacebookSettings
                                v-else-if="
                                    expandedAccount.platform === 'facebook'
                                "
                                :social-account="expandedAccount"
                                :content-type="expandedDestination.content_type"
                                :media="expandedDestination.media"
                                :meta="expandedDestination.meta"
                                @update:content-type="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'content_type',
                                        $event,
                                    )
                                "
                                @update:meta="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'meta',
                                        $event,
                                    )
                                "
                            />
                            <TikTokSettings
                                v-else-if="
                                    expandedAccount.platform === 'tiktok'
                                "
                                :social-account="expandedAccount"
                                :publish-config="
                                    platformConfigs[expandedAccount.id]
                                        ?.publishConfig ?? null
                                "
                                :creator-info="
                                    tiktokCreatorInfos[expandedAccount.id] ??
                                    null
                                "
                                :video-duration-sec="videoDurationSec"
                                :content-type="expandedDestination.content_type"
                                :meta="expandedDestination.meta"
                                @update:content-type="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'content_type',
                                        $event,
                                    )
                                "
                                @update:meta="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'meta',
                                        $event,
                                    )
                                "
                            />
                            <PinterestSettings
                                v-else-if="
                                    expandedAccount.platform === 'pinterest'
                                "
                                :social-account="expandedAccount"
                                :content-type="expandedDestination.content_type"
                                :media="expandedDestination.media"
                                :boards="
                                    pinterestBoards[expandedAccount.id]
                                        ?.boards ?? []
                                "
                                :boards-truncated="
                                    pinterestBoards[expandedAccount.id]
                                        ?.truncated ?? false
                                "
                                :meta="expandedDestination.meta"
                                @update:content-type="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'content_type',
                                        $event,
                                    )
                                "
                                @update:meta="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'meta',
                                        $event,
                                    )
                                "
                            />
                            <LinkedInSettings
                                v-else-if="
                                    ['linkedin', 'linkedin-page'].includes(
                                        expandedAccount.platform,
                                    )
                                "
                                :social-account="expandedAccount"
                                :platform="expandedAccount.platform"
                                :media="expandedDestination.media"
                                :meta="expandedDestination.meta"
                                @update:meta="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'meta',
                                        $event,
                                    )
                                "
                            />
                            <GoogleBusinessSettings
                                v-else-if="
                                    expandedAccount.platform ===
                                    'google_business'
                                "
                                :social-account="expandedAccount"
                                :platform-index="0"
                                :meta="expandedDestination.meta"
                                @update:meta="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'meta',
                                        $event,
                                    )
                                "
                            />
                            <DiscordSettings
                                v-else-if="
                                    expandedAccount.platform === 'discord'
                                "
                                :social-account="expandedAccount"
                                :meta="expandedDestination.meta"
                                @update:meta="
                                    composition.setOverride(
                                        expandedAccount.id,
                                        'meta',
                                        $event,
                                    )
                                "
                            />
                        </div>
                    </template>
                </div>

                <aside
                    class="hidden min-h-0 overflow-y-auto border-l bg-muted/30 p-5 md:block"
                >
                    <h3 class="mb-4 text-sm font-semibold">
                        {{ $t('posts.edit.tabs.preview') }}
                    </h3>
                    <PlatformPreview
                        v-if="previewAccount && previewDestination"
                        :platform="previewAccount.platform"
                        :social-account="previewAccount"
                        :content="previewDestination.content"
                        :media="previewDestination.media"
                        :content-type="previewDestination.content_type"
                        :meta="previewDestination.meta"
                    />
                </aside>
            </div>

            <DialogFooter
                class="flex-col gap-3 border-t px-5 py-3 sm:flex-row sm:items-center"
            >
                <div v-if="step === 2" class="flex flex-1 items-center gap-2">
                    <IconCalendar class="size-4" />
                    <input
                        v-model="composition.scheduledAt.value"
                        type="datetime-local"
                        data-testid="composer-scheduled-at"
                        class="min-w-0 rounded-lg border px-2 py-1 text-sm"
                    />
                </div>
                <div v-else class="flex-1" />
                <Button
                    v-if="step === 1"
                    type="button"
                    data-testid="composer-next"
                    :disabled="selectedAccounts.length === 0"
                    @click="goToCustomization"
                    >{{ $t('posts.create.steps.next') }}</Button
                >
                <template v-else>
                    <Button
                        type="button"
                        variant="outline"
                        data-testid="composer-save-draft"
                        :disabled="!canSubmit"
                        @click="submit('draft')"
                        >{{ $t('posts.composer.save_draft') }}</Button
                    >
                    <Button
                        type="button"
                        data-testid="composer-submit"
                        :disabled="!canSubmit || !composition.scheduledAt.value"
                        @click="submit('scheduled')"
                        ><IconLoader2
                            v-if="submitting || cropUploading"
                            class="size-4 animate-spin"
                        />{{ $t('posts.edit.schedule') }}</Button
                    >
                </template>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <MediaPickerDialog ref="mediaPicker" @select="onMediaPicked" />
    <ImageCropperDialog
        v-model:open="cropping"
        :src="cropTarget?.media.url ?? null"
        :file-name="cropTarget?.media.original_filename ?? 'image.png'"
        :mime-type="cropTarget?.media.mime_type ?? 'image/png'"
        :output-width="cropDimensions.width"
        :output-height="cropDimensions.height"
        @cropped="onCropped"
    />
</template>
