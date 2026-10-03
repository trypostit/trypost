<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePageErrors } from '@/composables/usePageErrors';
import { htmlToPlainText, toNullableText } from '@/lib/utils';
import {
    getYouTubeDescriptionIssue,
    YOUTUBE_DESCRIPTION_MAX_BYTES,
    youtubeDescriptionBytes,
} from '@/lib/youtubeDescription';
import {
    YOUTUBE_TITLE_MAX,
    YouTubeLicense,
    YouTubePrivacyStatus,
} from '@/types/network-options';

interface CategoryOption {
    value: string;
    labelKey: string;
}

interface Props {
    platformIndex: number;
    publishConfig: {
        categoryOptions?: CategoryOption[];
        defaultCategoryId?: string;
    } | null;
    content?: string;
    meta: Record<string, unknown>;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    content: '',
    disabled: false,
});
const emit = defineEmits<{
    'update:meta': [value: Record<string, unknown>];
}>();

const errors = usePageErrors();
const descriptionId = computed(
    () => `youtube-description-${props.platformIndex}`,
);

const update = (patch: Record<string, unknown>) =>
    emit('update:meta', { ...props.meta, ...patch });

const showsDescription = Boolean(toNullableText(props.meta.description));

const privacyOptions = [
    {
        value: YouTubePrivacyStatus.Public,
        labelKey: 'posts.form.youtube.privacy.public',
    },
    {
        value: YouTubePrivacyStatus.Unlisted,
        labelKey: 'posts.form.youtube.privacy.unlisted',
    },
    {
        value: YouTubePrivacyStatus.Private,
        labelKey: 'posts.form.youtube.privacy.private',
    },
];
const licenseOptions = [
    {
        value: YouTubeLicense.YouTube,
        labelKey: 'posts.form.youtube.license_youtube',
    },
    {
        value: YouTubeLicense.CreativeCommon,
        labelKey: 'posts.form.youtube.license_creative_common',
    },
];

const title = computed({
    get: () => toNullableText(props.meta.title) ?? '',
    set: (value: string) => update({ title: toNullableText(value) }),
});
const titleFromCaption = (caption: string): string | null => {
    const firstLine =
        htmlToPlainText(caption)
            .split('\n')
            .map((line) => line.replace(/[<>]/g, '').trim())
            .find((line) => line !== '') ?? '';

    return toNullableText(
        Array.from(firstLine).slice(0, YOUTUBE_TITLE_MAX).join(''),
    );
};

const followCaption = (caption: string, previousCaption?: string): void => {
    const current = toNullableText(props.meta.title);
    const next = titleFromCaption(caption);
    const followsCaption =
        current === null ||
        (previousCaption !== undefined &&
            current === titleFromCaption(previousCaption));

    if (followsCaption && next !== current) {
        update({ title: next });
    }
};

watch(() => props.content, followCaption);
onMounted(() => followCaption(props.content));
const categories = computed(() => props.publishConfig?.categoryOptions ?? []);
const categoryId = computed({
    get: () =>
        (props.meta.category_id as string | undefined) ??
        props.publishConfig?.defaultCategoryId ??
        '',
    set: (value: string) => update({ category_id: value }),
});
const privacyStatus = computed({
    get: () =>
        (props.meta.privacy_status as string | undefined) ??
        YouTubePrivacyStatus.Public,
    set: (value: string) => update({ privacy_status: value }),
});
const license = computed({
    get: () =>
        (props.meta.license as string | undefined) ?? YouTubeLicense.YouTube,
    set: (value: string) => update({ license: value }),
});
const notifySubscribers = computed({
    get: () => (props.meta.notify_subscribers as boolean | undefined) ?? true,
    set: (value: boolean) => update({ notify_subscribers: value }),
});
const embeddable = computed({
    get: () => (props.meta.embeddable as boolean | undefined) ?? true,
    set: (value: boolean) => update({ embeddable: value }),
});
const madeForKids = computed({
    get: () => (props.meta.made_for_kids as boolean | undefined) ?? false,
    set: (value: boolean) => update({ made_for_kids: value }),
});
const labelFor = (
    options: readonly { value: string; labelKey: string }[],
    value: string,
): string => options.find((option) => option.value === value)?.labelKey ?? '';
const titleError = computed(
    () =>
        errors.value[`destinations.${props.platformIndex}.meta.title`] ??
        errors.value[`platforms.${props.platformIndex}.meta.title`],
);

const description = computed({
    get: () => toNullableText(props.meta.description) ?? '',
    set: (value: string) => update({ description: toNullableText(value) }),
});
const usedBytes = computed(() => youtubeDescriptionBytes(description.value));
const descriptionIssueKey = computed(() =>
    getYouTubeDescriptionIssue(props.meta.description),
);
const descriptionServerError = computed(
    () =>
        errors.value[`destinations.${props.platformIndex}.meta.description`] ??
        errors.value[`platforms.${props.platformIndex}.meta.description`],
);
const hasDescriptionError = computed(
    () => !!descriptionIssueKey.value || !!descriptionServerError.value,
);
</script>

