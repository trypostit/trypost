<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconPlus, IconSearch } from '@tabler/icons-vue';
import { watchDebounced } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import DuplicateTemplateDialog from '@/components/create/templates/DuplicateTemplateDialog.vue';
import TemplateCard from '@/components/create/templates/TemplateCard.vue';
import TemplateEditorDialog from '@/components/create/templates/TemplateEditorDialog.vue';
import TemplateFilterPopover from '@/components/create/templates/TemplateFilterPopover.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { destroy, picker } from '@/routes/app/create/templates';
import {
    isLibraryTemplate,
    type DuplicateSource,
    type LibraryTemplate,
    type PostTemplate,
    type TemplateFacetFilters,
    type TemplatePickerData,
    type TemplateScope,
    type TemplateVisibility,
} from '@/types/template';

const emit = defineEmits<{
    select: [body: string];
}>();

const SCOPES: TemplateScope[] = ['discover', 'team', 'personal'];

const scope = ref<TemplateScope>('discover');

const selectScope = (option: TemplateScope): void => {
    scope.value = option;
};

const search = ref('');
const types = ref<string[]>([]);
const audiences = ref<string[]>([]);
const formats = ref<string[]>([]);
const goals = ref<string[]>([]);
const data = ref<TemplatePickerData | null>(null);
const loadFailed = ref(false);
const root = useTemplateRef<HTMLElement>('root');

const http = useHttp<Record<string, never>, TemplatePickerData>({});
const deleteHttp = useHttp<Record<string, never>, null>({});

let latestLoad = 0;

const load = async (): Promise<void> => {
    const loadId = ++latestLoad;
    const term = search.value.trim();

    let result: TemplatePickerData | null | undefined = null;

    try {
        result = await http.get(
            picker.url({ query: { search: term || undefined } }),
        );
    } catch {
        result = null;
    }

    if (loadId !== latestLoad) {
        return;
    }

    loadFailed.value = !result;

    if (result) {
        data.value = result;
    }
};

onMounted(load);

watchDebounced(search, load, { debounce: 300 });

const moveScope = async (step: number): Promise<void> => {
    const next =
        SCOPES[(SCOPES.indexOf(scope.value) + step + SCOPES.length) % SCOPES.length];

    scope.value = next;
    await nextTick();
    document.getElementById(`composer-templates-tab-${next}`)?.focus();
};

const unique = (values: string[]): string[] => [...new Set(values)];

const facets = computed<TemplateFacetFilters>(() => {
    const library = data.value?.library ?? [];

    return {
        types: unique(library.map((template) => template.type)),
        audiences: unique(library.flatMap((template) => template.audiences)),
        formats: unique(library.map((template) => template.format)),
        goals: unique(library.map((template) => template.goal)),
    };
});

const matches = (selected: string[], values: string[]): boolean =>
    selected.length === 0 || values.some((value) => selected.includes(value));

const library = computed<LibraryTemplate[]>(() =>
    (data.value?.library ?? []).filter(
        (template) =>
            matches(types.value, [template.type]) &&
            matches(audiences.value, template.audiences) &&
            matches(formats.value, [template.format]) &&
            matches(goals.value, [template.goal]),
    ),
);

const templates = computed<(LibraryTemplate | PostTemplate)[]>(() => {
    if (scope.value === 'discover') {
        return library.value;
    }

    return data.value?.[scope.value] ?? [];
});

const hasMore = computed(
    () => scope.value !== 'discover' && Boolean(data.value?.has_more[scope.value]),
);

const templateId = (template: LibraryTemplate | PostTemplate): string =>
    isLibraryTemplate(template) ? template.key : template.id;

const targetVisibility = computed<TemplateVisibility>(() =>
    scope.value === 'team' ? 'team' : 'personal',
);

const focusPanel = async (): Promise<void> => {
    await nextTick();
    root.value?.focus();
};

const afterWrite = async (template: PostTemplate): Promise<void> => {
    scope.value = template.visibility;
    await load();
};

type EditorState =
    | { key: number; template: null; visibility: TemplateVisibility }
    | { key: number; template: PostTemplate };

let dialogKey = 0;

const editor = ref<EditorState | null>(null);
const duplicating = ref<{ key: number; source: DuplicateSource } | null>(null);

const openCreate = (): void => {
    editor.value = {
        key: ++dialogKey,
        template: null,
        visibility: targetVisibility.value,
    };
};

const openEdit = (template: PostTemplate): void => {
    editor.value = { key: ++dialogKey, template };
};

const openDuplicate = (template: LibraryTemplate | PostTemplate): void => {
    duplicating.value = {
        key: ++dialogKey,
        source: isLibraryTemplate(template)
            ? { kind: 'library', key: template.key, title: template.title }
            : { kind: 'custom', id: template.id, title: template.title },
    };
};

const closeEditor = (): void => {
    editor.value = null;
    focusPanel();
};

const closeDuplicate = (): void => {
    duplicating.value = null;
    focusPanel();
};

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const openDelete = (template: PostTemplate): void => {
    const url = destroy.url(template.id);

    deleteModal.value?.open({
        url,
        request: async () => {
            try {
                await deleteHttp.delete(url);
            } catch (error) {
                toast.error(trans('create.templates.errors.request_failed'));

                throw error;
            }

            await load();
        },
    });
};
</script>

