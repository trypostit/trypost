<script setup lang="ts">
import { useHttp, usePage } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconArrowLeft,
    IconArrowRight,
    IconArrowsMaximize,
    IconArrowsMinimize,
    IconCalendarClock,
    IconCheck,
    IconChevronDown,
    IconChevronUp,
    IconCirclePlus,
    IconEye,
    IconLayoutGrid,
    IconLoader2,
    IconPin,
    IconPlus,
    IconSend,
    IconStar,
    IconStarFilled,
    IconTag,
    IconTemplate,
    IconWand,
    IconX,
} from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import {
    computed,
    effectScope,
    onMounted,
    ref,
    shallowReactive,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';

import WritingAssistantPanel, {
    type AssistantChannel,
} from '@/components/ai/WritingAssistantPanel.vue';
import EmptyState from '@/components/EmptyState.vue';
import FilterEmptyState from '@/components/FilterEmptyState.vue';
import LabelFilter from '@/components/labels/LabelFilter.vue';
import MediaTray from '@/components/media/MediaTray.vue';
import UnsplashDialog from '@/components/media/UnsplashDialog.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import ComposerAccountChip from '@/components/posts/composer/ComposerAccountChip.vue';
import ComposerAccountOptions from '@/components/posts/composer/ComposerAccountOptions.vue';
import ComposerAccountStack from '@/components/posts/composer/ComposerAccountStack.vue';
import ComposerEditorToolbar from '@/components/posts/composer/ComposerEditorToolbar.vue';
import ComposerLinkCard from '@/components/posts/composer/ComposerLinkCard.vue';
import ComposerNetworkCard from '@/components/posts/composer/ComposerNetworkCard.vue';
import ComposerNetworkRow from '@/components/posts/composer/ComposerNetworkRow.vue';
import ComposerNetworkSettings from '@/components/posts/composer/ComposerNetworkSettings.vue';
import ComposerSchedulePicker from '@/components/posts/composer/ComposerSchedulePicker.vue';
import ComposerTemplatesPanel from '@/components/posts/composer/ComposerTemplatesPanel.vue';
import MediaEditorDialog, {
    type MediaEditChange,
} from '@/components/posts/composer/MediaEditorDialog.vue';
import ResumeUnfinishedPostDialog from '@/components/posts/composer/ResumeUnfinishedPostDialog.vue';
import ChannelMediaWarnings from '@/components/posts/editor/ChannelMediaWarnings.vue';
import GoogleBusinessTopicTypeRadios from '@/components/posts/editor/GoogleBusinessTopicTypeRadios.vue';
import ThreadRepliesField from '@/components/posts/editor/ThreadRepliesField.vue';
import PlatformPreview from '@/components/posts/previews/PlatformPreview.vue';
import PreviewPanelTitle from '@/components/posts/previews/PreviewPanelTitle.vue';
import PublishEmptyIllustration from '@/components/publish/PublishEmptyIllustration.vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    type AutosaveMediaRef,
    type AutosaveSnapshot,
    composerAutosaveKey,
    fromAutosaveMedia,
    isExpiredUpload,
    toAutosaveMedia,
    useComposerAutosave,
} from '@/composables/useComposerAutosave';
import { useComposerTimezone } from '@/composables/useComposerTimezone';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { firstHttpUrl, useLinkCard } from '@/composables/useLinkCard';
import {
    getMediaValidationWarning,
    mediaWarningParams,
    type MediaValidationWarning,
} from '@/composables/useMedia';
import { useMediaEditSwap, withMediaAdded } from '@/composables/useMediaEditSwap';
import {
    type MediaImportStarted,
    useMediaImport,
} from '@/composables/useMediaImport';
import { getMediaRulesForContentType } from '@/composables/useMediaRules';
import {
    type MediaUploader,
    useMediaUpload,
} from '@/composables/useMediaUpload';
import { usePageErrors } from '@/composables/usePageErrors';
import {
    type ContentTypeOption,
    getPickableContentTypeOptions,
    getPlatformLabel,
} from '@/composables/usePlatformLogo';
import {
    ACCOUNT_SCOPED_SETTINGS,
    usePostComposition,
    type ComposerAccount,
    type ComposerInitialDraft,
    type ComposerInitialPost,
    type NetworkGroup,
    type PostComposition,
} from '@/composables/usePostComposition';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { useXLinkDefuser } from '@/composables/useXLinkDefuser';
import date from '@/date';
import dayjs from '@/dayjs';
import { characterCount, isBlankText } from '@/lib/characters';
import {
    googleBusinessTopicMeta,
    resolveGoogleBusinessTopicType,
} from '@/lib/googleBusiness';
import { countHashtags } from '@/lib/hashtags';
import { extractErrorMessage } from '@/lib/httpError';
import { getInstagramImageAspectIssues } from '@/lib/instagramImageAspect';
import { linkPreviewDroppable, linkPreviewUrl } from '@/lib/linkPreview';
import {
    editorTabsFor,
    type EditorTab,
    rulesFor,
} from '@/lib/mediaEditor';
import { isGooglePickerOpen } from '@/lib/mediaSources/googleDrive';
import { evaluatePlatformMeta } from '@/lib/platformMeta';
import { htmlToPlainText } from '@/lib/utils';
import { userTimezone } from '@/preferences';
import { settings as channelSettings } from '@/routes/app/channels';
import { linkPreviewMedia } from '@/routes/app/posts';
import { update as updatePreferences } from '@/routes/app/settings/preferences';
import type {
    MediaUploadLimits,
    PinterestBoardsPayload,
    SharedData,
    User,
} from '@/types';
import {
    CAPTIONLESS_CONTENT_TYPES,
    MEDIALESS_CONTENT_TYPES,
    ContentType,
} from '@/types/content-type';
import type { MediaItem } from '@/types/media';
import { THREAD_MAX_REPLIES, THREAD_PLATFORMS } from '@/types/network-options';
import { Platform } from '@/types/platform';
import {
    PostStatus,
    ScheduleMode,
    type QueuePositionValue,
} from '@/types/post';
import type { TikTokPrivacyLevelValue } from '@/types/tiktok-privacy';

