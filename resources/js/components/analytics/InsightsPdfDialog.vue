<script setup lang="ts">
import { router, useHttp, usePage } from '@inertiajs/vue3';
import { IconPhoto, IconUpload } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { store as channelStore } from '@/routes/app/channels/insights/pdf';
import { show, store } from '@/routes/app/insights/pdf';

type PdfExport = {
    id: string;
    status: 'pending' | 'ready' | 'failed';
    download_url: string | null;
};

const props = defineProps<{
    query: Record<string, string | string[]>;
    sections: readonly string[];
    readColumns: () => readonly string[];
    channelId?: string;
    logoUrl: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const POLL_INTERVAL_MS = 1500;

const MAX_WAIT_MS = 5 * 60 * 1000;

const page = usePage();
const { canManageWorkspace } = useWorkspaceAbilities();
const currentWorkspace = computed(
    () =>
        (
            page.props.auth as
                | { currentWorkspace?: { name?: string; logo_url?: string | null } }
                | undefined
        )?.currentWorkspace,
);
const workspaceName = computed((): string => currentWorkspace.value?.name ?? '');
const workspaceLogo = computed(
    (): string | null => currentWorkspace.value?.logo_url ?? null,
);

const http = useHttp<
    {
        name: string;
        logo: File | null;
        remove_logo: boolean;
        sections: string[];
        columns: string[];
    },
    PdfExport
>({
    name: '',
    logo: null,
    remove_logo: false,
    sections: [...props.sections],
    columns: [],
});
const statusHttp = useHttp<Record<string, never>, PdfExport>({});

const preparing = ref(false);
const logoInput = useTemplateRef<HTMLInputElement>('logoInput');
const customLogo = ref<string | null>(props.logoUrl);
const logoPreview = computed(
    (): string | null => customLogo.value ?? workspaceLogo.value,
);
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let waitingSince = 0;
let generation = 0;
let previewUrl: string | null = null;

const releasePreview = (): void => {
    if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = null;
    }
};

const stopPolling = (): void => {
    if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
};

const reset = (): void => {
    generation++;
    stopPolling();
    releasePreview();
    preparing.value = false;
    http.name = '';
    http.logo = null;
    http.remove_logo = false;
    http.sections = [...props.sections];
    http.columns = [...props.readColumns()];
    http.clearErrors();
    customLogo.value = props.logoUrl;
};

watch(open, (isOpen) => {
    if (isOpen) {
        reset();
    } else {
        generation++;
        stopPolling();
    }
});

onBeforeUnmount(() => {
    stopPolling();
    releasePreview();
});

const toggleSection = (section: string, checked: boolean | 'indeterminate'): void => {
    http.sections = checked === true
        ? props.sections.filter((key) => key === section || http.sections.includes(key))
        : http.sections.filter((key) => key !== section);
};

const chooseLogo = (): void => {
    logoInput.value?.click();
};

const pickLogo = (event: Event): void => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    if (!file) {
        return;
    }

    releasePreview();
    previewUrl = URL.createObjectURL(file);
    customLogo.value = previewUrl;
    http.logo = file;
    http.remove_logo = false;
};

const removeLogo = (): void => {
    releasePreview();
    customLogo.value = null;
    http.logo = null;
    http.remove_logo = true;

    if (logoInput.value) {
        logoInput.value.value = '';
    }
};

const fail = (): void => {
    stopPolling();
    preparing.value = false;
    toast.error(trans('analytics.insights.export.pdf.failed'));
};

const poll = (id: string, round: number): void => {
    if (Date.now() - waitingSince > MAX_WAIT_MS) {
        fail();

        return;
    }

    pollTimer = setTimeout(() => {
        statusHttp
            .get(show.url(id))
            .then((result) => {
                if (round !== generation) {
                    return;
                }

                if (result.status === 'ready' && result.download_url) {
                    window.location.assign(result.download_url);
                    open.value = false;

                    return;
                }

                if (result.status === 'failed') {
                    fail();

                    return;
                }

                poll(id, round);
            })
            .catch(() => {
                if (round === generation) {
                    fail();
                }
            });
    }, POLL_INTERVAL_MS);
};

const closeDialog = (): void => {
    open.value = false;
};

const FIELDS_WITH_ERRORS = ['name', 'logo', 'sections'];

