<script setup lang="ts">
import { computed, ref } from 'vue';

import TemplateCardMenu from '@/components/create/templates/TemplateCardMenu.vue';
import { Badge } from '@/components/ui/badge';
import {
    isLibraryTemplate,
    type LibraryTemplate,
    type PostTemplate,
} from '@/types/template';

const props = withDefaults(
    defineProps<{
        template: LibraryTemplate | PostTemplate;
        compact?: boolean;
        featured?: boolean;
        showVisibility?: boolean;
        testIdPrefix?: string;
    }>(),
    {
        compact: false,
        featured: false,
        showVisibility: false,
        testIdPrefix: 'template-card',
    },
);

const emit = defineEmits<{
    open: [];
    edit: [];
    duplicate: [];
    delete: [];
}>();

const menuOpen = ref(false);

const id = computed(() =>
    isLibraryTemplate(props.template) ? props.template.key : props.template.id,
);

const editable = computed(
    () => !isLibraryTemplate(props.template) && props.template.can_edit,
);
</script>

<template>
    <article
        class="group/card relative flex flex-col gap-2 overflow-hidden rounded-xl border bg-gradient-to-b from-card to-muted/40 p-4 text-start transition-[border-color,box-shadow] duration-150 hover:border-border-strong has-[[data-card-action]:focus-visible]:outline-2 has-[[data-card-action]:focus-visible]:outline-offset-1 has-[[data-card-action]:focus-visible]:outline-ring"
        :class="
            compact
                ? 'w-full'
                : featured
                  ? 'min-h-[232px] w-full sm:w-[320px]'
                  : 'h-[232px] w-full sm:w-[280px] sm:shrink-0'
        "
        :data-testid="`${testIdPrefix}-${id}`"
    >
        <div class="flex items-start justify-between gap-2">
            <span class="text-3xl leading-none" aria-hidden="true">{{
                template.emoji || '📝'
            }}</span>
            <div class="relative z-10 flex items-center gap-1.5">
                <Badge
                    v-if="showVisibility && !isLibraryTemplate(template)"
                    variant="secondary"
                    :data-testid="`${testIdPrefix}-visibility-${id}`"
                >
                    {{ $t(`create.templates.visibility.${template.visibility}`) }}
                </Badge>
                <div
                    :class="
                        menuOpen
                            ? ''
                            : 'sm:opacity-0 sm:transition-opacity sm:group-focus-within/card:opacity-100 sm:group-hover/card:opacity-100'
                    "
                >
                    <TemplateCardMenu
                        v-model:open="menuOpen"
                        :id="id"
                        :title="template.title"
                        :editable="editable"
                        :trigger-test-id="`${testIdPrefix}-menu-${id}`"
                        @edit="emit('edit')"
                        @duplicate="emit('duplicate')"
                        @delete="emit('delete')"
                    />
                </div>
            </div>
        </div>
        <h3
            class="font-heading text-[17px] leading-snug font-semibold text-foreground"
        >
            <button
                type="button"
                class="line-clamp-2 w-full cursor-pointer text-start outline-none after:absolute after:inset-0 after:content-['']"
                data-card-action
                :data-testid="`${testIdPrefix}-open-${id}`"
                @click="emit('open')"
            >
                {{ template.title }}
            </button>
        </h3>
        <p
            v-if="template.description"
            class="line-clamp-3 text-sm text-muted-foreground"
        >
            {{ template.description }}
        </p>
    </article>
</template>
