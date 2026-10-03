<script setup lang="ts">
import { Head, InfiniteScroll, router } from '@inertiajs/vue3';
import { IconChevronRight, IconPlus, IconTemplate } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import CreateHeader from '@/components/create/CreateHeader.vue';
import CreateTabs from '@/components/create/CreateTabs.vue';
import DuplicateTemplateDialog from '@/components/create/templates/DuplicateTemplateDialog.vue';
import TemplateCard from '@/components/create/templates/TemplateCard.vue';
import TemplateDetailDialog from '@/components/create/templates/TemplateDetailDialog.vue';
import TemplateEditorDialog from '@/components/create/templates/TemplateEditorDialog.vue';
import TemplateFilterPopover from '@/components/create/templates/TemplateFilterPopover.vue';
import TemplateScopeChips from '@/components/create/templates/TemplateScopeChips.vue';
import TemplateSearch from '@/components/create/templates/TemplateSearch.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, index } from '@/routes/app/create/templates';
import {
    isLibraryTemplate,
    type DuplicateSource,
    type LibraryTemplate,
    type PostTemplate,
    type PostTemplatePage,
    type TemplateCounts,
    type TemplateFacetFilters,
    type TemplateFilters,
    type TemplateLibraryProps,
    type TemplateModal,
    type TemplateScope,
} from '@/types/template';

const props = defineProps<{
    view: TemplateScope;
    counts: TemplateCounts;
    filters: TemplateFilters;
    facets: TemplateFacetFilters;
    library?: TemplateLibraryProps;
    templates?: PostTemplatePage;
}>();

type Query = Record<string, string | string[] | undefined>;

const LIST_PROPS = ['view', 'counts', 'filters', 'library', 'templates'];

const search = ref(props.filters.search ?? '');
const types = ref<string[]>([...props.filters.types]);
const audiences = ref<string[]>([...props.filters.audiences]);
const formats = ref<string[]>([...props.filters.formats]);
const goals = ref<string[]>([...props.filters.goals]);

const nonEmpty = (values: string[]): string[] | undefined =>
    values.length ? values : undefined;

const query = (): Query => ({
    view: props.view === 'discover' ? undefined : props.view,
    search: search.value.trim() || undefined,
    types: nonEmpty(types.value),
    audiences: nonEmpty(audiences.value),
    formats: nonEmpty(formats.value),
    goals: nonEmpty(goals.value),
});

const visitList = (): void => {
    router.get(
        index.url(),
        query(),
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: LIST_PROPS,
            reset: ['templates'],
        },
    );
};

watch([types, audiences, formats, goals], visitList);

const hasSearch = computed(() => Boolean(props.filters.search));

const newVisibility = computed(() =>
    props.view === 'team' ? 'team' : 'personal',
);

const modal = ref<TemplateModal>(null);

const closeModal = (): void => {
    modal.value = null;
};

const openNew = (): void => {
    modal.value = {
        kind: 'editor',
        mode: 'create',
        visibility: newVisibility.value,
    };
};

const openCard = (template: LibraryTemplate | PostTemplate): void => {
    modal.value = isLibraryTemplate(template)
        ? { kind: 'library', template }
        : { kind: 'custom', template };
};

const editTemplate = (template: PostTemplate): void => {
    modal.value = { kind: 'editor', mode: 'edit', template };
};

const seeAll = (type: string): void => {
    types.value = [type];
};

const duplicateSource = ref<DuplicateSource | null>(null);

const clearDuplicateSource = (): void => {
    duplicateSource.value = null;
};

const duplicateTemplate = (template: LibraryTemplate | PostTemplate): void => {
    duplicateSource.value = isLibraryTemplate(template)
        ? { kind: 'library', key: template.key, title: template.title }
        : { kind: 'custom', id: template.id, title: template.title };
};

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const deleteTemplate = (template: PostTemplate): void => {
    deleteModal.value?.open({
        url: destroy.url(template.id),
    });
};

