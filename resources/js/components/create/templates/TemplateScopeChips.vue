<script setup lang="ts">
import { router } from '@inertiajs/vue3';

import { index } from '@/routes/app/create/templates';
import type { TemplateCounts, TemplateScope } from '@/types/template';

const props = defineProps<{
    view: TemplateScope;
    counts: TemplateCounts;
    search: string | null;
}>();

const scopes: TemplateScope[] = ['discover', 'team', 'personal'];

const scopeUrl = (scope: TemplateScope): string =>
    index.url({
        query: {
            view: scope === 'discover' ? undefined : scope,
            search: props.search ?? undefined,
        },
    });

const visitScope = (event: MouseEvent, scope: TemplateScope): void => {
    if (
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    ) {
        return;
    }

    event.preventDefault();

    router.get(
        scopeUrl(scope),
        {},
        {
            preserveScroll: true,
            only: ['view', 'counts', 'filters', 'library', 'templates'],
            reset: ['templates'],
        },
    );
};
</script>

<template>
    <nav
        class="flex shrink-0 flex-wrap items-center gap-2 px-4 pt-4 md:px-8"
        :aria-label="$t('create.templates.scopes_label')"
        data-testid="templates-scopes"
    >
        <a
            v-for="scope in scopes"
            :key="scope"
            :href="scopeUrl(scope)"
            :aria-current="view === scope ? 'page' : undefined"
            :data-testid="`templates-scope-${scope}`"
            @click="visitScope($event, scope)"
            class="inline-flex h-8 items-center gap-2 rounded-full border px-3 text-sm font-medium transition-control"
            :class="
                view === scope
                    ? 'border-transparent bg-primary-selected text-primary-text'
                    : 'border-border-strong bg-card text-foreground hover:bg-accent'
            "
        >
            {{ $t(`create.templates.scopes.${scope}`) }}
            <span
                class="text-xs"
                :class="view === scope ? '' : 'text-muted-foreground'"
                :data-testid="`templates-scope-count-${scope}`"
                >{{ counts[scope] }}</span
            >
        </a>
    </nav>
</template>