const createPdf = (): void => {
    const round = generation;
    const url = props.channelId
        ? channelStore.url(props.channelId)
        : store.url();

    http.transform((data) => ({ ...props.query, ...data }))
        .post(url)
        .then((result) => {
            if (round !== generation) {
                return;
            }

            if (http.logo || http.remove_logo) {
                router.reload({ only: ['reportLogoUrl'] });
            }

            preparing.value = true;
            waitingSince = Date.now();
            poll(result.id, round);
        })
        .catch(() => {
            const shown = Object.keys(http.errors).some((field) =>
                FIELDS_WITH_ERRORS.some(
                    (visible) => field === visible || field.startsWith(`${visible}.`),
                ),
            );

            if (round === generation && !shown) {
                fail();
            }
        });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-testid="insights-pdf-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('analytics.insights.export.pdf.title')
                }}</DialogTitle>
                <DialogDescription>
                    {{ $t('analytics.insights.export.pdf.description') }}
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="preparing"
                class="flex flex-col items-center gap-3 py-8 text-center"
                role="status"
                aria-live="polite"
                data-testid="insights-pdf-preparing"
            >
                <Spinner class="size-6 text-primary-text" />
                <p class="text-sm font-medium text-foreground">
                    {{ $t('analytics.insights.export.pdf.preparing') }}
                </p>
                <p class="max-w-xs text-sm text-muted-foreground">
                    {{ $t('analytics.insights.export.pdf.preparing_hint') }}
                </p>
            </div>

            <div v-else class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="insights-pdf-name">{{
                        $t('analytics.insights.export.pdf.name')
                    }}</Label>
                    <Input
                        id="insights-pdf-name"
                        v-model="http.name"
                        :placeholder="workspaceName"
                        data-testid="insights-pdf-name"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ $t('analytics.insights.export.pdf.name_hint') }}
                    </p>
                    <InputError :message="http.errors.name" />
                </div>

                <div v-if="canManageWorkspace || logoPreview" class="grid gap-2">
                    <Label>{{ $t('analytics.insights.export.pdf.logo') }}</Label>
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-muted"
                        >
                            <img
                                v-if="logoPreview"
                                :src="logoPreview"
                                alt=""
                                class="size-full object-contain"
                                data-testid="insights-pdf-logo-preview"
                            />
                            <IconPhoto
                                v-else
                                class="size-5 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </span>
                        <div v-if="canManageWorkspace" class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                data-testid="insights-pdf-logo-upload"
                                @click="chooseLogo"
                            >
                                <IconUpload aria-hidden="true" />
                                {{
                                    logoPreview
                                        ? $t('analytics.insights.export.pdf.replace_logo')
                                        : $t('analytics.insights.export.pdf.upload_logo')
                                }}
                            </Button>
                            <Button
                                v-if="customLogo"
                                type="button"
                                variant="ghost"
                                size="sm"
                                data-testid="insights-pdf-logo-remove"
                                @click="removeLogo"
                            >
                                {{ $t('analytics.insights.export.pdf.remove_logo') }}
                            </Button>
                        </div>
                        <input
                            ref="logoInput"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            class="hidden"
                            data-testid="insights-pdf-logo-input"
                            @change="pickLogo"
                        />
                    </div>
                    <p
                        v-if="canManageWorkspace"
                        class="text-xs text-muted-foreground"
                    >
                        {{ $t('analytics.insights.export.pdf.logo_hint') }}
                    </p>
                    <InputError :message="http.errors.logo" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium text-foreground">
                        {{ $t('analytics.insights.export.pdf.sections') }}
                    </legend>
                    <label
                        v-for="section in sections"
                        :key="section"
                        class="flex cursor-pointer items-center gap-2.5 text-sm text-foreground"
                    >
                        <Checkbox
                            :model-value="http.sections.includes(section)"
                            :data-testid="`insights-pdf-section-${section}`"
                            @update:model-value="toggleSection(section, $event)"
                        />
                        {{ $t(`analytics.insights.export.pdf.section.${section}`) }}
                    </label>
                    <InputError :message="http.errors.sections" />
                </fieldset>
            </div>

            <DialogFooter>
                <Button type="button" variant="ghost" @click="closeDialog">
                    {{ preparing ? $t('common.close') : $t('common.cancel') }}
                </Button>
                <Button
                    v-if="!preparing"
                    type="button"
                    :loading="http.processing"
                    data-testid="insights-pdf-create"
                    @click="createPdf"
                >
                    {{ $t('analytics.insights.export.pdf.create') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