const detail = computed(() =>
    modal.value?.kind === 'library' || modal.value?.kind === 'custom'
        ? modal.value
        : null,
);

const editor = computed(() =>
    modal.value?.kind === 'editor' ? modal.value : null,
);

const editorKey = computed(() =>
    editor.value?.mode === 'edit' ? editor.value.template.id : 'create',
);

const editFromDetail = (): void => {
    if (modal.value?.kind === 'custom') {
        editTemplate(modal.value.template);
    }
};

const rows = computed(() => props.library?.rows ?? []);
const results = computed(() => props.library?.results ?? []);
const customTemplates = computed(() => props.templates?.data ?? []);

const emptyKey = computed(() => (props.view === 'team' ? 'team' : 'personal'));
</script>

<template>
    <Head :title="$t('create.templates.title')" />

    <AppLayout full-width>
        <template #header>
            <CreateHeader />
        </template>

        <template #header-actions>
            <Button
                variant="outline"
                class="max-sm:w-8 max-sm:px-0"
                :aria-label="$t('create.templates.new')"
                data-testid="templates-new"
                @click="openNew"
            >
                <IconPlus class="size-4" />
                <span class="max-sm:sr-only">{{
                    $t('create.templates.new')
                }}</span>
            </Button>
        </template>

        <div
            class="flex min-h-0 flex-1 flex-col overflow-hidden"
            data-testid="templates-page"
        >
            <CreateTabs active="templates">
                <template #filters>
                    <TemplateSearch v-model="search" @commit="visitList" />
                    <TemplateFilterPopover
                        v-if="view === 'discover'"
                        v-model:types="types"
                        v-model:audiences="audiences"
                        v-model:formats="formats"
                        v-model:goals="goals"
                        :facets="facets"
                        test-id="templates-filter"
                    />
                </template>
            </CreateTabs>

            <div
                class="flex min-h-0 min-w-0 flex-1 flex-col overflow-auto overscroll-contain"
                data-testid="templates-scroll"
            >
                <TemplateScopeChips
                    :view="view"
                    :counts="counts"
                    :search="filters.search"
                />

                <template v-if="view === 'discover'">
                    <div
                        v-if="library?.results"
                        class="px-4 pt-6 pb-12 md:px-8"
                    >
                        <div
                            v-if="results.length"
                            class="flex flex-wrap gap-4"
                            data-testid="templates-results"
                        >
                            <TemplateCard
                                v-for="template in results"
                                :key="template.key"
                                :template="template"
                                @open="openCard(template)"
                                @duplicate="duplicateTemplate(template)"
                            />
                        </div>
                        <div v-else data-testid="templates-empty">
                            <EmptyState
                                :icon="IconTemplate"
                                :title="$t('create.templates.empty.results')"
                                :description="$t('posts.try_different_search')"
                            />
                        </div>
                    </div>

                    <div v-else class="space-y-10 px-4 pt-6 pb-12 md:px-8">
                        <section
                            v-if="library?.featured?.length"
                            class="rounded-xl bg-gradient-to-b from-primary-subtle to-transparent p-6"
                            data-testid="templates-featured"
                        >
                            <p
                                class="text-xs font-medium tracking-wide text-primary-text uppercase"
                            >
                                {{ $t('create.templates.featured.eyebrow') }}
                            </p>
                            <h2
                                class="mt-1 font-heading text-xl font-semibold"
                            >
                                {{ $t('create.templates.featured.title') }}
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ $t('create.templates.featured.subtitle') }}
                            </p>
                            <div class="mt-4 flex flex-wrap gap-4">
                                <TemplateCard
                                    v-for="template in library.featured"
                                    :key="template.key"
                                    :template="template"
                                    featured
                                    @open="openCard(template)"
                                    @duplicate="duplicateTemplate(template)"
                                />
                            </div>
                        </section>

                        <section
                            v-for="row in rows"
                            :key="row.type"
                            :data-testid="`templates-row-${row.type}`"
                        >
                            <div
                                class="mb-3 flex items-center justify-between gap-2"
                            >
                                <h2
                                    class="font-heading text-base font-semibold"
                                >
                                    {{
                                        $t(
                                            `create.templates.facets.type.${row.type}`,
                                        )
                                    }}
                                </h2>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground transition-control hover:text-foreground"
                                    :data-testid="`templates-row-see-all-${row.type}`"
                                    @click="seeAll(row.type)"
                                >
                                    {{ $t('create.templates.see_all') }}
                                    <IconChevronRight class="size-4" />
                                </button>
                            </div>
                            <div
                                class="flex gap-4 overflow-x-auto pb-2 max-sm:flex-col"
                            >
                                <TemplateCard
                                    v-for="template in row.templates"
                                    :key="template.key"
                                    :template="template"
                                    @open="openCard(template)"
                                    @duplicate="duplicateTemplate(template)"
                                />
                            </div>
                        </section>
                    </div>
                </template>

                <template v-else>
                    <div
                        v-if="customTemplates.length === 0"
                        class="flex flex-1"
                        data-testid="templates-empty"
                    >
                        <EmptyState
                            :icon="IconTemplate"
                            :title="
                                hasSearch
                                    ? $t('create.templates.empty.results')
                                    : $t(
                                          `create.templates.empty.${emptyKey}.title`,
                                      )
                            "
                            :description="
                                hasSearch
                                    ? $t('posts.try_different_search')
                                    : $t(
                                          `create.templates.empty.${emptyKey}.body`,
                                      )
                            "
                        />
                    </div>

                    <InfiniteScroll
                        v-else
                        data="templates"
                        items-element="#templates-grid"
                        preserve-url
                    >
                        <div
                            id="templates-grid"
                            class="flex flex-wrap items-start gap-4 px-4 pt-6 pb-12 md:px-8"
                            data-testid="templates-grid"
                        >
                            <TemplateCard
                                v-for="template in customTemplates"
                                :key="template.id"
                                :template="template"
                                @open="openCard(template)"
                                @edit="editTemplate(template)"
                                @duplicate="duplicateTemplate(template)"
                                @delete="deleteTemplate(template)"
                            />
                        </div>

                        <template #next="{ loading }">
                            <p
                                v-if="loading"
                                class="py-5 text-center text-sm text-muted-foreground"
                                role="status"
                            >
                                {{ $t('common.loading_more') }}
                            </p>
                        </template>
                    </InfiniteScroll>
                </template>
            </div>
        </div>
    </AppLayout>

    <TemplateDetailDialog
        v-if="detail"
        :key="isLibraryTemplate(detail.template) ? detail.template.key : detail.template.id"
        :template="detail.template"
        :editable="detail.kind === 'custom' && detail.template.can_edit"
        @close="closeModal"
        @edit="editFromDetail"
    />

    <TemplateEditorDialog
        v-if="editor"
        :key="editorKey"
        mode="page"
        :template="editor.mode === 'edit' ? editor.template : null"
        :visibility="editor.mode === 'create' ? editor.visibility : undefined"
        :can-change-visibility="
            editor.mode === 'edit'
                ? editor.template.can_change_visibility
                : true
        "
        @close="closeModal"
    />

    <DuplicateTemplateDialog
        v-if="duplicateSource"
        :key="duplicateSource.kind === 'library' ? duplicateSource.key : duplicateSource.id"
        mode="page"
        :source="duplicateSource"
        :default-visibility="view === 'team' ? 'team' : 'personal'"
        @close="clearDuplicateSource"
    />

    <ConfirmDeleteModal
        ref="deleteModal"
        :title="$t('create.templates.delete_confirm.title')"
        :description="$t('create.templates.delete_confirm.body')"
        :action="$t('create.templates.delete')"
        :cancel="$t('create.templates.cancel')"
        action-test-id="template-delete-confirm"
    />
</template>
