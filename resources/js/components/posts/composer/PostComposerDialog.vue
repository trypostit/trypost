<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCalendar,
    IconChevronDown,
    IconCrop,
    IconLibraryPhoto,
    IconLoader2,
    IconMaximize,
    IconMessageCircle,
    IconPlus,
    IconSparkles,
    IconTrash,
    IconX,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import ImageCropperDialog from '@/components/ImageCropperDialog.vue';
import AiRegenerateImageDialog from '@/components/posts/ai/AiRegenerateImageDialog.vue';
import CommentsTab from '@/components/posts/editor/CommentsTab.vue';
import DiscordSettings from '@/components/posts/editor/DiscordSettings.vue';
import FacebookSettings from '@/components/posts/editor/FacebookSettings.vue';
import GoogleBusinessSettings from '@/components/posts/editor/GoogleBusinessSettings.vue';
import InstagramSettings from '@/components/posts/editor/InstagramSettings.vue';
import LinkedInSettings from '@/components/posts/editor/LinkedInSettings.vue';
import PinterestSettings from '@/components/posts/editor/PinterestSettings.vue';
import TikTokSettings from '@/components/posts/editor/TikTokSettings.vue';
import MediaPickerDialog from '@/components/posts/MediaPickerDialog.vue';
import PickTimePopover from '@/components/posts/PickTimePopover.vue';
import PlatformPreview from '@/components/posts/previews/PlatformPreview.vue';
import SignaturesModal from '@/components/posts/SignaturesModal.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { usePageErrors } from '@/composables/usePageErrors';
import {
    getContentTypeOptions,
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import {
    usePostComposition,
    type ComposerAccount,
    type ComposerInitialDraft,
    type ComposerInitialPost,
    type PostComposition,
} from '@/composables/usePostComposition';
import { useXLinkDefuser } from '@/composables/useXLinkDefuser';
import date from '@/date';
import { isImage } from '@/lib/mediaType';
import { storeChunked as assetsStoreChunked } from '@/routes/app/assets';
import { assist as assistPostAi } from '@/routes/app/posts/ai';
import type { PinterestBoardsPayload } from '@/types';
import type { MediaItem } from '@/types/media';
import type { TikTokPrivacyLevelValue } from '@/types/tiktok-privacy';
import { uploadChunked } from '@/utils/chunkedUpload';

const props = withDefaults(
    defineProps<{
        open: boolean;
        socialAccounts: ComposerAccount[];
        initialPost?: ComposerInitialPost | null;
        initialDraft?: ComposerInitialDraft | null;
        postId?: string | null;
        currentUserId?: string | null;
        openComments?: boolean;
        openAssistant?: boolean;
        highlightCommentId?: string | null;
        labels?: { id: string; name: string; color: string }[];
        signatures?: { id: string; name: string; content: string }[];
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
        initialDraft: null,
        postId: null,
        currentUserId: null,
        openComments: false,
        openAssistant: false,
        highlightCommentId: null,
        labels: () => [],
        signatures: () => [],
        initialDate: null,
        submitting: false,
        platformConfigs: () => ({}),
        pinterestBoards: () => ({}),
        tiktokCreatorInfos: () => ({}),
    },
);

const emit = defineEmits<{
    (event: 'update:open', value: boolean): void;
    (
        event: 'submit',
        composition: PostComposition,
        createAnother: boolean,
    ): void;
}>();

const composition = usePostComposition(
    () => props.socialAccounts,
    props.initialPost,
    props.initialDraft,
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
const previewVisible = ref(true);
const assistantOpen = ref(props.openAssistant);
const assistantScreen = ref<'actions' | 'prompt' | 'suggestion'>('actions');
const assistantMode = ref<'write_more' | 'rephrase' | 'shorten' | 'expand'>(
    'write_more',
);
const assistantPrompt = ref('');
const assistantSuggestion = ref('');
const assistantError = ref('');
const assistantBusy = ref(false);
const assistantHttp = useHttp({ mode: '', current_content: '', prompt: '' });
const expandedDialog = ref(false);
const accountPickerOpen = ref(false);
const scheduleMenuOpen = ref(false);
const timePickerOpen = ref(false);
const createAnother = ref(false);
const scheduleMode = ref<'now' | 'custom'>(
    composition.scheduledAt.value ? 'custom' : 'now',
);
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
const commentsOpen = ref(props.openComments);
const aiRegenerateOpen = ref(false);
const aiMediaTarget = ref<{
    accountId: string;
    index: number;
    media: MediaItem;
} | null>(null);
const persistedMediaIds = new Set(
    (props.initialPost?.media ?? props.initialDraft?.media ?? []).map(
        (item) => item.id,
    ),
);
const signaturesModal = ref<InstanceType<typeof SignaturesModal> | null>(null);
const signatureTargetId = ref<string | null>(null);

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
const assistantContent = computed(() =>
    step.value === 2 && expandedDestination.value
        ? expandedDestination.value.content
        : composition.content.value,
);
const canSubmit = computed(
    () =>
        selectedAccounts.value.length > 0 &&
        !props.submitting &&
        !cropUploading.value,
);
const scheduleLabel = computed(() =>
    scheduleMode.value === 'custom' && composition.scheduledAt.value
        ? date.formatLocalDateTime(composition.scheduledAt.value)
        : trans('posts.composer.now'),
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

const toggleLabel = (id: string): void => {
    composition.labelIds.value = composition.labelIds.value.includes(id)
        ? composition.labelIds.value.filter((selected) => selected !== id)
        : [...composition.labelIds.value, id];
};

const openSignatures = (accountId: string | null): void => {
    signatureTargetId.value = accountId;
    signaturesModal.value?.open();
};

const appendSignature = (signature: { content: string }): void => {
    const account = selectedAccounts.value.find(
        (selected) => selected.id === signatureTargetId.value,
    );
    const current = account
        ? composition.resolvedDestination(account).content
        : composition.content.value;
    const next = `${current}${current.trim() ? '\n\n' : ''}${signature.content}`;
    if (account) {
        composition.setOverride(account.id, 'content', next);
    } else {
        composition.content.value = next;
    }
};

const startAssistant = (mode: typeof assistantMode.value): void => {
    assistantMode.value = mode;
    assistantSuggestion.value = '';
    assistantError.value = '';
    if (mode === 'write_more') {
        assistantScreen.value = 'prompt';
        return;
    }
    void generateAssistantSuggestion();
};

const generateAssistantSuggestion = async (): Promise<void> => {
    assistantBusy.value = true;
    assistantError.value = '';
    assistantHttp.mode = assistantMode.value;
    assistantHttp.current_content = assistantContent.value;
    assistantHttp.prompt = assistantPrompt.value;
    try {
        const result = (await assistantHttp.post(assistPostAi.url())) as {
            content: string;
        };
        if (!result.content?.trim()) throw new Error('Empty AI suggestion');
        assistantSuggestion.value = result.content;
        assistantScreen.value = 'suggestion';
    } catch {
        assistantError.value = trans('posts.composer.assistant_error');
    } finally {
        assistantBusy.value = false;
    }
};

const applyAssistantSuggestion = (): void => {
    if (step.value === 2 && expandedAccount.value) {
        composition.setOverride(
            expandedAccount.value.id,
            'content',
            assistantSuggestion.value,
        );
    } else {
        composition.content.value = assistantSuggestion.value;
    }
    assistantScreen.value = 'actions';
    assistantSuggestion.value = '';
};

const beginAiRegenerate = (
    accountId: string,
    index: number,
    media: MediaItem,
): void => {
    aiMediaTarget.value = { accountId, index, media };
    aiRegenerateOpen.value = true;
};

const onAiMediaRegenerated = (payload: {
    media: MediaItem;
    targetMediaId: string;
}): void => {
    const target = aiMediaTarget.value;
    const account = selectedAccounts.value.find(
        (selected) => selected.id === target?.accountId,
    );
    if (!target || !account) return;
    const items = [...composition.resolvedDestination(account).media];
    if (items[target.index]?.id !== payload.targetMediaId) return;
    items[target.index] = payload.media;
    composition.setOverride(account.id, 'media', items);
    aiMediaTarget.value = null;
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
    emit('submit', payload, createAnother.value && !props.postId);
};

const submitSelectedSchedule = (): void => {
    submit(scheduleMode.value === 'now' ? 'publishing' : 'scheduled');
};

const selectScheduleMode = (mode: 'now' | 'custom'): void => {
    scheduleMenuOpen.value = false;
    if (mode === 'custom') {
        timePickerOpen.value = true;
        return;
    }
    scheduleMode.value = 'now';
};

const confirmScheduledAt = (value: string): void => {
    composition.scheduledAt.value = value;
    scheduleMode.value = 'custom';
};

const close = (): void => emit('update:open', false);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="flex h-[min(90dvh,900px)] max-w-[calc(100%-1rem)] flex-col gap-0 overflow-hidden p-0"
            :class="
                expandedDialog ? 'sm:max-w-[calc(100%-2rem)]' : 'sm:max-w-6xl'
            "
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
                        initialPost || initialDraft
                            ? $t('posts.edit.title')
                            : $t('posts.create.title')
                    }}</DialogTitle>
                    <Popover>
                        <PopoverTrigger as-child>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                data-testid="composer-tags-trigger"
                            >
                                {{ $t('posts.edit.labels') }}
                                <IconChevronDown class="size-4" />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent class="w-56 space-y-2" align="start">
                            <p
                                v-if="!labels.length"
                                class="text-sm text-muted-foreground"
                            >
                                {{ $t('posts.no_labels') }}
                            </p>
                            <button
                                v-for="label in labels"
                                :key="label.id"
                                type="button"
                                :data-testid="`composer-label-${label.id}`"
                                :aria-pressed="
                                    composition.labelIds.value.includes(
                                        label.id,
                                    )
                                "
                                class="flex w-full items-center gap-2 rounded p-2 text-left text-sm hover:bg-muted"
                                @click="toggleLabel(label.id)"
                            >
                                <span
                                    class="size-3 rounded-full"
                                    :style="{ backgroundColor: label.color }"
                                />
                                {{ label.name }}
                            </button>
                        </PopoverContent>
                    </Popover>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="postId && commentsOpen"
                        type="button"
                        variant="outline"
                        size="sm"
                        data-testid="composer-back-to-post"
                        @click="commentsOpen = false"
                        >{{ $t('posts.edit.tabs.preview') }}</Button
                    >
                    <Button
                        v-if="postId && !commentsOpen"
                        type="button"
                        variant="outline"
                        size="sm"
                        data-testid="composer-comments"
                        @click="commentsOpen = true"
                        ><IconMessageCircle class="size-4" />{{
                            $t('posts.edit.tabs.comments')
                        }}</Button
                    >
                    <Button
                        v-if="!commentsOpen"
                        type="button"
                        variant="ghost"
                        size="sm"
                        :aria-pressed="assistantOpen"
                        data-testid="composer-ai-assistant"
                        @click="assistantOpen = true"
                        ><IconSparkles class="size-4" />{{
                            $t('posts.composer.assistant_title')
                        }}</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :aria-pressed="!assistantOpen && previewVisible"
                        data-testid="composer-preview-toggle"
                        @click="
                            assistantOpen = false;
                            previewVisible = true;
                        "
                        >{{ $t('posts.edit.tabs.preview') }}</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('posts.composer.expand')"
                        @click="expandedDialog = !expandedDialog"
                    >
                        <IconMaximize class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('posts.edit.cancel')"
                        @click="close"
                        ><IconX class="size-4"
                    /></Button>
                </div>
            </DialogHeader>

            <div
                v-if="commentsOpen && postId && currentUserId"
                class="min-h-0 flex-1 p-5"
                data-testid="composer-comments-panel"
            >
                <CommentsTab
                    :post-id="postId"
                    :current-user-id="currentUserId"
                    :highlight-comment-id="highlightCommentId"
                />
            </div>

            <div
                v-else
                class="grid min-h-0 flex-1"
                :class="
                    assistantOpen || previewVisible
                        ? 'md:grid-cols-[minmax(0,1fr)_minmax(280px,38%)]'
                        : 'grid-cols-1'
                "
            >
                <div
                    class="min-h-0 overflow-y-auto p-5"
                    :class="step === 1 ? 'flex flex-col gap-5' : 'space-y-5'"
                >
                    <p
                        v-if="Object.keys(errors).length"
                        data-testid="composer-errors"
                        class="rounded-lg border border-destructive bg-destructive/10 p-3 text-sm text-destructive"
                    >
                        {{ Object.values(errors)[0] }}
                    </p>
                    <template v-if="step === 1">
                        <div class="flex shrink-0 items-center gap-2">
                            <div
                                class="flex min-w-0 gap-2 overflow-x-auto"
                                data-testid="composer-accounts"
                            >
                                <button
                                    v-for="account in selectedAccounts"
                                    :key="account.id"
                                    type="button"
                                    :data-testid="`composer-account-${account.id}`"
                                    :aria-pressed="
                                        composition.selectedAccountIds.value.includes(
                                            account.id,
                                        )
                                    "
                                    class="relative shrink-0 rounded-lg border p-1"
                                    :class="
                                        composition.selectedAccountIds.value.includes(
                                            account.id,
                                        )
                                            ? 'border-primary bg-primary/10'
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
                                    <span class="sr-only">{{
                                        account.display_name || account.username
                                    }}</span>
                                    <img
                                        :src="getPlatformLogo(account.platform)"
                                        :alt="
                                            getPlatformLabel(account.platform)
                                        "
                                        class="absolute -right-1 -bottom-1 size-4 rounded-full bg-background"
                                    />
                                </button>
                            </div>
                            <Popover v-model:open="accountPickerOpen">
                                <PopoverTrigger as-child>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        :aria-label="
                                            $t(
                                                'posts.edit.platforms_dialog.title',
                                            )
                                        "
                                        data-testid="composer-add-account"
                                    >
                                        <IconPlus class="size-4" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent
                                    class="max-h-64 w-64 space-y-1 overflow-y-auto"
                                    align="start"
                                >
                                    <button
                                        v-for="account in socialAccounts"
                                        :key="account.id"
                                        type="button"
                                        :data-testid="`composer-account-option-${account.id}`"
                                        :aria-pressed="
                                            composition.selectedAccountIds.value.includes(
                                                account.id,
                                            )
                                        "
                                        class="flex w-full items-center gap-2 rounded p-2 text-left text-sm hover:bg-muted"
                                        @click="
                                            selectAccount(account);
                                            accountPickerOpen = false;
                                        "
                                    >
                                        <img
                                            :src="
                                                account.avatar_url ||
                                                getPlatformLogo(
                                                    account.platform,
                                                )
                                            "
                                            alt=""
                                            class="size-7 rounded-full object-cover"
                                        />
                                        <span class="min-w-0 flex-1 truncate">{{
                                            account.display_name ||
                                            account.username
                                        }}</span>
                                        <span
                                            v-if="
                                                composition.selectedAccountIds.value.includes(
                                                    account.id,
                                                )
                                            "
                                            >✓</span
                                        >
                                    </button>
                                </PopoverContent>
                            </Popover>
                        </div>
                        <div
                            class="flex min-h-64 flex-1 flex-col rounded-xl border border-border bg-card p-4"
                        >
                            <textarea
                                v-model="composition.content.value"
                                data-testid="composer-base-content"
                                class="min-h-40 w-full flex-1 resize-none bg-transparent outline-none"
                                :placeholder="
                                    $t('posts.create.steps.prompt_placeholder')
                                "
                            />
                            <Button
                                v-if="signatures.length"
                                type="button"
                                variant="ghost"
                                size="sm"
                                data-testid="composer-base-signature"
                                @click="openSignatures(null)"
                                >{{ $t('posts.edit.signatures') }}</Button
                            >
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
                                        v-for="(item, index) in composition
                                            .media.value"
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
                                            :aria-label="
                                                $t('posts.edit.delete')
                                            "
                                            @click="removeMedia(null, index)"
                                        >
                                            <IconTrash class="size-3" />
                                        </button>
                                    </div>
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
                                class="flex w-full items-center gap-3 rounded-xl border p-3 text-left"
                                :class="
                                    expandedAccountId === account.id
                                        ? 'border-primary bg-primary/10'
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
                                <Button
                                    v-if="signatures.length"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="openSignatures(expandedAccount.id)"
                                    >{{ $t('posts.edit.signatures') }}</Button
                                >
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
                                                v-if="
                                                    postId &&
                                                    isImage(item) &&
                                                    persistedMediaIds.has(
                                                        item.id,
                                                    )
                                                "
                                                type="button"
                                                :data-testid="`composer-ai-regenerate-${expandedAccount.id}-${index}`"
                                                :aria-label="
                                                    $t(
                                                        'posts.ai.image_regenerate.title',
                                                    )
                                                "
                                                @click="
                                                    beginAiRegenerate(
                                                        expandedAccount.id,
                                                        index,
                                                        item,
                                                    )
                                                "
                                            >
                                                <IconSparkles class="size-4" />
                                            </button>
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
                    v-if="assistantOpen || previewVisible"
                    class="hidden min-h-0 flex-col border-l bg-muted/30 md:flex"
                >
                    <template v-if="assistantOpen">
                        <div class="shrink-0 border-b px-5 py-4">
                            <h3 class="text-sm font-semibold">
                                {{ $t('posts.composer.assistant_title') }}
                            </h3>
                        </div>
                        <div
                            class="min-h-0 flex-1 overflow-y-auto p-5"
                            data-testid="composer-assistant-panel"
                        >
                            <template v-if="assistantScreen === 'actions'">
                                <p class="mb-4 text-sm">
                                    {{
                                        $t('posts.composer.assistant_question')
                                    }}
                                </p>
                                <div class="space-y-2">
                                    <Button
                                        v-for="mode in [
                                            'write_more',
                                            'rephrase',
                                            'shorten',
                                            'expand',
                                        ] as const"
                                        :key="mode"
                                        type="button"
                                        variant="outline"
                                        class="w-full justify-start"
                                        :disabled="
                                            assistantBusy ||
                                            (mode !== 'write_more' &&
                                                !assistantContent.trim())
                                        "
                                        :data-testid="`composer-ai-${mode}`"
                                        @click="startAssistant(mode)"
                                        >{{
                                            $t(
                                                `posts.composer.assistant_${mode}`,
                                            )
                                        }}</Button
                                    >
                                </div>
                                <IconLoader2
                                    v-if="assistantBusy"
                                    class="mt-4 size-5 animate-spin"
                                    :aria-label="
                                        $t('posts.composer.assistant_generate')
                                    "
                                    role="status"
                                />
                            </template>
                            <template v-else-if="assistantScreen === 'prompt'">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        $t('posts.composer.assistant_back')
                                    "
                                    @click="assistantScreen = 'actions'"
                                    ><IconArrowLeft class="size-4"
                                /></Button>
                                <label
                                    for="assistant-prompt"
                                    class="mt-6 mb-2 block text-sm font-medium"
                                    >{{
                                        $t(
                                            'posts.composer.assistant_prompt_question',
                                        )
                                    }}</label
                                >
                                <textarea
                                    id="assistant-prompt"
                                    v-model="assistantPrompt"
                                    data-testid="composer-ai-prompt"
                                    :placeholder="
                                        $t(
                                            'posts.composer.assistant_prompt_placeholder',
                                        )
                                    "
                                    class="min-h-40 w-full rounded-md border bg-background p-3 text-sm"
                                />
                                <p class="mt-2 text-xs text-muted-foreground">
                                    {{ $t('posts.composer.assistant_tip') }}
                                </p>
                                <Button
                                    type="button"
                                    class="mt-3 ml-auto flex"
                                    data-testid="composer-ai-generate"
                                    :disabled="
                                        assistantBusy || !assistantPrompt.trim()
                                    "
                                    @click="generateAssistantSuggestion"
                                    ><IconLoader2
                                        v-if="assistantBusy"
                                        class="size-4 animate-spin"
                                    /><IconSparkles v-else class="size-4" />{{
                                        $t('posts.composer.assistant_generate')
                                    }}</Button
                                >
                            </template>
                            <template v-else>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        $t('posts.composer.assistant_back')
                                    "
                                    @click="assistantScreen = 'actions'"
                                    ><IconArrowLeft class="size-4"
                                /></Button>
                                <h4 class="mt-5 mb-3 text-sm font-medium">
                                    {{
                                        $t(
                                            'posts.composer.assistant_suggestion',
                                        )
                                    }}
                                </h4>
                                <p
                                    class="rounded-md border bg-background p-3 text-sm whitespace-pre-wrap"
                                    data-testid="composer-ai-suggestion"
                                >
                                    {{ assistantSuggestion }}
                                </p>
                                <Button
                                    type="button"
                                    class="mt-3"
                                    data-testid="composer-ai-apply"
                                    @click="applyAssistantSuggestion"
                                    >{{
                                        $t('posts.composer.assistant_apply')
                                    }}</Button
                                >
                            </template>
                            <p
                                v-if="assistantError"
                                role="alert"
                                class="mt-3 text-sm text-destructive"
                            >
                                {{ assistantError }}
                            </p>
                        </div>
                    </template>
                    <template v-else>
                        <h3
                            class="shrink-0 border-b px-5 py-4 text-sm font-semibold"
                        >
                            {{
                                step === 1
                                    ? $t('posts.composer.post_previews')
                                    : $t('posts.edit.tabs.preview')
                            }}
                        </h3>
                        <div
                            class="min-h-0 flex-1 space-y-8 overflow-y-auto p-5"
                            data-testid="composer-previews-scroll"
                        >
                            <template v-if="step === 1">
                                <section
                                    v-for="account in selectedAccounts"
                                    :key="account.id"
                                    data-testid="composer-preview-card"
                                    class="space-y-3"
                                >
                                    <h4 class="text-sm text-muted-foreground">
                                        {{ getPlatformLabel(account.platform) }}
                                        ·
                                        {{
                                            account.display_name ||
                                            account.username
                                        }}
                                    </h4>
                                    <PlatformPreview
                                        :platform="account.platform"
                                        :social-account="account"
                                        :content="
                                            composition.resolvedDestination(
                                                account,
                                            ).content
                                        "
                                        :media="
                                            composition.resolvedDestination(
                                                account,
                                            ).media
                                        "
                                        :content-type="
                                            composition.resolvedDestination(
                                                account,
                                            ).content_type
                                        "
                                        :meta="
                                            composition.resolvedDestination(
                                                account,
                                            ).meta
                                        "
                                    />
                                </section>
                            </template>
                            <PlatformPreview
                                v-else-if="previewAccount && previewDestination"
                                :platform="previewAccount.platform"
                                :social-account="previewAccount"
                                :content="previewDestination.content"
                                :media="previewDestination.media"
                                :content-type="previewDestination.content_type"
                                :meta="previewDestination.meta"
                            />
                        </div>
                    </template>
                </aside>
            </div>

            <DialogFooter
                class="flex-col gap-3 border-t px-5 py-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex flex-1 items-center gap-4">
                    <label
                        v-if="!postId"
                        class="flex cursor-pointer items-center gap-2 text-sm"
                    >
                        <Checkbox
                            v-model="createAnother"
                            data-testid="composer-create-another"
                        />
                        {{ $t('posts.composer.create_another') }}
                    </label>
                    <Button
                        type="button"
                        variant="ghost"
                        data-testid="composer-save-draft"
                        :disabled="!canSubmit"
                        @click="submit('draft')"
                        >{{ $t('posts.composer.save_draft') }}</Button
                    >
                </div>
                <div class="flex items-center gap-0">
                    <Popover v-model:open="scheduleMenuOpen">
                        <PopoverTrigger as-child>
                            <Button
                                type="button"
                                variant="outline"
                                class="rounded-r-none"
                                data-testid="composer-schedule-trigger"
                            >
                                <IconCalendar class="size-4" />{{ scheduleLabel
                                }}<IconChevronDown class="size-4" />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent
                            align="end"
                            side="top"
                            class="w-64 space-y-1 p-2"
                        >
                            <button
                                type="button"
                                data-testid="composer-schedule-now"
                                class="w-full rounded p-2 text-left text-sm hover:bg-muted"
                                @click="selectScheduleMode('now')"
                            >
                                <strong class="block">{{
                                    $t('posts.composer.now')
                                }}</strong>
                                <span class="text-muted-foreground">{{
                                    $t('posts.composer.now_description')
                                }}</span>
                            </button>
                            <button
                                type="button"
                                data-testid="composer-schedule-custom"
                                class="w-full rounded p-2 text-left text-sm hover:bg-muted"
                                @click="selectScheduleMode('custom')"
                            >
                                <strong class="block">{{
                                    $t('posts.composer.set_date_time')
                                }}</strong>
                                <span class="text-muted-foreground">{{
                                    $t(
                                        'posts.composer.set_date_time_description',
                                    )
                                }}</span>
                            </button>
                        </PopoverContent>
                    </Popover>
                    <Button
                        v-if="step === 1"
                        type="button"
                        class="rounded-l-none"
                        data-testid="composer-next"
                        :disabled="selectedAccounts.length === 0"
                        @click="goToCustomization"
                        >{{ $t('posts.composer.customize_networks') }} →</Button
                    >
                    <template v-else>
                        <Button
                            type="button"
                            class="rounded-l-none"
                            data-testid="composer-submit"
                            :data-schedule-mode="scheduleMode"
                            :disabled="
                                !canSubmit ||
                                (scheduleMode === 'custom' &&
                                    !composition.scheduledAt.value)
                            "
                            @click="submitSelectedSchedule"
                            ><IconLoader2
                                v-if="submitting || cropUploading"
                                class="size-4 animate-spin"
                            />{{
                                scheduleMode === 'now'
                                    ? $t('posts.composer.publish_now')
                                    : $t('posts.edit.schedule')
                            }}</Button
                        >
                    </template>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <MediaPickerDialog ref="mediaPicker" @select="onMediaPicked" />
    <PickTimePopover
        v-model="composition.scheduledAt.value"
        v-model:open="timePickerOpen"
        @confirm="confirmScheduledAt"
    />
    <ImageCropperDialog
        v-model:open="cropping"
        :src="cropTarget?.media.url ?? null"
        :file-name="cropTarget?.media.original_filename ?? 'image.png'"
        :mime-type="cropTarget?.media.mime_type ?? 'image/png'"
        :output-width="cropDimensions.width"
        :output-height="cropDimensions.height"
        @cropped="onCropped"
    />
    <SignaturesModal
        ref="signaturesModal"
        :signatures="signatures"
        @select="appendSignature"
    />
    <AiRegenerateImageDialog
        v-if="postId"
        v-model:open="aiRegenerateOpen"
        :post-id="postId"
        :media-item="aiMediaTarget?.media ?? null"
        @regenerated="onAiMediaRegenerated"
    />
</template>