<template>
    <SettingsSection>
        <SettingsRow
            :label="$t('posts.form.youtube.title')"
            :label-for="`youtube-title-${platformIndex}`"
        >
            <Input
                :id="`youtube-title-${platformIndex}`"
                v-model="title"
                data-testid="youtube-title"
                :disabled="disabled"
                :placeholder="$t('posts.form.youtube.title_placeholder')"
                :aria-invalid="
                    titleError || title.length > YOUTUBE_TITLE_MAX
                        ? true
                        : undefined
                "
            />
            <InputError :message="titleError" />
        </SettingsRow>
        <SettingsRow
            :label="$t('posts.form.youtube.category')"
            :label-for="`youtube-category-${platformIndex}`"
        >
            <div
                class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center"
            >
                <Select v-model="categoryId" :disabled="disabled">
                    <SelectTrigger
                        :id="`youtube-category-${platformIndex}`"
                        class="w-full"
                        data-testid="youtube-category"
                    >
                        <SelectValue>{{
                            $t(labelFor(categories, categoryId))
                        }}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="category in categories"
                            :key="category.value"
                            :value="category.value"
                            :data-testid="`youtube-category-${category.value}`"
                            >{{ $t(category.labelKey) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <label
                    class="text-[13px] font-medium text-foreground"
                    :for="`youtube-privacy-${platformIndex}`"
                    data-single-line
                    >{{ $t('posts.form.youtube.visibility') }}</label
                >
                <Select v-model="privacyStatus" :disabled="disabled">
                    <SelectTrigger
                        :id="`youtube-privacy-${platformIndex}`"
                        class="w-full"
                        data-testid="youtube-privacy"
                    >
                        <SelectValue>{{
                            $t(labelFor(privacyOptions, privacyStatus))
                        }}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in privacyOptions"
                            :key="option.value"
                            :value="option.value"
                            :data-testid="`youtube-privacy-${option.value}`"
                            >{{ $t(option.labelKey) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
        </SettingsRow>
        <SettingsRow
            :label="$t('posts.form.youtube.license')"
            :label-for="`youtube-license-${platformIndex}`"
        >
            <Select v-model="license" :disabled="disabled">
                <SelectTrigger
                    :id="`youtube-license-${platformIndex}`"
                    class="w-full"
                    data-testid="youtube-license"
                >
                    <SelectValue>{{
                        $t(labelFor(licenseOptions, license))
                    }}</SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in licenseOptions"
                        :key="option.value"
                        :value="option.value"
                        :data-testid="`youtube-license-${option.value}`"
                        >{{ $t(option.labelKey) }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </SettingsRow>
        <SettingsRow>
            <div
                class="flex min-h-8 flex-wrap items-center justify-between gap-x-6 gap-y-2"
            >
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        v-model="notifySubscribers"
                        data-testid="youtube-notify-subscribers"
                        :disabled="disabled"
                    />
                    <span data-single-line>{{
                        $t('posts.form.youtube.notify_subscribers')
                    }}</span>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        v-model="embeddable"
                        data-testid="youtube-embeddable"
                        :disabled="disabled"
                    />
                    <span data-single-line>{{
                        $t('posts.form.youtube.embeddable')
                    }}</span>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        v-model="madeForKids"
                        data-testid="youtube-made-for-kids"
                        :disabled="disabled"
                    />
                    <span data-single-line>{{
                        $t('posts.form.youtube.made_for_kids')
                    }}</span>
                </label>
            </div>
        </SettingsRow>
        <SettingsRow
            v-if="showsDescription"
            :label="$t('posts.form.youtube.description')"
            :label-for="descriptionId"
            align-top
        >
            <Textarea
                :id="descriptionId"
                v-model="description"
                :data-testid="descriptionId"
                :disabled="disabled"
                :aria-invalid="hasDescriptionError ? true : undefined"
                :placeholder="$t('posts.form.youtube.description_placeholder')"
                class="field-sizing-fixed min-h-32 w-full resize-y"
            />
            <p
                class="text-xs tabular-nums"
                :class="
                    hasDescriptionError
                        ? 'text-destructive-text'
                        : 'text-muted-foreground'
                "
            >
                {{
                    $t('posts.form.youtube.description_bytes', {
                        used: usedBytes.toString(),
                        limit: YOUTUBE_DESCRIPTION_MAX_BYTES.toString(),
                    })
                }}
            </p>
            <InputError
                :message="
                    descriptionIssueKey
                        ? $t(descriptionIssueKey)
                        : descriptionServerError
                "
            />
        </SettingsRow>
    </SettingsSection>
</template>
