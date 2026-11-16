<script setup lang="ts">
import { IconCheck, IconMapPin } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { detectBrowserLanguage } from '@/lib/detectPreferences';
import { blank, filled } from '@/lib/helpers';
import { matchesSearch } from '@/lib/search';
import type { Language } from '@/types';

const props = defineProps<{
    languages: Language[];
    selected: string;
    testid: string;
}>();

const emit = defineEmits<{
    select: [code: string];
}>();

const search = ref('');

const matches = (language: Language): boolean =>
    matchesSearch(search.value, [language.name, language.code]);

const browserLanguage = computed(() =>
    props.languages.find(
        (language) =>
            language.code ===
            detectBrowserLanguage(props.languages.map((item) => item.code)),
    ),
);

const suggestion = computed<Language | null>(() => {
    if (!browserLanguage.value) {
        return null;
    }

    if (!matches(browserLanguage.value)) {
        return null;
    }

    return browserLanguage.value;
});

const filtered = computed(() =>
    props.languages.filter(
        (language) =>
            language.code !== browserLanguage.value?.code && matches(language),
    ),
);

const hasNoResults = computed(() => {
    if (filled(suggestion.value)) {
        return false;
    }

    return blank(filtered.value);
});

const showsAllHeading = computed(() => {
    if (!filled(suggestion.value)) {
        return false;
    }

    return filled(filtered.value);
});

const checkClass = (code: string): string => {
    if (code === props.selected) {
        return 'size-4 shrink-0 opacity-100';
    }

    return 'size-4 shrink-0 opacity-0';
};

const choose = (code: string): void => {
    search.value = '';
    emit('select', code);
};

/**
 * Keeps typing in the search box from reaching a surrounding menu, whose
 * typeahead and arrow keys would otherwise steal the keys. Escape still
 * bubbles so the menu or popover can close.
 */
const keepKeysInSearch = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        return;
    }

    event.stopPropagation();
};
</script>

<template>
    <div>
        <Input
            v-model="search"
            :data-testid="`${testid}-search`"
            :placeholder="$t('settings.preferences.language.search')"
            autocomplete="off"
            @keydown="keepKeysInSearch"
        />

        <div
            class="mt-3 max-h-72 overflow-y-auto"
            :data-testid="`${testid}-list`"
        >
            <p
                v-if="hasNoResults"
                class="px-2 py-6 text-center text-sm text-muted-foreground"
            >
                {{ $t('settings.preferences.language.empty') }}
            </p>

            <div v-if="suggestion" :data-testid="`${testid}-suggestions`">
                <p class="px-2 py-1 text-xs text-muted-foreground">
                    {{ $t('settings.preferences.language.suggestions') }}
                </p>
                <TooltipProvider :delay-duration="200">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                :data-testid="`${testid}-option-${suggestion.code}`"
                                class="flex min-h-8 w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm transition-control hover:bg-accent"
                                @click="choose(suggestion.code)"
                            >
                                <IconCheck :class="checkClass(suggestion.code)" />
                                <img
                                    v-if="suggestion.flag"
                                    :src="suggestion.flag"
                                    alt=""
                                    class="h-3.5 w-5 shrink-0 rounded-xs object-cover"
                                />
                                <span class="truncate">{{ suggestion.name }}</span>
                                <IconMapPin
                                    class="ms-auto size-4 shrink-0 text-muted-foreground"
                                    :data-testid="`${testid}-detected`"
                                />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent
                            side="left"
                            :side-offset="20"
                            class="flex items-center gap-2"
                        >
                            <IconMapPin class="size-4 shrink-0" />
                            {{ $t('settings.preferences.language.browser') }}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>

            <p
                v-if="showsAllHeading"
                class="mt-2 px-2 py-1 text-xs text-muted-foreground"
            >
                {{ $t('settings.preferences.language.all') }}
            </p>
            <button
                v-for="language in filtered"
                :key="language.code"
                type="button"
                :data-testid="`${testid}-option-${language.code}`"
                class="flex min-h-8 w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm transition-control hover:bg-accent"
                @click="choose(language.code)"
            >
                <IconCheck :class="checkClass(language.code)" />
                <img
                    v-if="language.flag"
                    :src="language.flag"
                    alt=""
                    class="h-3.5 w-5 shrink-0 rounded-xs object-cover"
                />
                <span class="truncate">{{ language.name }}</span>
            </button>
        </div>
    </div>
</template>