const props = withDefaults(
    defineProps<{
        open: boolean;
        socialAccounts: ComposerAccount[];
        initialPost?: ComposerInitialPost | null;
        initialDraft?: ComposerInitialDraft | null;
        postId?: string | null;
        openAssistant?: boolean;
        labels?: { id: string; name: string; color: string }[];
        signatures?: { id: string; name: string; content: string }[];
        initialDate?: string | null;
        initialAccountIds?: string[];
        initialQueueSlot?: string | null;
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
        openAssistant: false,
        labels: () => [],
        signatures: () => [],
        initialDate: null,
        initialAccountIds: () => [],
        initialQueueSlot: null,
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
if (!props.initialPost && props.initialDate) {
    if (/^\d{4}-\d{2}-\d{2}$/.test(props.initialDate)) {
        composition.scheduledAt.value = `${props.initialDate}T09:00`;
    } else if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/.test(props.initialDate)) {
        composition.scheduledAt.value = props.initialDate;
    }
}

const updateContent = (value: string): void => {
    composition.content.value = value;
};

const updateMedia = (items: MediaItem[]): void => {
    composition.media.value = items;
};

if (!props.initialPost) {
    props.initialAccountIds.forEach((id) => composition.toggleAccount(id));
}
const composerTimezone = useComposerTimezone(
    () => composition.selectedAccounts.value,
);
let scheduledInstant =
    props.initialQueueSlot ??
    (composition.scheduledAt.value
        ? date.wallClockToUtc(composition.scheduledAt.value, userTimezone.value)
        : '');
const scheduledWallClock = (): string =>
    scheduledInstant
        ? date.utcToWallClock(scheduledInstant, composerTimezone.value)
        : '';
composition.scheduledAt.value = scheduledWallClock();
watch(
    composition.scheduledAt,
    (value) => {
        if (value !== scheduledWallClock()) {
            scheduledInstant = value
                ? date.wallClockToUtc(value, composerTimezone.value)
                : '';
        }
    },
    { flush: 'sync' },
);
watch(
    composerTimezone,
    () => {
        composition.scheduledAt.value = scheduledWallClock();
    },
    { flush: 'sync' },
);
const defaultPostAction =
    (usePage().props.auth?.user as User | null)?.default_post_action ?? 'next';
if (
    !props.initialPost &&
    !composition.scheduledAt.value &&
    defaultPostAction === 'custom'
) {
    composition.scheduledAt.value = date.nextFullHour(composerTimezone.value);
}
const { contentFor } = useXLinkDefuser();
const { requiresApproval, canManageAccounts } = useWorkspaceAbilities();
const errors = usePageErrors();
type ComposerSidePanel = 'templates' | 'assistant' | 'preview';
const sidePanel = ref<ComposerSidePanel>(
    props.openAssistant ? 'assistant' : 'preview',
);
const expandedDialog = ref(false);

const toggleExpandedDialog = (): void => {
    expandedDialog.value = !expandedDialog.value;
};

const mobilePanelOpen = ref(false);

const closeMobilePanel = (): void => {
    mobilePanelOpen.value = false;
};

const accountPickerOpen = ref(false);
const accountSearch = ref('');
const lastUsedAccountIds = ref<string[]>([]);
const scheduleMenuOpen = ref(false);
const schedulePanel = ref<'menu' | 'picker'>('menu');

const showScheduleMenu = (): void => {
    schedulePanel.value = 'menu';
};

const createAnother = ref(false);
type ComposerScheduleMode = QueuePositionValue | 'now' | 'custom';
const scheduleModeChosen = ref(
    Boolean(props.initialPost) || Boolean(composition.scheduledAt.value),
);
const initialScheduleMode = (): ComposerScheduleMode => {
    const initial = props.initialPost;

    if (
        initial?.schedule_mode === ScheduleMode.Queue &&
        initial.status === PostStatus.PendingApproval
    ) {
        return initial.queue_position ?? 'next';
    }

    if (
        initial?.schedule_mode === ScheduleMode.Queue &&
        initial.status === PostStatus.Scheduled
    ) {
        return 'next';
    }

    return composition.scheduledAt.value ? 'custom' : 'now';
};
const scheduleMode = ref<ComposerScheduleMode>(initialScheduleMode());
const previewAccountId = ref<string | null>(null);
const networkGroups = composition.networkGroups;
const customizing = composition.customizing;
const sharedStep = computed(
    () => networkGroups.value.length > 1 && !customizing.value,
);
const confirmingBack = ref(false);

const requestBackConfirmation = (): void => {
    confirmingBack.value = true;
};

const openGroupKey = ref<string | null>(null);
const findGroup = (groupKey: string | null): NetworkGroup | undefined =>
    groupKey === null
        ? undefined
        : networkGroups.value.find((group) => group.key === groupKey);
const openGroup = computed(() => findGroup(openGroupKey.value));
const groupDestination = (group: NetworkGroup) =>
    composition.resolvedDestination(group.anchor);
const contentTypeOptionsFor = (group: NetworkGroup): ContentTypeOption[] => {
    const options = getPickableContentTypeOptions(group.platform);

    if (group.platform !== Platform.Threads) return options;

    const attached =
        groupDestination(group).media.length > 0 ||
        (group.key === openGroupKey.value && openLinkCard.value !== null);

    return options.map((option) =>
        option.value === ContentType.ThreadsGhostPost && attached
            ? {
                  ...option,
                  disabledReasonKey: 'posts.form.threads.ghost_post_attachments',
              }
            : option,
    );
};
const openNetwork = (
    groupKey: string,
    accountId: string | null = null,
): void => {
    openGroupKey.value = groupKey;
    previewAccountId.value =
        accountId ?? findGroup(groupKey)?.anchor.id ?? null;
};
const cropping = ref(false);
const cropTarget = ref<{
    groupKey: string | null;
    indexes: number[];
    initialIndex: number;
    tab: EditorTab;
} | null>(null);
const {
    uploading: cropUploading,
    failed: cropError,
    swap: swapEditedMedia,
} = useMediaEditSwap();
const availableSignatures = ref([...props.signatures]);
watch(
    () => props.signatures,
    (signatures) => {
        availableSignatures.value = [...signatures];
    },
);

const selectedAccounts = composition.selectedAccounts;
const isSingleChannel = computed(() => selectedAccounts.value.length === 1);

const slotSchedule = computed(() =>
    isSingleChannel.value
        ? (selectedAccounts.value[0]?.posting_schedule ?? null)
        : null,
);
const ownScheduledInstant = props.initialPost ? scheduledInstant : '';
const isInitialQueueSlot = (): boolean =>
    Boolean(props.initialQueueSlot) &&
    dayjs(scheduledInstant).isSame(props.initialQueueSlot) &&
    isSingleChannel.value &&
    selectedAccounts.value[0]?.id === props.initialAccountIds[0];
const takenSlots = computed(() =>
    isSingleChannel.value
        ? (selectedAccounts.value[0]?.taken_slots ?? []).filter(
              (at) => !ownScheduledInstant || !dayjs(at).isSame(ownScheduledInstant),
          )
        : [],
);
watch(
    () => composition.selectedAccountIds.value,
    () => {
        if (!findGroup(openGroupKey.value)) {
            openGroupKey.value = networkGroups.value[0]?.key ?? null;
        }
        if (
            !openGroup.value?.accounts.some(
                (account) => account.id === previewAccountId.value,
            )
        ) {
            previewAccountId.value = openGroup.value?.anchor.id ?? null;
        }
    },
    { immediate: true },
);
const page = usePage<SharedData>();
const mediaUploadLimits = (): MediaUploadLimits =>
    page.props.mediaUploadLimits ?? {
        max_bytes: { image: 0, video: 0, document: 0 },
        extensions: { image: [], video: [], document: [] },
        upload_retention_hours: 0,
        heic: false,
    };
const trayKey = (groupKey: string | null): string =>
    groupKey !== null && customizing.value ? groupKey : '';
const appendMedia = (
    groupKey: string | null,
    item: MediaItem,
    replaces: string | null = null,
): void => {
    const group = findGroup(groupKey);
    if (!group) {
        composition.media.value = withMediaAdded(
            composition.media.value,
            item,
            replaces,
        );
    }
    const targets = group
        ? [group]
        : customizing.value
          ? networkGroups.value
          : [];
    targets.forEach((target) =>
        composition.setGroupOverride(
            target.key,
            'media',
            withMediaAdded(groupDestination(target).media, item, replaces),
        ),
    );
};
const linkCardDestination = computed(() =>
    !sharedStep.value && openGroup.value
        ? groupDestination(openGroup.value)
        : null,
);
const { card: openLinkCard } = useLinkCard(
    computed(() => linkCardDestination.value?.content ?? ''),
    computed(() => linkCardDestination.value?.media ?? []),
    (text) =>
        openGroup.value && linkCardDestination.value
            ? linkPreviewUrl(
                  openGroup.value.platform,
                  linkCardDestination.value.content_type,
                  linkCardDestination.value.meta,
                  text,
              )
            : null,
);
const dropLinkCard = (group: NetworkGroup): void =>
    composition.setGroupOverride(group.key, 'meta', {
        ...groupDestination(group).meta,
        link_preview: false,
    });
const openLinkUrl = computed(() =>
    openGroup.value && linkCardDestination.value
        ? linkPreviewUrl(
              openGroup.value.platform,
              linkCardDestination.value.content_type,
              undefined,
              linkCardDestination.value.content,
          )
        : null,
);
const lastLinkUrls = new Map<string, string>();
watch(
    () => props.open,
    () => lastLinkUrls.clear(),
);
watch(
    openLinkUrl,
    (url) => {
        const group = openGroup.value;
        if (!group || url === null) return;
        const previous = lastLinkUrls.get(group.key);
        lastLinkUrls.set(group.key, url);
        const meta = groupDestination(group).meta ?? {};
        if (
            previous === undefined ||
            previous === url ||
            meta.link_preview !== false
        ) {
            return;
        }
        composition.setGroupOverride(
            group.key,
            'meta',
            Object.fromEntries(
                Object.entries(meta).filter(([key]) => key !== 'link_preview'),
            ),
        );
    },
    { immediate: true },
);
const linkCardMediaHttp = useHttp<{ url: string }, MediaItem>({ url: '' });
const replaceLinkCardWithMedia = async (group: NetworkGroup): Promise<void> => {
    if (!openLinkCard.value) return;
    linkCardMediaHttp.url = openLinkCard.value.uri;
    try {
        appendMedia(
            group.key,
            await linkCardMediaHttp.post(linkPreviewMedia.url()),
        );
    } catch (exception) {
        toast.error(
            extractErrorMessage(exception) ??
                trans('posts.composer.media_sources.errors.import_failed'),
        );
    }
};
const uploadScope = effectScope();
const uploaders = shallowReactive(new Map<string, MediaUploader>());
const cardUploaders = new Map<string, MediaUploader>();
const withSharedUploads = (
    own: MediaUploader,
    shared: MediaUploader,
): MediaUploader => {
    const owner = (key: string): MediaUploader =>
        shared.entries.value.some((entry) => entry.key === key) ||
        shared.imports.value.some((pending) => pending.key === key)
            ? shared
            : own;

    return {
        ...own,
        entries: computed(() => [
            ...shared.entries.value,
            ...own.entries.value,
        ]),
        imports: computed(() => [
            ...shared.imports.value,
            ...own.imports.value,
        ]),
        busy: computed(() => shared.busy.value || own.busy.value),
        failed: computed(() => shared.failed.value || own.failed.value),
        retry: (key) => owner(key).retry(key),
        cancel: (key) => owner(key).cancel(key),
        remove: (key) => owner(key).remove(key),
    };
};
const createUploader = (key: string): void => {
    if (uploaders.has(key)) return;
    uploadScope.run(() => {
        const uploader = useMediaUpload({
            limits: mediaUploadLimits,
            onReady: (item, _key, replaces) =>
                appendMedia(key || null, item, replaces ?? null),
        });
        uploaders.set(key, uploader);
        if (key) {
            cardUploaders.set(
                key,
                withSharedUploads(uploader, uploaders.get('')!),
            );
        }
    });
};
createUploader('');
const suggestedMedia = ref<Record<string, MediaItem[]>>({});
watch(
    () => networkGroups.value.map((group) => group.key),
    (keys, previousKeys) => {
        keys.forEach(createUploader);
        (previousKeys ?? [])
            .filter((key) => !keys.includes(key))
            .forEach((key) => {
                uploaders.get(key)?.clear();
                delete suggestedMedia.value[key];
            });
    },
    { immediate: true },
);
const uploaderFor = (groupKey: string | null): MediaUploader =>
    cardUploaders.get(trayKey(groupKey)) ?? uploaders.get('')!;
const trayHasActivity = (groupKey: string | null): boolean => {
    const uploader = uploaderFor(groupKey);

    return (
        uploader.entries.value.length > 0 || uploader.imports.value.length > 0
    );
};
const suggestionsFor = (groupKey: string | null): MediaItem[] =>
    suggestedMedia.value[trayKey(groupKey) || 'shared'] ?? [];
const setSuggestions = (groupKey: string | null, items: MediaItem[]): void => {
    suggestedMedia.value[trayKey(groupKey) || 'shared'] = items;
};
watch(
    () => props.open,
    (open) => {
        if (!open) suggestedMedia.value = {};
    },
);
const unsplashOpen = ref(false);
const unsplashGroupKey = ref<string | null>(null);
const openUnsplash = (groupKey: string | null): void => {
    unsplashGroupKey.value = groupKey;
    unsplashOpen.value = true;
};

const mediaImport = useMediaImport();
const onImportStarted = (
    started: MediaImportStarted,
    groupKey: string | null,
): void => mediaImport.track(uploaderFor(groupKey), started);
watch(customizing, (isCustomizing) => {
    if (isCustomizing) return;
    uploaders.forEach((uploader, key) => {
        if (key) uploader.clear();
    });
});
const activeUploaders = computed(() => [
    uploaders.get('')!,
    ...(customizing.value ? networkGroups.value : []).flatMap((group) => {
        const uploader = uploaders.get(group.key);

        return uploader ? [uploader] : [];
    }),
]);
const mediaUploading = computed(() =>
    activeUploaders.value.some((uploader) => uploader.busy.value),
);
const mediaFailed = computed(() =>
    activeUploaders.value.some((uploader) => uploader.failed.value),
);
const mediaKey = (item: MediaItem): string => item.upload_token ?? item.id;
const ownsMediaOverride = (accountId: string): boolean =>
    Object.hasOwn(composition.overrides.value[accountId] ?? {}, 'media');
type SubmittedMedia = {
    shared: MediaItem[];
    destinations: { groupKey: string; overridden: boolean; media: MediaItem[] }[];
};
const snapshotMedia = (): SubmittedMedia => ({
    shared: [...composition.media.value],
    destinations: selectedAccounts.value.map((account) => ({
        groupKey: account.platform,
        overridden: ownsMediaOverride(account.id),
        media: [...composition.resolvedDestination(account).media],
    })),
});
const submittedMedia = ref<SubmittedMedia | null>(null);
const mediaErrorKeys = computed(() => {
    const submitted = submittedMedia.value ?? snapshotMedia();
    const found: Record<string, Record<string, string>> = {};
    const record = (
        target: string,
        items: MediaItem[],
        index: number,
        message: string,
    ): void => {
        const item = items[index];
        if (!item) return;
        found[target] ??= {};
        found[target][mediaKey(item)] ??= message;
    };
    for (const [key, message] of Object.entries(errors.value)) {
        const shared = /^media\.(\d+)(\.|$)/.exec(key);
        if (shared) {
            record('', submitted.shared, Number(shared[1]), message);
            continue;
        }
        const match = /^destinations\.(\d+)\.media\.(\d+)(\.|$)/.exec(key);
        const destination = match
            ? submitted.destinations[Number(match[1])]
            : undefined;
        if (!match || !destination) continue;
        record(
            destination.overridden ? destination.groupKey : '',
            destination.media,
            Number(match[2]),
            message,
        );
    }

    return found;
});
const mediaErrorsFor = (groupKey: string | null): Record<number, string> => {
    const group = findGroup(groupKey);
    const target = group && ownsMediaOverride(group.anchor.id) ? group.key : '';
    const keyed = mediaErrorKeys.value[target] ?? {};
    const items = group ? groupDestination(group).media : composition.media.value;

    return Object.fromEntries(
        items.flatMap((item, index) => {
            const message = keyed[mediaKey(item)];

            return message ? [[index, message]] : [];
        }),
    );
};
const onMediaDropped = (event: DragEvent, groupKey: string | null): void => {
    const files = Array.from(event.dataTransfer?.files ?? []);
    if (!files.length || cropUploading.value) return;
    uploaderFor(groupKey).add(files);
};
const onMediaPasted = (
    event: ClipboardEvent,
    groupKey: string | null,
): void => {
    const files = Array.from(event.clipboardData?.files ?? []);
    if (
        !files.length ||
        event.clipboardData?.getData('text/plain').trim() ||
        cropUploading.value
    ) {
        return;
    }
    event.preventDefault();
    uploaderFor(groupKey).add(files);
};
const queueAvailable = computed(
    () =>
        selectedAccounts.value.length > 0 &&
        selectedAccounts.value.every((account) => account.has_posting_schedule),
);
const accountsWithoutSlots = computed(() =>
    selectedAccounts.value.filter((account) => !account.has_posting_schedule),
);
const queueBlocked = computed(() => accountsWithoutSlots.value.length > 0);
const accountsWithoutSlotsLabel = computed(() =>
    accountsWithoutSlots.value
        .map((account) => account.display_label || account.display_name)
        .join(', '),
);
const isQueueMode = computed(
    () => scheduleMode.value === 'next' || scheduleMode.value === 'top',
);
watch(
    queueAvailable,
    (available) => {
        if (
            available &&
            !scheduleModeChosen.value &&
            (defaultPostAction === 'next' || defaultPostAction === 'top')
        ) {
            scheduleMode.value = defaultPostAction;
        }
    },
    { immediate: true },
);
watch(
    queueBlocked,
    (blocked) => {
        if (blocked && isQueueMode.value) {
            scheduleMode.value = 'custom';
        }
    },
    { immediate: true },
);
watch(
    [scheduleMode, queueBlocked],
    ([mode, blocked]) => {
        if (requiresApproval.value && mode === 'now') {
            scheduleMode.value = blocked ? 'custom' : 'next';
        }
    },
    { immediate: true },
);
const currentDefaultPostAction = computed(
    () =>
        (page.props.auth?.user as User | null)?.default_post_action ?? 'next',
);
const preferenceHttp = useHttp<{ default_post_action: string }>({
    default_post_action: '',
});
let confirmedDefaultPostAction: User['default_post_action'] | null = null;
let savingDefaultPostAction = false;
let queuedDefaultPostAction: User['default_post_action'] | null = null;
const saveDefaultPostAction = async (
    user: User,
    action: User['default_post_action'],
): Promise<void> => {
    savingDefaultPostAction = true;
    preferenceHttp.default_post_action = action;
    let saved = false;
    try {
        await preferenceHttp.patch(updatePreferences.url(), {
            onSuccess: () => {
                saved = true;
            },
        });
    } catch {
        saved = false;
    }
    savingDefaultPostAction = false;
    if (saved) confirmedDefaultPostAction = action;

    const next = queuedDefaultPostAction;
    queuedDefaultPostAction = null;
    if (next !== null && next !== confirmedDefaultPostAction) {
        await saveDefaultPostAction(user, next);
        return;
    }
    if (!saved && next === null && confirmedDefaultPostAction) {
        user.default_post_action = confirmedDefaultPostAction;
        toast.error(trans('posts.composer.queue.set_default_failed'));
    }
};
const setDefaultPostAction = (action: User['default_post_action']): void => {
    const user = page.props.auth?.user as User | null;
    if (!user || user.default_post_action === action) return;
    confirmedDefaultPostAction ??= user.default_post_action;
    user.default_post_action = action;
    if (savingDefaultPostAction) {
        queuedDefaultPostAction = action;
        return;
    }
    void saveDefaultPostAction(user, action);
};
const isInstagramPlatform = (platform: string): boolean =>
    platform === Platform.Instagram || platform === Platform.InstagramFacebook;
const availableLabels = ref([...props.labels]);
watch(
    () => props.labels,
    (labels) => {
        availableLabels.value = [...labels];
    },
);
const selectedLabels = computed(() =>
    availableLabels.value.filter((label) =>
        composition.labelIds.value.includes(label.id),
    ),
);
const addLabel = (label: {
    id: string;
    name: string;
    color: string;
}): void => {
    if (!availableLabels.value.some(({ id }) => id === label.id)) {
        availableLabels.value = [...availableLabels.value, label];
    }
    composition.labelIds.value = [...composition.labelIds.value, label.id];
};
const filteredAccounts = computed(() => {
    const query = accountSearch.value.trim().toLocaleLowerCase();

    return query
        ? props.socialAccounts.filter((account) =>
              [
                  account.display_name,
                  account.username,
                  getPlatformLabel(account.platform),
              ].some((value) => value.toLocaleLowerCase().includes(query)),
          )
        : props.socialAccounts;
});
const recentlyUsedAccounts = computed(() =>
    props.socialAccounts.filter((account) =>
        lastUsedAccountIds.value.includes(account.id),
    ),
);
onMounted(() => {
    try {
        const saved = JSON.parse(
            localStorage.getItem('trypost:composer:last-used-accounts') ?? '[]',
        );
        if (Array.isArray(saved)) {
            lastUsedAccountIds.value = saved.filter(
                (id): id is string => typeof id === 'string',
            );
        }
    } catch {
        lastUsedAccountIds.value = [];
    }
});
const hasSharedPreview = computed(
    () =>
        Boolean(composition.content.value.trim()) ||
        composition.media.value.length > 0,
);
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
const contentOf = (groupKey: string | null): string => {
    const group = findGroup(groupKey);

    return group ? groupDestination(group).content : composition.content.value;
};
const writeContent = (groupKey: string | null, next: string): void => {
    const group = findGroup(groupKey);
    if (group) {
        composition.setGroupOverride(group.key, 'content', next);
        return;
    }
    composition.content.value = next;
};
const assistantContent = computed(() =>
    contentOf(sharedStep.value ? null : openGroupKey.value),
);
const accountLimit = (account: ComposerAccount): number =>
    props.platformConfigs[account.id]?.maxContentLength ?? Infinity;
const assistantChannel = computed<AssistantChannel | null>(() => {
    const account = sharedStep.value
        ? undefined
        : openGroup.value?.accounts.reduce<ComposerAccount | undefined>(
              (tightest, candidate) =>
                  !tightest || accountLimit(candidate) < accountLimit(tightest)
                      ? candidate
                      : tightest,
              undefined,
          );
    if (!account) return null;
    const limit = props.platformConfigs[account.id]?.maxContentLength;

    return {
        accountId: account.id,
        platform: account.platform,
        label: getPlatformLabel(account.platform),
        limit: typeof limit === 'number' ? limit : null,
    };
});
const { open: openConnectDialog } = useConnectChannelDialog();

const canSubmit = computed(
    () =>
        selectedAccounts.value.length > 0 &&
        !props.submitting &&
        !cropUploading.value &&
        !mediaUploading.value &&
        !mediaFailed.value,
);
const contentWarningLength = (account: ComposerAccount): number =>
    account.platform === Platform.Mastodon
        ? characterCount(
              String(
                  composition.resolvedDestination(account).meta
                      ?.spoiler_text ?? '',
              ).trim(),
          )
        : 0;
const remainingCharacters = (account: ComposerAccount): number | null => {
    const limit = props.platformConfigs[account.id]?.maxContentLength;
    if (typeof limit !== 'number' || limit <= 0) return null;
    const destination = composition.resolvedDestination(account);
    if (CAPTIONLESS_CONTENT_TYPES.has(destination.content_type)) return null;

    return (
        limit -
        contentWarningLength(account) -
        characterCount(contentFor(destination.content, account.platform))
    );
};
const groupRemaining = (group: NetworkGroup): number | null => {
    const values = group.accounts
        .map(remainingCharacters)
        .filter((value): value is number => value !== null);

    return values.length ? Math.min(...values) : null;
};
const threadActive = ref(-1);

const deselectThread = (): void => {
    threadActive.value = -1;
};

watch(openGroupKey, () => {
    threadActive.value = -1;
});
const repliesOf = (meta: Record<string, any> | null | undefined): string[] =>
    Array.isArray(meta?.thread_replies) ? meta.thread_replies : [];
const threadReplies = (group: NetworkGroup): string[] =>
    repliesOf(groupDestination(group).meta);
const supportsThread = (group: NetworkGroup): boolean =>
    THREAD_PLATFORMS.includes(group.platform);
const replyLimit = (account: ComposerAccount): number | null => {
    const limit = props.platformConfigs[account.id]?.maxContentLength;

    return typeof limit === 'number' && limit > 0
        ? limit - contentWarningLength(account)
        : null;
};
const threadReplyLimit = (group: NetworkGroup): number | null => {
    const limits = group.accounts
        .map(replyLimit)
        .filter((limit): limit is number => limit !== null);

    return limits.length ? Math.min(...limits) : null;
};
const setThreadReplies = (group: NetworkGroup, replies: string[]): void => {
    const meta = { ...groupDestination(group).meta };
    delete meta.thread_replies;
    composition.setGroupOverride(
        group.key,
        'meta',
        replies.length ? { ...meta, thread_replies: replies } : meta,
    );
};
const addThreadReply = (group: NetworkGroup): void => {
    const replies = threadReplies(group);
    if (replies.length >= THREAD_MAX_REPLIES) return;
    setThreadReplies(group, [...replies, '']);
    threadActive.value = replies.length;
};
const activeRemaining = (group: NetworkGroup): number | null => {
    const reply = threadReplies(group)[threadActive.value];
    if (reply === undefined) return groupRemaining(group);
    const limit = threadReplyLimit(group);

    return limit === null
        ? null
        : limit - characterCount(contentFor(reply, group.platform));
};
const threadReplyErrors = (group: NetworkGroup): Record<number, string> => {
    const found: Record<number, string> = {};
    for (const account of group.accounts) {
        const prefix = `destinations.${selectedAccounts.value.indexOf(account)}.meta.thread_replies`;
        for (const [key, message] of Object.entries(errors.value)) {
            const match = key.startsWith(`${prefix}.`)
                ? Number(key.slice(prefix.length + 1))
                : NaN;
            if (Number.isInteger(match)) found[match] ??= message;
        }
    }

    return found;
};
const remainingHashtags = (account: ComposerAccount): number | null => {
    const limit = props.platformConfigs[account.id]?.maxHashtags;
    if (typeof limit !== 'number') return null;
    const destination = composition.resolvedDestination(account);
    if (CAPTIONLESS_CONTENT_TYPES.has(destination.content_type)) return null;

    return (
        limit -
        countHashtags(
            htmlToPlainText(contentFor(destination.content, account.platform)),
        )
    );
};
const groupHashtagsRemaining = (group: NetworkGroup): number | null => {
    const values = group.accounts
        .map(remainingHashtags)
        .filter((value): value is number => value !== null);

    return values.length ? Math.min(...values) : null;
};
type DestinationIssue = {
    key: string;
    params: Record<string, string>;
    warning?: MediaValidationWarning;
    contentType: string;
};
const destinationIssues = (account: ComposerAccount): DestinationIssue[] => {
    const destination = composition.resolvedDestination(account);
    const contentType = destination.content_type;
    const aspectIssues = isInstagramPlatform(account.platform)
        ? getInstagramImageAspectIssues(contentType, destination.media)
        : [];
    const mediaWarning = getMediaValidationWarning(
        contentType,
        destination.media,
    );
    const remaining = remainingCharacters(account);
    const meta = evaluatePlatformMeta(account.platform, destination.meta ?? {});
    const issues: DestinationIssue[] = [];

    if (
        mediaWarning &&
        !(aspectIssues.length && mediaWarning.key.startsWith('aspect_ratio_'))
    ) {
        issues.push({
            key: `posts.form.warnings.${mediaWarning.key}`,
            params: {},
            warning: mediaWarning,
            contentType,
        });
    } else if (
        MEDIALESS_CONTENT_TYPES.has(contentType) &&
        firstHttpUrl(destination.content) !== null
    ) {
        issues.push({
            key: 'posts.form.warnings.text_only',
            params: {},
            contentType,
        });
    }
    for (const aspectIssue of aspectIssues) {
        issues.push({
            key: 'posts.composer.instagram_image_aspect_issue',
            params: {
                image: String(aspectIssue.index + 1),
                current: aspectIssue.ratio.toFixed(2),
                min: aspectIssue.min.toFixed(2),
                max: aspectIssue.max.toFixed(2),
            },
            contentType,
        });
    }
    if (remaining !== null && remaining < 0) {
        issues.push({
            key: 'posts.form.content_exceeds_platform',
            params: {
                platform: getPlatformLabel(account.platform),
                over: String(-remaining),
                limit: String(
                    props.platformConfigs[account.id]?.maxContentLength ?? '',
                ),
            },
            contentType,
        });
    }
    const hashtagsLeft = remainingHashtags(account);
    if (hashtagsLeft !== null && hashtagsLeft < 0) {
        issues.push({
            key: 'posts.form.hashtags_exceed_platform',
            params: {
                platform: getPlatformLabel(account.platform),
                limit: String(props.platformConfigs[account.id]?.maxHashtags),
            },
            contentType,
        });
    }
    if (!meta.valid) {
        issues.push({
            key: meta.tooltipKey ?? 'posts.edit.compliance_incomplete',
            params: {},
            contentType,
        });
    }
    if (THREAD_PLATFORMS.includes(account.platform)) {
        const limit = replyLimit(account);
        for (const reply of repliesOf(destination.meta)) {
            const over =
                limit === null
                    ? 0
                    : characterCount(contentFor(reply, account.platform)) -
                      limit;
            if (isBlankText(reply)) {
                issues.push({
                    key: 'posts.form.thread.reply_empty',
                    params: {},
                    contentType,
                });
            } else if (over > 0) {
                issues.push({
                    key: 'posts.form.thread.reply_too_long',
                    params: { limit: String(limit), over: String(over) },
                    contentType,
                });
            }
        }
    }

    return issues;
};
const groupIssues = (group: NetworkGroup): DestinationIssue[] => {
    const seen = new Set<string>();

    return group.accounts.flatMap(destinationIssues).filter((issue) => {
        const id = `${issue.key}:${JSON.stringify(issue.params)}`;
        if (seen.has(id)) return false;
        seen.add(id);

        return true;
    });
};
const groupIssueLabel = (group: NetworkGroup): string => {
    const count = groupIssues(group).length;

    return transChoice('posts.composer.destination_issues', count, {
        count: String(count),
    });
};
const blockingIssue = computed(() => {
    for (const account of selectedAccounts.value) {
        const [issue] = destinationIssues(account);
        if (issue) return { ...issue, account };
    }

    return null;
});
const requiresMediaWarning = (account: ComposerAccount | undefined): boolean => {
    if (!account) return false;
    const destination = composition.resolvedDestination(account);

    return (
        getMediaValidationWarning(destination.content_type, destination.media)
            ?.key === 'requires_media'
    );
};
const hasBlockingIssues = computed(() => blockingIssue.value !== null);
const isBatch = computed(
    () => !props.postId && selectedAccounts.value.length > 1,
);
const scheduleLabelKey = computed(() =>
    scheduleMode.value === 'next' || scheduleMode.value === 'top'
        ? `posts.composer.queue.${scheduleMode.value}`
        : 'posts.composer.now',
);
const scheduleDateLabel = computed(() =>
    scheduleMode.value === 'custom' && composition.scheduledAt.value
        ? date.formatLocalMonthDayTime(composition.scheduledAt.value)
        : null,
);
const scheduleTriggerIcon = computed(() => {
    if (scheduleMode.value === 'custom' && composition.scheduledAt.value) {
        return { name: 'pin', component: IconPin };
    }

    return scheduleMode.value === 'next' || scheduleMode.value === 'top'
        ? { name: 'calendar-clock', component: IconCalendarClock }
        : { name: 'send', component: IconSend };
});
watch(scheduleMenuOpen, (open) => {
    if (open) {
        schedulePanel.value =
            scheduleMode.value === 'custom' && composition.scheduledAt.value
                ? 'picker'
                : 'menu';
    }
});
const scheduleOptions = computed(() => [
    ...(['next', 'top'] as const).map((mode) => ({
        mode,
        titleKey: `posts.composer.queue.${mode}`,
        descriptionKey: `posts.composer.queue.${mode}_description`,
    })),
    {
        mode: 'now' as const,
        titleKey: 'posts.composer.now',
        descriptionKey: 'posts.composer.now_description',
    },
    {
        mode: 'custom' as const,
        titleKey: 'posts.composer.set_date_time',
        descriptionKey: 'posts.composer.set_date_time_description',
    },
].filter((option) => !requiresApproval.value || option.mode !== 'now'));

const selectAccount = (account: ComposerAccount): void => {
    composition.toggleAccount(account.id);
    if (composition.selectedAccountIds.value.includes(account.id)) {
        openNetwork(account.platform, account.id);
    }
};

const selectAccounts = (accounts: ComposerAccount[]): void => {
    if (props.initialPost) return;

    const allSelected = accounts.every((account) =>
        composition.selectedAccountIds.value.includes(account.id),
    );
    for (const account of accounts) {
        if (
            composition.selectedAccountIds.value.includes(account.id) ===
            allSelected
        ) {
            selectAccount(account);
        }
    }
};

const saveSignature = (signature: {
    id: string;
    name: string;
    content: string;
}): void => {
    availableSignatures.value = [
        signature,
        ...availableSignatures.value.filter(
            (existing) => existing.id !== signature.id,
        ),
    ];
};

const appendSignature = (
    signature: { content: string },
    groupKey: string | null,
): void => {
    const current = contentOf(groupKey);
    writeContent(
        groupKey,
        `${current}${current.trim() ? '\n\n' : ''}${signature.content}`,
    );
};

const appendEmoji = (emoji: string, groupKey: string | null): void =>
    writeContent(groupKey, `${contentOf(groupKey)}${emoji}`);

const writeAssistantTarget = (text: string): void =>
    writeContent(sharedStep.value ? null : openGroupKey.value, text);

const customizeNetworks = (): void => {
    composition.customize();
    const [firstGroup] = networkGroups.value;
    if (firstGroup) openNetwork(firstGroup.key);
};

const goBackToSharedStep = (): void => {
    composition.discardCustomization();
    confirmingBack.value = false;
};

const insertAssistantText = (text: string): void => {
    const target = assistantContent.value;
    writeAssistantTarget(target.trim() ? `${target}\n\n${text}` : text);
    mobilePanelOpen.value = false;
};

const showSidePanel = (panel: ComposerSidePanel): void => {
    sidePanel.value = panel;
    mobilePanelOpen.value = true;
};

const editorMedia = (groupKey: string | null): MediaItem[] => {
    const group = findGroup(groupKey);

    return group ? groupDestination(group).media : composition.media.value;
};
const editorContentTypes = (groupKey: string | null): string[] => {
    const group = findGroup(groupKey);

    return (group ? [group] : groupKey === null ? networkGroups.value : [])
        .map((candidate) => groupDestination(candidate).content_type)
        .filter((contentType) => Boolean(contentType));
};

const openEditor = (
    target: { groupKey: string | null },
    index: number,
    tab: EditorTab,
): void => {
    if (cropUploading.value) return;
    if (target.groupKey !== null && !findGroup(target.groupKey)) return;
    const rules = rulesFor(editorContentTypes(target.groupKey));
    const indexes = editorMedia(target.groupKey).flatMap((candidate, position) =>
        editorTabsFor(candidate, rules).length > 0 ? [position] : [],
    );
    if (!indexes.includes(index)) return;
    cropError.value = false;
    cropTarget.value = {
        groupKey: target.groupKey,
        indexes,
        initialIndex: indexes.indexOf(index),
        tab,
    };
    cropping.value = true;
};

const cropContentTypes = computed(() =>
    cropTarget.value ? editorContentTypes(cropTarget.value.groupKey) : [],
);
const cropItems = computed(() => {
    if (!cropTarget.value) return [];
    const media = editorMedia(cropTarget.value.groupKey);

    return cropTarget.value.indexes.flatMap((index) =>
        media[index] ? [media[index]] : [],
    );
});
const cropAspectBounds = computed(() => {
    if (!cropTarget.value?.groupKey) return {};
    const rules = getMediaRulesForContentType(cropContentTypes.value[0] ?? '');

    return { min: rules.aspectRatioMin, max: rules.aspectRatioMax };
});

const onMediaEdited = async (changes: MediaEditChange[]): Promise<void> => {
    const target = cropTarget.value;
    if (!target) return;
    await swapEditedMedia({
        changes,
        indexes: target.indexes,
        items: () => editorMedia(target.groupKey),
        write: (items) => {
            if (target.groupKey === null) {
                composition.media.value = items;
            } else {
                composition.setGroupOverride(target.groupKey, 'media', items);
            }
        },
    });
    cropTarget.value = null;
};

const submit = (status: PostComposition['status']): void => {
    if (
        !canSubmit.value ||
        (status !== 'draft' && hasBlockingIssues.value)
    )
        return;
    submittedMedia.value = snapshotMedia();
    const queue =
        status === 'scheduled' &&
        (scheduleMode.value === 'next' || scheduleMode.value === 'top')
            ? scheduleMode.value
            : null;
    const payload = composition.materialize(status, queue);
    if (status === 'scheduled' && !queue) {
        if (!scheduledInstant) return;
        payload.scheduled_at = scheduledInstant;
        if (isInitialQueueSlot()) payload.queue_slot = scheduledInstant;
    }
    try {
        localStorage.setItem(
            'trypost:composer:last-used-accounts',
            JSON.stringify(composition.selectedAccountIds.value),
        );
    } catch {
        // Browser storage is optional; publishing must still work without it.
    }
    autosave.flush();
    emit('submit', payload, createAnother.value && !props.postId);
};

const submitSelectedSchedule = (): void => {
    submit(scheduleMode.value === 'now' ? 'publishing' : 'scheduled');
};

const isScheduleModeDisabled = (mode: ComposerScheduleMode): boolean =>
    (mode === 'next' || mode === 'top') && queueBlocked.value;
const selectScheduleMode = (mode: ComposerScheduleMode): void => {
    if (isScheduleModeDisabled(mode)) return;
    if (mode === 'custom') {
        schedulePanel.value = 'picker';
        return;
    }
    scheduleMenuOpen.value = false;
    scheduleModeChosen.value = true;
    scheduleMode.value = mode;
};

const confirmScheduledAt = (value: string): void => {
    composition.scheduledAt.value = value;
    scheduleModeChosen.value = true;
    scheduleMode.value = 'custom';
    scheduleMenuOpen.value = false;
};

const autosaveEnabled =
    !props.initialPost && !props.postId && !props.initialDraft;
const autosave = useComposerAutosave(
    computed(() =>
        composerAutosaveKey(
            page.props.auth?.user?.id,
            page.props.auth?.currentWorkspace?.id,
        ),
    ),
);
const resumeAccounts = computed(() =>
    (autosave.saved.value?.accountIds ?? []).flatMap((id) => {
        const account = props.socialAccounts.find(
            (candidate) => candidate.id === id,
        );

        return account ? [account] : [];
    }),
);
const resumeMediaCount = computed(
    () =>
        (autosave.saved.value?.media ?? []).filter(
            (item) =>
                !isExpiredUpload(
                    item,
                    mediaUploadLimits().upload_retention_hours,
                ),
        ).length,
);
const resumePreview = computed(
    () =>
        (autosave.saved.value?.content ?? '')
            .split('\n')
            .map((line) => line.trim())
            .find(Boolean) ?? '',
);
const resumeOpen = ref(
    autosaveEnabled &&
        Boolean(
            resumePreview.value ||
                resumeAccounts.value.length ||
                resumeMediaCount.value,
        ),
);
const autosaveSnapshot = (): AutosaveSnapshot => ({
    version: 1,
    savedAt: dayjs().toISOString(),
    content: composition.content.value,
    overrides: Object.fromEntries(
        Object.entries(composition.overrides.value).map(
            ([id, { media, ...override }]) => [
                id,
                media
                    ? { ...override, media: media.map(toAutosaveMedia) }
                    : override,
            ],
        ),
    ),
    accountIds: [...composition.selectedAccountIds.value],
    media: composition.media.value.map(toAutosaveMedia),
    labelIds: [...composition.labelIds.value],
    scheduleMode: scheduleModeChosen.value ? scheduleMode.value : null,
    scheduledAt:
        scheduleModeChosen.value && scheduleMode.value === 'custom'
            ? composition.scheduledAt.value || null
            : null,
});
const compositionIsEmpty = (): boolean =>
    !composition.content.value.trim() &&
    composition.media.value.length === 0 &&
    composition.selectedAccountIds.value.length === 0 &&
    composition.labelIds.value.length === 0;
if (autosaveEnabled) {
    watch(
        [
            composition.content,
            composition.media,
            composition.overrides,
            composition.selectedAccountIds,
            composition.labelIds,
            scheduleMode,
            scheduleModeChosen,
            composition.scheduledAt,
        ],
        () => {
            if (resumeOpen.value || props.submitting) return;
            if (compositionIsEmpty()) {
                autosave.clear();
                return;
            }
            autosave.save(autosaveSnapshot());
        },
        { deep: true },
    );
}
const resumeUnfinishedPost = (): void => {
    const snapshot = autosave.saved.value;
    resumeOpen.value = false;
    if (!snapshot) return;
    const retentionHours = mediaUploadLimits().upload_retention_hours;
    const restoreMedia = (items: AutosaveMediaRef[]): MediaItem[] =>
        items
            .filter((item) => !isExpiredUpload(item, retentionHours))
            .map(fromAutosaveMedia);
    [...composition.selectedAccountIds.value].forEach((id) =>
        composition.toggleAccount(id),
    );
    snapshot.accountIds.forEach((id) => composition.toggleAccount(id));
    const selectedIds = composition.selectedAccountIds.value;
    composition.content.value = snapshot.content;
    composition.media.value = restoreMedia(snapshot.media);
    composition.labelIds.value = snapshot.labelIds.filter((id) =>
        props.labels.some((label) => label.id === id),
    );
    composition.overrides.value = Object.fromEntries(
        Object.entries(snapshot.overrides)
            .filter(([id]) => selectedIds.includes(id))
            .map(([id, { media, ...override }]) => [
                id,
                Array.isArray(media)
                    ? { ...override, media: restoreMedia(media) }
                    : override,
            ]),
    );
    composition.normalizeGroups();
    const [firstGroup] = composition.networkGroups.value;
    if (firstGroup) openNetwork(firstGroup.key);
    const mode = snapshot.scheduleMode;
    if (
        mode === 'now' ||
        ((mode === 'next' || mode === 'top') && !queueBlocked.value)
    ) {
        scheduleModeChosen.value = true;
        scheduleMode.value = mode;
    } else if (
        mode === 'custom' &&
        snapshot.scheduledAt &&
        dayjs.tz(snapshot.scheduledAt, composerTimezone.value).isAfter(dayjs())
    ) {
        composition.scheduledAt.value = snapshot.scheduledAt;
        scheduleModeChosen.value = true;
        scheduleMode.value = 'custom';
    }
};
const updateResumeOpen = (open: boolean): void => {
    if (!open) {
        resumeOpen.value = false;
    }
};

const discardUnfinishedPost = (): void => {
    autosave.clear();
    resumeOpen.value = false;
};

const close = (): void => emit('update:open', false);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="motion-resize top-0 left-0 flex h-dvh max-h-dvh w-screen max-w-none translate-x-0 translate-y-0 flex-col gap-0 overflow-hidden rounded-none p-0 sm:top-1/2 sm:left-1/2 sm:max-w-none sm:-translate-x-1/2 sm:-translate-y-1/2"
            :class="
                expandedDialog
                    ? 'sm:h-dvh sm:max-h-dvh sm:w-screen sm:rounded-none'
                    : 'sm:h-[calc(100dvh-3rem)] sm:max-h-[888px] sm:w-[min(1100px,calc(100vw-3rem))] sm:rounded-2xl'
            "
            :show-close-button="false"
            :aria-describedby="undefined"
            data-testid="post-composer-dialog"
            :disable-outside-pointer-events="!isGooglePickerOpen"
            @interact-outside="isGooglePickerOpen && $event.preventDefault()"
        >
            <header
                data-testid="composer-header"
                class="flex shrink-0 flex-row flex-wrap items-center justify-between gap-2 border-b px-4 py-3 sm:min-h-16 sm:flex-nowrap sm:py-4 sm:ps-8 sm:pe-6"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <Button
                        v-if="customizing"
                        type="button"
                        variant="ghost"
                        size="icon"
                        data-testid="composer-back"
                        :aria-label="$t('common.back')"
                        @click="requestBackConfirmation"
                        ><IconArrowLeft class="size-4"
                    /></Button>
                    <DialogTitle class="font-sans">{{
                        initialPost || initialDraft
                            ? $t('posts.edit.title')
                            : $t('posts.create.title')
                    }}</DialogTitle>
                    <LabelFilter
                        v-model="composition.labelIds.value"
                        :labels="availableLabels"
                        :show-untagged="false"
                        test-id="composer-label"
                        align="start"
                        @created="addLabel"
                    >
                        <template #trigger>
                            <Button
                                type="button"
                                variant="outline"
                                size="default"
                                class="max-w-72"
                                data-testid="composer-tags-trigger"
                            >
                                <IconTag
                                    v-if="!selectedLabels.length"
                                    class="size-4"
                                />
                                <span v-if="!selectedLabels.length">{{
                                    $t('posts.edit.labels')
                                }}</span>
                                <span
                                    v-else
                                    class="flex min-w-0 items-center gap-1.5 overflow-hidden"
                                >
                                    <span
                                        v-for="label in selectedLabels.slice(
                                            0,
                                            2,
                                        )"
                                        :key="label.id"
                                        class="flex min-w-0 items-center gap-1"
                                    >
                                        <span
                                            class="size-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor: label.color,
                                            }"
                                        />
                                        <span class="truncate">{{
                                            label.name
                                        }}</span>
                                    </span>
                                    <span
                                        v-if="selectedLabels.length > 2"
                                        class="shrink-0 text-muted-foreground"
                                        >+{{ selectedLabels.length - 2 }}</span
                                    >
                                </span>
                                <IconChevronDown
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                            </Button>
                        </template>
                    </LabelFilter>
                </div>
                <div
                    class="flex w-full min-w-0 items-center justify-end gap-2 sm:w-auto"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        :aria-pressed="sidePanel === 'templates'"
                        data-testid="composer-templates-toggle"
                        class="max-sm:w-8 max-sm:px-0"
                        :class="
                            sidePanel === 'templates'
                                ? 'bg-primary-subtle text-primary-text hover:bg-primary-subtle hover:text-primary-text'
                                : 'text-muted-foreground'
                        "
                        @click="showSidePanel('templates')"
                        ><IconTemplate class="size-4" /><span class="max-sm:sr-only">{{
                            $t('create.templates.panel.title')
                        }}</span></Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        :aria-pressed="sidePanel === 'assistant'"
                        data-testid="composer-ai-assistant"
                        class="max-sm:w-8 max-sm:px-0"
                        :class="
                            sidePanel === 'assistant'
                                ? 'bg-primary-subtle text-primary-text hover:bg-primary-subtle hover:text-primary-text'
                                : 'text-muted-foreground'
                        "
                        @click="showSidePanel('assistant')"
                        ><IconWand class="size-4" /><span class="max-sm:sr-only">{{
                            $t('posts.composer.assistant_title')
                        }}</span></Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        :aria-pressed="sidePanel === 'preview'"
                        data-testid="composer-preview-toggle"
                        class="max-sm:w-8 max-sm:px-0"
                        :class="
                            sidePanel === 'preview'
                                ? 'bg-primary-subtle text-primary-text hover:bg-primary-subtle hover:text-primary-text'
                                : 'text-muted-foreground'
                        "
                        @click="showSidePanel('preview')"
                        ><IconEye class="size-4" /><span class="max-sm:sr-only">{{
                            $t('posts.edit.tabs.preview')
                        }}</span></Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="
                            expandedDialog
                                ? $t('posts.composer.collapse')
                                : $t('posts.composer.expand')
                        "
                        data-testid="composer-expand-dialog"
                        @click="toggleExpandedDialog"
                    >
                        <IconArrowsMinimize
                            v-if="expandedDialog"
                            class="size-4"
                        />
                        <IconArrowsMaximize v-else class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('posts.edit.cancel')"
                        data-testid="composer-close"
                        @click="close"
                        ><IconX class="size-4"
                    /></Button>
                </div>
            </header>

            <div
                class="grid min-h-0 flex-1 md:grid-cols-[minmax(0,1fr)_380px]"
            >
                <div
                    class="min-h-0 overflow-y-auto px-4 pt-4 pb-5 sm:px-8"
                    :class="[
                        'flex flex-col gap-6',
                        mobilePanelOpen ? 'max-md:hidden' : '',
                    ]"
                >
                    <p
                        v-if="Object.keys(errors).length"
                        data-testid="composer-errors"
                        class="rounded-lg border border-destructive bg-destructive/10 p-3 text-sm text-destructive"
                    >
                        {{ Object.values(errors)[0] }}
                    </p>
                    <div
                        class="relative mx-auto flex h-12 w-full max-w-[744px] shrink-0 items-center gap-2"
                    >
                        <div
                            class="-my-3 flex min-w-0 gap-4 overflow-x-auto py-3 pr-3 empty:hidden"
                            data-testid="composer-accounts"
                        >
                            <ComposerAccountChip
                                v-for="account in selectedAccounts"
                                :key="account.id"
                                :account="account"
                                :active="previewAccountId === account.id"
                                :removable="!initialPost"
                                @focus="openNetwork(account.platform, account.id)"
                                @remove="selectAccount(account)"
                            />
                        </div>
                        <Popover
                            v-if="!initialPost"
                            v-model:open="accountPickerOpen"
                        >
                            <PopoverAnchor as-child>
                                <span
                                    class="pointer-events-none absolute inset-x-0 top-0 h-full"
                                    aria-hidden="true"
                                />
                            </PopoverAnchor>
                            <PopoverTrigger as-child>
                                <Button
                                    type="button"
                                    variant="outline"
                                    :size="
                                        selectedAccounts.length
                                            ? 'icon-lg'
                                            : 'lg'
                                    "
                                    class="shrink-0"
                                    :aria-label="
                                        $t(
                                            'posts.edit.platforms_dialog.title',
                                        )
                                    "
                                    data-testid="composer-add-account"
                                >
                                    <IconPlus class="size-4" />
                                    <span v-if="!selectedAccounts.length">{{
                                        $t('posts.edit.tabs.channels')
                                    }}</span>
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent
                                class="w-[min(380px,calc(100vw-2rem))] p-3"
                                :side-offset="8"
                                align="start"
                            >
                                <FilterEmptyState
                                    v-if="!socialAccounts.length"
                                    :icon="IconLayoutGrid"
                                    :title="$t('posts.no_channels')"
                                    test-id="composer-accounts-empty"
                                >
                                    <Button
                                        v-if="canManageAccounts"
                                        type="button"
                                        size="sm"
                                        data-testid="composer-accounts-connect"
                                        @click="openConnectDialog()"
                                    >
                                        <IconPlus class="size-4" />
                                        {{ $t('channels.connect') }}
                                    </Button>
                                </FilterEmptyState>
                                <ComposerAccountOptions
                                    v-else
                                    v-model:search="accountSearch"
                                    :accounts="filteredAccounts"
                                    :selected-ids="
                                        composition.selectedAccountIds.value
                                    "
                                    @toggle="selectAccount"
                                    @toggle-all="selectAccounts"
                                />
                            </PopoverContent>
                        </Popover>
                        <template
                            v-if="
                                !selectedAccounts.length &&
                                socialAccounts.length > 1
                            "
                        >
                            <Button
                                v-if="recentlyUsedAccounts.length"
                                type="button"
                                variant="outline"
                                size="lg"
                                class="shrink-0 border-dashed ps-4 pe-2"
                                data-testid="composer-last-used"
                                @click="
                                    selectAccounts(recentlyUsedAccounts)
                                "
                                >{{ $t('posts.composer.last_used') }}
                                <ComposerAccountStack
                                    :accounts="recentlyUsedAccounts"
                                />
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                class="shrink-0 border-dashed ps-4 pe-2"
                                data-testid="composer-all-accounts"
                                @click="selectAccounts(socialAccounts)"
                                >{{ $t('sidebar.posts.all') }}
                                <ComposerAccountStack
                                    :accounts="socialAccounts"
                                />
                            </Button>
                        </template>
                    </div>
                    <div
                        class="mx-auto flex w-full max-w-[744px] flex-1 flex-col gap-3"
                    >
                        <ComposerNetworkCard
                            v-if="!networkGroups.length || sharedStep"
                            class="flex-1"
                            data-testid="composer-base"
                            test-id-prefix="composer-base"
                            caption-test-id="composer-base-content"
                            :content="composition.content.value"
                            @update:content="updateContent"
                            @paste="onMediaPasted($event, null)"
                            @drop="onMediaDropped($event, null)"
                            @open-templates="showSidePanel('templates')"
                        >
                            <template #media>
                                <MediaTray
                                    test-id-prefix="composer"
                                    :items="composition.media.value"
                                    :limits="mediaUploadLimits()"
                                    :uploader="uploaderFor(null)"
                                    :suggested="suggestionsFor(null)"
                                    :item-errors="mediaErrorsFor(null)"
                                    :content-types="editorContentTypes(null)"
                                    :disabled="cropUploading"
                                    @update:suggested="setSuggestions(null, $event)"
                                    @update:items="updateMedia"
                                    @import-started="onImportStarted($event, null)"
                                    @edit="
                                        openEditor(
                                            { groupKey: null },
                                            $event.index,
                                            $event.tab,
                                        )
                                    "
                                />
                                <p
                                    v-if="cropError"
                                    class="mt-2 text-sm text-destructive"
                                    data-testid="composer-crop-error"
                                >
                                    {{ $t('posts.composer.crop_upload_failed') }}
                                </p>
                            </template>
                            <template #toolbar>
                                <ComposerEditorToolbar
                                    test-id-prefix="composer-base"
                                    :signatures="availableSignatures"
                                    @import-started="onImportStarted($event, null)"
                                    @open-unsplash="openUnsplash(null)"
                                    @select-emoji="appendEmoji($event, null)"
                                    @select-signature="appendSignature($event, null)"
                                    @save-signature="saveSignature"
                                />
                            </template>
                        </ComposerNetworkCard>
                        <template
                            v-for="group in sharedStep ? [] : networkGroups"
                            :key="group.key"
                        >
                            <ComposerNetworkCard
                                v-if="group.key === openGroupKey"
                                class="flex-1"
                                data-testid="composer-customization"
                                :platform="group.platform"
                                :test-id-prefix="`composer-${group.anchor.id}`"
                                :caption-test-id="`composer-caption-${group.anchor.id}`"
                                :type-test-id-prefix="`composer-type-${group.anchor.id}`"
                                :content="groupDestination(group).content"
                                :content-type-options="
                                    contentTypeOptionsFor(group)
                                "
                                :has-media="groupDestination(group).media.length > 0"
                                :content-type="groupDestination(group).content_type"
                                :disabled="cropUploading"
                                :caption-collapsed="
                                    threadReplies(group)[threadActive] !== undefined
                                "
                                @expand-caption="deselectThread"
                                @update:content="
                                    composition.setGroupOverride(
                                        group.key,
                                        'content',
                                        $event,
                                    )
                                "
                                @update:content-type="
                                    composition.setGroupOverride(
                                        group.key,
                                        'content_type',
                                        $event,
                                    )
                                "
                                @paste="onMediaPasted($event, group.key)"
                                @drop="onMediaDropped($event, group.key)"
                                @open-templates="showSidePanel('templates')"
                            >
                                <template
                                    v-if="group.platform === Platform.GoogleBusiness"
                                    #header
                                >
                                    <GoogleBusinessTopicTypeRadios
                                        :model-value="
                                            resolveGoogleBusinessTopicType(
                                                groupDestination(group).meta
                                                    ?.topic_type,
                                            )
                                        "
                                        :disabled="cropUploading"
                                        @update:model-value="
                                            composition.setGroupOverride(
                                                group.key,
                                                'meta',
                                                googleBusinessTopicMeta(
                                                    groupDestination(group).meta,
                                                    $event,
                                                ),
                                            )
                                        "
                                    />
                                </template>
                                <template #warnings>
                                    <div
                                        v-if="requiresMediaWarning(group.anchor)"
                                        role="status"
                                        class="flex items-center gap-2 rounded-md bg-warning/15 px-3 py-1.5 text-sm"
                                        :data-testid="`composer-media-warning-${group.anchor.id}`"
                                    >
                                        <IconAlertTriangle
                                            class="size-4 text-warning"
                                        />
                                        {{
                                            $t(
                                                'posts.form.warnings.requires_media',
                                            )
                                        }}
                                    </div>
                                    <div
                                        v-if="
                                            destinationIssues(group.anchor).some(
                                                (issue) =>
                                                    issue.key ===
                                                    'posts.form.warnings.text_only',
                                            )
                                        "
                                        role="status"
                                        class="flex items-center gap-2 rounded-md bg-warning/15 px-3 py-1.5 text-sm"
                                        :data-testid="`composer-text-only-warning-${group.anchor.id}`"
                                    >
                                        <IconAlertTriangle
                                            class="size-4 shrink-0 text-warning"
                                        />
                                        {{ $t('posts.form.warnings.text_only') }}
                                    </div>
                                    <div
                                        v-if="(groupHashtagsRemaining(group) ?? 0) < 0"
                                        role="status"
                                        class="flex items-center gap-2 rounded-md bg-warning/15 px-3 py-1.5 text-sm"
                                        :data-testid="`composer-hashtag-warning-${group.anchor.id}`"
                                    >
                                        <IconAlertTriangle
                                            class="size-4 text-warning"
                                        />
                                        {{
                                            $t(
                                                'posts.form.hashtags_exceed_platform',
                                                {
                                                    platform: getPlatformLabel(
                                                        group.platform,
                                                    ),
                                                    limit: String(
                                                        platformConfigs[
                                                            group.anchor.id
                                                        ]?.maxHashtags,
                                                    ),
                                                },
                                            )
                                        }}
                                    </div>
                                    <ChannelMediaWarnings
                                        :platform="group.platform"
                                        :content-type="
                                            groupDestination(group).content_type
                                        "
                                        :media="groupDestination(group).media"
                                        :media-editing="true"
                                        :disabled="cropUploading"
                                        @edit:media="
                                            openEditor(
                                                { groupKey: group.key },
                                                $event,
                                                'edit',
                                            )
                                        "
                                    />
                                </template>
                                <template #media>
                                    <ComposerLinkCard
                                        v-if="openLinkCard"
                                        class="mb-3"
                                        :card="openLinkCard"
                                        :test-id-prefix="`composer-link-card-${group.anchor.id}`"
                                        :removable="
                                            linkPreviewDroppable(group.platform)
                                        "
                                        :replacing="linkCardMediaHttp.processing"
                                        :disabled="cropUploading"
                                        @remove="dropLinkCard(group)"
                                        @replace="replaceLinkCardWithMedia(group)"
                                    />
                                    <MediaTray
                                        v-show="!openLinkCard || trayHasActivity(group.key)"
                                        :test-id-prefix="`composer-${group.anchor.id}`"
                                        :items="groupDestination(group).media"
                                        :limits="mediaUploadLimits()"
                                        :uploader="uploaderFor(group.key)"
                                        :suggested="suggestionsFor(group.key)"
                                        :item-errors="mediaErrorsFor(group.key)"
                                        :content-types="
                                            editorContentTypes(group.key)
                                        "
                                        :disabled="cropUploading"
                                        @update:suggested="
                                            setSuggestions(group.key, $event)
                                        "
                                        @update:items="
                                            composition.setGroupOverride(
                                                group.key,
                                                'media',
                                                $event,
                                            )
                                        "
                                        @edit="
                                            openEditor(
                                                { groupKey: group.key },
                                                $event.index,
                                                $event.tab,
                                            )
                                        "
                                        @import-started="
                                            onImportStarted($event, group.key)
                                        "
                                    />
                                    <p
                                        v-if="cropError"
                                        class="mt-2 text-sm text-destructive"
                                        :data-testid="`composer-${group.anchor.id}-crop-error`"
                                    >
                                        {{
                                            $t(
                                                'posts.composer.crop_upload_failed',
                                            )
                                        }}
                                    </p>
                                </template>
                                <template
                                    v-if="threadReplies(group).length"
                                    #replies
                                >
                                    <ThreadRepliesField
                                        v-model:active="threadActive"
                                        :model-value="threadReplies(group)"
                                        :platform="group.platform"
                                        :limit="threadReplyLimit(group) ?? Infinity"
                                        :errors="threadReplyErrors(group)"
                                        :disabled="cropUploading"
                                        @update:model-value="
                                            setThreadReplies(group, $event)
                                        "
                                    />
                                </template>
                                <template #toolbar>
                                    <ComposerEditorToolbar
                                        :test-id-prefix="`composer-${group.anchor.id}`"
                                        :signatures="availableSignatures"
                                        @import-started="
                                            onImportStarted($event, group.key)
                                        "
                                        @open-unsplash="openUnsplash(group.key)"
                                        @select-emoji="
                                            appendEmoji($event, group.key)
                                        "
                                        @select-signature="
                                            appendSignature($event, group.key)
                                        "
                                        @save-signature="saveSignature"
                                    >
                                        <span
                                            v-if="activeRemaining(group) !== null"
                                            :data-testid="`composer-char-count-${group.anchor.id}`"
                                            class="rounded-sm border px-1 py-0.5 text-xs leading-3 tabular-nums"
                                            :class="
                                                (activeRemaining(group) ?? 0) < 0
                                                    ? 'border-destructive font-medium text-destructive-text'
                                                    : 'border-border-strong text-subtle-foreground'
                                            "
                                        >
                                            {{ activeRemaining(group) }}
                                        </span>
                                        <template v-if="supportsThread(group)">
                                            <Button
                                                v-if="!threadReplies(group).length"
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                class="ms-1 text-primary-text"
                                                data-testid="thread-start"
                                                :disabled="cropUploading"
                                                @click="addThreadReply(group)"
                                            >
                                                <IconCirclePlus class="size-4" />
                                                <span data-single-line>{{
                                                    $t('posts.form.thread.start')
                                                }}</span>
                                            </Button>
                                            <Button
                                                v-else-if="
                                                    threadReplies(group).length <
                                                    THREAD_MAX_REPLIES
                                                "
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                class="ms-1 size-7 text-primary-text"
                                                data-testid="thread-add-reply"
                                                :aria-label="
                                                    $t('posts.form.thread.add')
                                                "
                                                :disabled="cropUploading"
                                                @click="addThreadReply(group)"
                                            >
                                                <IconCirclePlus class="size-4" />
                                            </Button>
                                        </template>
                                        <span
                                            v-if="(groupHashtagsRemaining(group) ?? -1) >= 0"
                                            :data-testid="`composer-hashtags-remaining-${group.anchor.id}`"
                                            data-single-line
                                            class="rounded-sm border border-border-strong px-1 py-0.5 text-xs leading-3 text-subtle-foreground tabular-nums"
                                        >
                                            {{
                                                $t(
                                                    'posts.composer.hashtags_remaining',
                                                    {
                                                        count: String(
                                                            groupHashtagsRemaining(
                                                                group,
                                                            ),
                                                        ),
                                                    },
                                                )
                                            }}
                                        </span>
                                    </ComposerEditorToolbar>
                                </template>
                                <template #settings>
                                    <template
                                        v-if="
                                            ACCOUNT_SCOPED_SETTINGS.includes(
                                                group.platform,
                                            )
                                        "
                                    >
                                        <section
                                            v-for="account in group.accounts"
                                            :key="account.id"
                                            class="space-y-2"
                                            :data-testid="`composer-account-settings-${account.id}`"
                                        >
                                            <p
                                                v-if="group.accounts.length > 1"
                                                class="truncate text-[13px] font-medium text-muted-foreground"
                                            >
                                                {{
                                                    account.display_label ||
                                                    account.display_name
                                                }}
                                            </p>
                                            <ComposerNetworkSettings
                                                :account="account"
                                                :destination="
                                                    composition.resolvedDestination(
                                                        account,
                                                    )
                                                "
                                                :platform-index="
                                                    selectedAccounts.indexOf(account)
                                                "
                                                :platform-config="
                                                    platformConfigs[account.id] ??
                                                    null
                                                "
                                                :pinterest-boards="
                                                    pinterestBoards[account.id] ??
                                                    null
                                                "
                                                :tiktok-creator-info="
                                                    tiktokCreatorInfos[
                                                        account.id
                                                    ] ?? null
                                                "
                                                @update:meta="
                                                    composition.setGroupOverride(
                                                        group.key,
                                                        'meta',
                                                        $event,
                                                        account.id,
                                                    )
                                                "
                                            />
                                        </section>
                                    </template>
                                    <ComposerNetworkSettings
                                        v-else
                                        :account="group.anchor"
                                        :destination="groupDestination(group)"
                                        :platform-index="
                                            selectedAccounts.indexOf(group.anchor)
                                        "
                                        :platform-config="
                                            platformConfigs[group.anchor.id] ??
                                            null
                                        "
                                        @update:meta="
                                            composition.setGroupOverride(
                                                group.key,
                                                'meta',
                                                $event,
                                            )
                                        "
                                    />
                                </template>
                            </ComposerNetworkCard>
                            <ComposerNetworkRow
                                v-else
                                :platform="group.platform"
                                :anchor-id="group.anchor.id"
                                :text="
                                    groupDestination(group).content ||
                                    group.anchor.display_name ||
                                    group.anchor.username
                                "
                                :issue-count="groupIssues(group).length"
                                :issue-label="groupIssueLabel(group)"
                                @open="openNetwork(group.key)"
                            />
                        </template>
                    </div>
                </div>

                <aside
                    class="min-h-0 flex-col bg-muted"
                    :class="mobilePanelOpen ? 'flex' : 'hidden md:flex'"
                >
                    <div class="border-b px-4 py-2 md:hidden">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-testid="composer-mobile-compose"
                            @click="closeMobilePanel"
                        >
                            <IconArrowLeft class="size-4" />
                            {{ $t('posts.edit.tabs.compose') }}
                        </Button>
                    </div>
                    <ComposerTemplatesPanel
                        v-if="sidePanel === 'templates'"
                        @select="insertAssistantText"
                    />
                    <template v-else-if="sidePanel === 'assistant'">
                        <h3
                            class="shrink-0 px-8 pt-[22px] pb-[18px] text-base leading-5 font-medium"
                        >
                            {{ $t('posts.composer.assistant_title') }}
                        </h3>
                        <div
                            class="min-h-0 flex-1 overflow-y-auto px-8 pb-6"
                            data-testid="composer-assistant-panel"
                        >
                            <WritingAssistantPanel
                                :key="
                                    openGroupKey
                                        ? `group-${openGroupKey}`
                                        : 'shared'
                                "
                                :content="assistantContent"
                                :channel="assistantChannel"
                                @insert="insertAssistantText"
                                @replace="writeAssistantTarget"
                            />
                        </div>
                    </template>
                    <template v-else>
                        <PreviewPanelTitle
                            :title="
                                previewAccount && !sharedStep
                                    ? $t('posts.composer.network_preview', {
                                          network: getPlatformLabel(
                                              previewAccount.platform,
                                          ),
                                      })
                                    : $t('posts.composer.post_previews')
                            "
                        />
                        <div
                            class="min-h-0 flex-1 space-y-10 overflow-x-hidden overflow-y-auto px-8 pb-8"
                            data-testid="composer-previews-scroll"
                        >
                            <template v-if="sharedStep && hasSharedPreview">
                                <section
                                    v-for="group in networkGroups"
                                    :key="group.key"
                                    data-testid="composer-preview-card"
                                    class="space-y-3"
                                >
                                    <h4
                                        class="flex items-center gap-2.5 text-[15px] leading-5 text-muted-foreground"
                                        data-testid="composer-preview-label"
                                    >
                                        <PlatformLogo
                                            :platform="group.platform"
                                            :size="18"
                                            :title="null"
                                            class="opacity-80 grayscale"
                                        />
                                        {{ getPlatformLabel(group.platform) }}
                                    </h4>
                                    <PlatformPreview
                                        data-testid="composer-preview-frame"
                                        :platform="group.platform"
                                        :social-account="group.anchor"
                                        :content="groupDestination(group).content"
                                        :media="groupDestination(group).media"
                                        :content-type="
                                            groupDestination(group).content_type
                                        "
                                        :meta="groupDestination(group).meta"
                                    />
                                </section>
                            </template>
                            <div
                                v-else-if="
                                    !sharedStep &&
                                    previewAccount &&
                                    previewDestination &&
                                    (previewDestination.content.trim() ||
                                        previewDestination.media.length)
                                "
                            >
                                <PlatformPreview
                                    data-testid="composer-preview-frame"
                                    :platform="previewAccount.platform"
                                    :social-account="previewAccount"
                                    :content="previewDestination.content"
                                    :media="previewDestination.media"
                                    :content-type="
                                        previewDestination.content_type
                                    "
                                    :meta="previewDestination.meta"
                                />
                            </div>
                            <EmptyState
                                v-else
                                class="h-full min-h-64"
                                data-testid="composer-empty-preview"
                                :title="$t('posts.composer.preview_empty')"
                            >
                                <template #illustration>
                                    <PublishEmptyIllustration />
                                </template>
                            </EmptyState>
                        </div>
                    </template>
                </aside>
            </div>

            <footer
                class="flex shrink-0 flex-col gap-3 border-t px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8"
            >
                <div class="flex flex-1 items-center gap-3.5">
                    <label
                        v-if="!postId"
                        class="flex cursor-pointer items-center gap-2 text-sm font-medium"
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
                        size="lg"
                        data-testid="composer-save-draft"
                        :disabled="!canSubmit"
                        @click="submit('draft')"
                        >{{
                            $t(
                                isBatch
                                    ? 'posts.composer.save_drafts'
                                    : 'posts.composer.save_draft',
                            )
                        }}</Button
                    >
                    <p
                        v-if="mediaFailed"
                        role="alert"
                        data-testid="composer-upload-blocked"
                        class="text-sm text-destructive-text"
                    >
                        {{ $t('posts.composer.upload_blocked') }}
                    </p>
                </div>
                <Button
                    v-if="socialAccounts.length === 0"
                    type="button"
                    size="lg"
                    class="rounded-xl"
                    data-testid="composer-connect-channel"
                    @click="openConnectDialog()"
                    >{{ $t('posts.composer.connect_to_post') }}</Button
                >
                <Popover v-else v-model:open="scheduleMenuOpen">
                    <PopoverAnchor as-child>
                        <div class="flex items-center gap-0">
                            <PopoverTrigger as-child>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="lg"
                                    class="rounded-l-xl rounded-r-none ps-3 pe-2"
                                    data-testid="composer-schedule-trigger"
                                >
                                    <component
                                        :is="scheduleTriggerIcon.component"
                                        class="size-4"
                                        data-testid="composer-schedule-trigger-icon"
                                        :data-icon="scheduleTriggerIcon.name"
                                    />{{
                                        scheduleDateLabel ??
                                        $t(scheduleLabelKey)
                                    }}<IconChevronUp
                                        class="size-4"
                                        data-testid="composer-schedule-trigger-chevron"
                                    />
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent
                                align="end"
                                side="top"
                                :class="
                                    schedulePanel === 'picker'
                                        ? 'w-[21rem] p-0'
                                        : 'w-80 space-y-0.5 p-3'
                                "
                            >
                                <ComposerSchedulePicker
                                    v-if="schedulePanel === 'picker'"
                                    :key="composerTimezone"
                                    :model-value="composition.scheduledAt.value"
                                    :timezone="composerTimezone"
                                    :posting-schedule="slotSchedule"
                                    :taken-slots="takenSlots"
                                    @back="showScheduleMenu"
                                    @confirm="confirmScheduledAt"
                                />
                                <template v-else>
                                <TooltipProvider :delay-duration="150">
                                    <div
                                        v-for="option in scheduleOptions"
                                        :key="option.mode"
                                        class="group/option relative"
                                        :data-testid="`composer-schedule-row-${option.mode}`"
                                    >
                                        <Tooltip
                                            :disabled="
                                                !isScheduleModeDisabled(
                                                    option.mode,
                                                )
                                            "
                                        >
                                            <TooltipTrigger as-child>
                                                <span class="block">
                                                        <button
                                                            type="button"
                                                            :data-testid="`composer-schedule-${option.mode}`"
                                                            :aria-pressed="
                                                                scheduleMode ===
                                                                option.mode
                                                            "
                                                            :disabled="
                                                                isScheduleModeDisabled(
                                                                    option.mode,
                                                                )
                                                            "
                                                            class="w-full space-y-1.5 rounded-md py-2 ps-3 pe-11 text-left text-sm transition-control outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                                            :class="
                                                                scheduleMode ===
                                                                option.mode
                                                                    ? 'bg-primary-subtle text-primary-text'
                                                                    : 'text-foreground enabled:hover:bg-accent focus-visible:bg-accent'
                                                            "
                                                            @click="
                                                                selectScheduleMode(
                                                                    option.mode,
                                                                )
                                                            "
                                                        >
                                                            <span
                                                                class="flex items-center gap-1 leading-[17.5px] font-emphasis"
                                                                ><IconCheck
                                                                    v-if="
                                                                        scheduleMode ===
                                                                        option.mode
                                                                    "
                                                                    class="size-4 shrink-0"
                                                                /><span
                                                                    v-else
                                                                    class="size-4 shrink-0"
                                                                    aria-hidden="true"
                                                                />{{
                                                                    $t(option.titleKey)
                                                                }}</span
                                                            >
                                                            <span
                                                                class="block ps-5 leading-[21px]"
                                                                >{{
                                                                    $t(
                                                                        option.descriptionKey,
                                                                    )
                                                                }}</span
                                                            >
                                                        </button>
                                                </span>
                                            </TooltipTrigger>
                                            <TooltipContent
                                                side="left"
                                                :data-testid="`composer-schedule-blocked-${option.mode}`"
                                            >
                                                {{
                                                    $t(
                                                        'posts.composer.queue.no_slots_tooltip',
                                                        {
                                                            channels:
                                                                accountsWithoutSlotsLabel,
                                                        },
                                                    )
                                                }}
                                            </TooltipContent>
                                        </Tooltip>
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <button
                                                    type="button"
                                                    :data-testid="`composer-schedule-default-${option.mode}`"
                                                    :aria-pressed="
                                                        currentDefaultPostAction ===
                                                        option.mode
                                                    "
                                                    :aria-label="
                                                        $t(
                                                            'posts.composer.queue.set_default',
                                                            {
                                                                option: $t(
                                                                    option.titleKey,
                                                                ),
                                                            },
                                                        )
                                                    "
                                                    class="absolute top-2 right-2 flex size-6 items-center justify-center rounded-md text-muted-foreground transition-[opacity,background-color,color] duration-150 outline-none hover:bg-sidebar-action-hover focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-ring"
                                                    :class="
                                                        currentDefaultPostAction ===
                                                        option.mode
                                                            ? 'opacity-100'
                                                            : 'opacity-0 group-hover/option:opacity-100'
                                                    "
                                                    @click.stop="
                                                        setDefaultPostAction(
                                                            option.mode,
                                                        )
                                                    "
                                                >
                                                    <IconStarFilled
                                                        v-if="
                                                            currentDefaultPostAction ===
                                                            option.mode
                                                        "
                                                        class="size-4"
                                                        :stroke-width="2.2"
                                                    />
                                                    <IconStar
                                                        v-else
                                                        class="size-4"
                                                        :stroke-width="2.2"
                                                    />
                                                </button>
                                            </TooltipTrigger>
                                            <TooltipContent side="top">
                                                {{
                                                    $t(
                                                        'posts.composer.queue.set_default',
                                                        {
                                                            option: $t(
                                                                option.titleKey,
                                                            ),
                                                        },
                                                    )
                                                }}
                                            </TooltipContent>
                                        </Tooltip>
                                    </div>
                                </TooltipProvider>
                                <div
                                    v-if="queueBlocked"
                                    data-testid="composer-queue-hint"
                                    class="mt-2 space-y-1 border-t border-border-strong px-3 pt-3 pb-1 text-xs text-muted-foreground"
                                >
                                    <p>{{ $t('posts.composer.queue.no_slots_hint') }}</p>
                                    <a
                                        v-if="accountsWithoutSlots.length === 1"
                                        :href="
                                            channelSettings.url(
                                                accountsWithoutSlots[0].id,
                                            )
                                        "
                                        data-testid="composer-queue-manage-slots"
                                        class="font-medium text-foreground underline underline-offset-2"
                                        >{{
                                            $t('posts.composer.queue.manage_slots')
                                        }}</a
                                    >
                                    <p v-else>
                                        {{
                                            $t(
                                                'posts.composer.queue.no_slots_channels',
                                                {
                                                    channels: accountsWithoutSlots
                                                        .map(
                                                            (account) =>
                                                                account.display_label ||
                                                                account.display_name,
                                                        )
                                                        .join(', '),
                                                },
                                            )
                                        }}
                                    </p>
                                </div>
                                </template>
                            </PopoverContent>
                        <Button
                            v-if="sharedStep"
                            type="button"
                            size="lg"
                            class="rounded-l-none rounded-r-xl"
                            data-testid="composer-next"
                            @click="customizeNetworks"
                            >{{ $t('posts.composer.customize_networks')
                            }}<IconArrowRight class="size-4"
                        /></Button>
                        <TooltipProvider v-else :delay-duration="150">
                            <Tooltip :disabled="!blockingIssue">
                                <TooltipTrigger as-child>
                                    <Button
                                        type="button"
                                        size="lg"
                                        class="rounded-l-none rounded-r-xl aria-disabled:cursor-not-allowed aria-disabled:bg-border-strong aria-disabled:text-subtle-foreground aria-disabled:hover:bg-border-strong aria-disabled:active:translate-y-0"
                                        data-testid="composer-submit"
                                        :data-schedule-mode="scheduleMode"
                                        :disabled="
                                            !canSubmit ||
                                            (scheduleMode === 'custom' &&
                                                !composition.scheduledAt.value)
                                        "
                                        :aria-disabled="
                                            hasBlockingIssues ? 'true' : undefined
                                        "
                                        @click="submitSelectedSchedule"
                                        ><IconLoader2
                                            v-if="submitting || cropUploading"
                                            class="size-4 animate-spin"
                                        />{{
                                            requiresApproval
                                                ? $t('posts.composer.request_approval')
                                                : isQueueMode && !postId
                                                ? isBatch
                                                    ? $t('posts.composer.queue.add_many', {
                                                          count: String(
                                                              selectedAccounts.length,
                                                          ),
                                                      })
                                                    : $t('posts.composer.queue.add')
                                                : scheduleMode === 'now'
                                                ? $t(
                                                      isBatch
                                                          ? 'posts.composer.publish_posts'
                                                          : 'posts.composer.publish_now',
                                                  )
                                                : $t(
                                                      isBatch
                                                          ? 'posts.composer.schedule_posts'
                                                          : 'posts.edit.schedule',
                                                  )
                                        }}</Button
                                    >
                                </TooltipTrigger>
                                <TooltipContent
                                    v-if="blockingIssue"
                                    side="top"
                                    data-testid="composer-blocked-tooltip"
                                >
                                    {{
                                        $t('posts.composer.blocked_tooltip', {
                                            issue: $t(
                                                blockingIssue.key,
                                                blockingIssue.warning
                                                    ? mediaWarningParams(
                                                          blockingIssue.warning,
                                                          blockingIssue.contentType,
                                                          $t,
                                                      )
                                                    : blockingIssue.params,
                                            ),
                                            channel:
                                                blockingIssue.account.display_name ||
                                                blockingIssue.account.username,
                                        })
                                    }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        </div>
                    </PopoverAnchor>
                </Popover>
            </footer>
        </DialogContent>
    </Dialog>

    <MediaEditorDialog
        v-model:open="cropping"
        :items="cropItems"
        :initial-index="cropTarget?.initialIndex ?? 0"
        :initial-tab="cropTarget?.tab ?? 'edit'"
        :content-types="cropContentTypes"
        :aspect-bounds="cropAspectBounds"
        @apply="onMediaEdited"
    />
    <UnsplashDialog
        v-model:open="unsplashOpen"
        @picked="appendMedia(unsplashGroupKey, $event)"
    />
    <AlertDialog v-model:open="confirmingBack">
        <AlertDialogContent
            class="gap-0 px-0 pt-6 pb-0 sm:max-w-[512px]"
            data-testid="composer-back-confirm"
        >
            <AlertDialogHeader class="gap-3 px-6 pb-4 text-left">
                <AlertDialogTitle class="font-sans text-base font-medium">
                    {{ $t('posts.composer.back_confirm.title') }}
                </AlertDialogTitle>
                <AlertDialogDescription class="text-sm text-foreground">
                    {{ $t('posts.composer.back_confirm.description') }}
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter
                class="flex-col border-t border-border px-6 py-4 sm:flex-row sm:justify-end"
            >
                <AlertDialogCancel
                    class="mt-0"
                    data-testid="composer-back-cancel"
                >
                    {{ $t('common.cancel') }}
                </AlertDialogCancel>
                <Button
                    variant="destructive"
                    size="lg"
                    data-testid="composer-back-confirm-go"
                    @click="goBackToSharedStep"
                >
                    {{ $t('posts.composer.back_confirm.confirm') }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
    <ResumeUnfinishedPostDialog
        :open="resumeOpen"
        :accounts="resumeAccounts"
        :preview="resumePreview"
        :media-count="resumeMediaCount"
        @update:open="updateResumeOpen"
        @discard="discardUnfinishedPost"
        @resume="resumeUnfinishedPost"
    />
</template>