<template>
    <div
        ref="root"
        tabindex="-1"
        class="flex min-h-0 flex-1 flex-col outline-none"
        data-testid="composer-templates-panel"
    >
        <div
            class="flex shrink-0 items-center justify-between gap-2 px-8 pt-[22px] pb-3"
        >
            <h3 class="text-base leading-5 font-medium">
                {{ $t('create.templates.panel.title') }}
            </h3>
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                :aria-label="$t('create.templates.panel.new')"
                data-testid="composer-templates-new"
                @click="openCreate"
            >
                <IconPlus class="size-4" />
            </Button>
        </div>

        <div
            class="mx-8 flex shrink-0 gap-2 shadow-[inset_0_-1px_0_var(--color-border)]"
            role="tablist"
            :aria-label="$t('create.templates.scopes_label')"
        >
            <button
                v-for="option in SCOPES"
                :key="option"
                type="button"
                role="tab"
                :id="`composer-templates-tab-${option}`"
                :aria-selected="scope === option"
                aria-controls="composer-templates-tabpanel"
                :tabindex="scope === option ? 0 : -1"
                class="relative inline-flex h-10 items-center px-2 text-sm font-medium transition-control outline-none after:absolute after:inset-x-0 after:bottom-0 after:h-px after:bg-primary-text after:opacity-0 focus-visible:outline-2 focus-visible:outline-ring aria-selected:after:opacity-100"
                :class="
                    scope === option
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :data-testid="`composer-templates-tab-${option}`"
                @click="selectScope(option)"
                @keydown.right.prevent="moveScope(1)"
                @keydown.left.prevent="moveScope(-1)"
            >
                {{ $t(`create.templates.scopes.${option}`) }}
            </button>
        </div>

        <div class="flex shrink-0 items-center gap-2 px-8 pt-3 pb-3">
            <div class="relative min-w-0 flex-1">
                <IconSearch
                    class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    class="h-8 bg-background ps-8 text-sm"
                    :placeholder="$t('create.templates.search_placeholder')"
                    :aria-label="$t('create.templates.search')"
                    data-testid="composer-templates-search"
                />
            </div>
            <TemplateFilterPopover
                v-if="scope === 'discover'"
                v-model:types="types"
                v-model:audiences="audiences"
                v-model:formats="formats"
                v-model:goals="goals"
                :facets="facets"
                icon-only
                test-id="composer-templates-filter"
            />
        </div>

        <div
            id="composer-templates-tabpanel"
            role="tabpanel"
            :aria-labelledby="`composer-templates-tab-${scope}`"
            class="min-h-0 flex-1 space-y-3 overflow-y-auto px-8 pb-6"
            data-testid="composer-templates-list"
        >
            <TemplateCard
                v-for="template in templates"
                :key="templateId(template)"
                :template="template"
                compact
                show-visibility
                test-id-prefix="composer-template"
                @open="emit('select', template.body)"
                @edit="!isLibraryTemplate(template) && openEdit(template)"
                @duplicate="openDuplicate(template)"
                @delete="!isLibraryTemplate(template) && openDelete(template)"
            />
            <div
                v-if="loadFailed"
                role="alert"
                class="space-y-3 py-6 text-center text-sm text-muted-foreground"
                data-testid="composer-templates-error"
            >
                <p>{{ $t('create.templates.errors.request_failed') }}</p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid="composer-templates-retry"
                    @click="load"
                >
                    {{ $t('posts.composer.retry') }}
                </Button>
            </div>
            <p
                v-else-if="data && templates.length === 0"
                class="py-6 text-center text-sm text-muted-foreground"
                data-testid="composer-templates-empty"
            >
                {{
                    search.trim() || (scope === 'discover' && library.length === 0)
                        ? $t('create.templates.empty.results')
                        : $t('create.templates.panel.empty')
                }}
            </p>
            <p
                v-if="hasMore"
                class="py-2 text-center text-xs text-muted-foreground"
                data-testid="composer-templates-refine"
            >
                {{ $t('create.templates.panel.refine_search') }}
            </p>
        </div>

        <TemplateEditorDialog
            v-if="editor"
            :key="editor.key"
            mode="local"
            :template="editor.template"
            :visibility="editor.template ? undefined : editor.visibility"
            :can-change-visibility="
                editor.template ? editor.template.can_change_visibility : true
            "
            @saved="afterWrite"
            @close="closeEditor"
        />

        <DuplicateTemplateDialog
            v-if="duplicating"
            :key="duplicating.key"
            mode="local"
            :source="duplicating.source"
            :default-visibility="targetVisibility"
            @saved="afterWrite"
            @close="closeDuplicate"
        />

        <ConfirmDeleteModal
            ref="deleteModal"
            :title="$t('create.templates.delete_confirm.title')"
            :description="$t('create.templates.delete_confirm.body')"
            :action="$t('create.templates.delete')"
            :cancel="$t('create.templates.cancel')"
            action-test-id="template-delete-confirm"
            @closed="focusPanel"
        />
    </div>
</template>
