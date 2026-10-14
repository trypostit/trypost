<script setup lang="ts">
import {
    IconCheck,
    IconChevronDown,
    IconInfoCircle,
    IconRefresh,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import InstagramFacebookRequirementsList from '@/components/channels/InstagramFacebookRequirementsList.vue';
import InstagramHelpMenu from '@/components/channels/InstagramHelpMenu.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Platform } from '@/types/platform';

const props = defineProps<{
    methods: string[];
}>();

const emit = defineEmits<{
    select: [method: string];
}>();

const STANDALONE_FEATURES = ['automatic', 'metrics'] as const;
const FACEBOOK_FEATURES = ['automatic', 'metrics', 'music'] as const;

const showsStandalone = computed(() =>
    props.methods.includes(Platform.Instagram),
);
const showsFacebook = computed(() =>
    props.methods.includes(Platform.InstagramFacebook),
);
const showsBoth = computed(() => showsStandalone.value && showsFacebook.value);
const standaloneRequirementsOpen = ref(true);
const facebookRequirementsOpen = ref(true);

const connectStandalone = (): void => {
    emit('select', Platform.Instagram);
};

const connectFacebook = (): void => {
    emit('select', Platform.InstagramFacebook);
};
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div
                class="mx-auto flex w-full max-w-[800px] flex-col gap-8 px-4 pt-2 pb-8 sm:px-6"
            >
                <header class="flex flex-col gap-2 text-center max-sm:px-10">
                    <DialogTitle
                        class="font-sans text-xl leading-tight font-medium text-foreground"
                    >
                        {{ $t('accounts.instagram_connect.title') }}
                    </DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        {{ $t('accounts.instagram_connect.description') }}
                    </DialogDescription>
                </header>

                <div
                    class="mx-auto grid w-full gap-x-4 gap-y-4"
                    :class="showsBoth ? 'sm:grid-cols-2' : 'max-w-[432px]'"
                    data-testid="instagram-connect-options"
                >
                    <section
                        v-if="showsStandalone"
                        class="row-span-4 grid grid-rows-subgrid gap-5 rounded-xl border border-border bg-card p-6"
                        data-testid="instagram-connect-professional"
                    >
                        <div class="flex flex-col items-start gap-2">
                            <PlatformLogo platform="instagram" :size="24" />
                            <h3 class="text-lg leading-tight text-foreground">
                                <span class="font-medium">{{
                                    $t(
                                        'accounts.instagram_connect.professional_title',
                                    )
                                }}</span>
                                {{
                                    $t(
                                        'accounts.instagram_connect.professional_types',
                                    )
                                }}
                            </h3>
                            <span
                                class="inline-flex items-center gap-1 rounded-md bg-primary-subtle px-1.5 py-0.5 text-xs text-primary-text"
                            >
                                <IconRefresh class="size-3.5" aria-hidden="true" />
                                {{ $t('accounts.instagram_connect.badge') }}
                            </span>
                        </div>

                        <ul class="flex flex-col gap-3">
                            <li
                                v-for="feature in STANDALONE_FEATURES"
                                :key="feature"
                                class="flex gap-3 text-sm leading-[21px] text-foreground"
                            >
                                <IconCheck
                                    class="mt-[2.5px] size-4 shrink-0 text-success"
                                    aria-hidden="true"
                                />
                                <span>
                                    <span class="font-medium">{{
                                        $t(
                                            `accounts.instagram_connect.features.${feature}.title`,
                                        )
                                    }}</span>
                                    -
                                    {{
                                        $t(
                                            `accounts.instagram_connect.features.${feature}.description`,
                                        )
                                    }}
                                </span>
                            </li>
                        </ul>

                        <Button
                            size="lg"
                            class="w-full"
                            data-testid="instagram-connect-standalone"
                            @click="connectStandalone"
                        >
                            {{ $t('accounts.instagram_connect.connect') }}
                        </Button>

                        <Collapsible
                            v-model:open="standaloneRequirementsOpen"
                            class="border-t border-border pt-5"
                        >
                            <CollapsibleTrigger
                                class="flex w-full items-center gap-3 text-start text-sm leading-[21px] font-medium text-foreground"
                                data-testid="instagram-connect-standalone-requirements-toggle"
                            >
                                <IconInfoCircle
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="flex-1">{{
                                    $t(
                                        'accounts.instagram_facebook_requirements.heading',
                                    )
                                }}</span>
                                <IconChevronDown
                                    class="size-4 shrink-0 text-muted-foreground transition-transform"
                                    :class="{
                                        'rotate-180': standaloneRequirementsOpen,
                                    }"
                                    aria-hidden="true"
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent
                                class="pt-3"
                                data-testid="instagram-connect-standalone-requirements"
                            >
                                <p
                                    class="flex gap-2 text-xs leading-[18px] text-foreground"
                                >
                                    <IconCheck
                                        class="mt-px size-3.5 shrink-0 text-success"
                                        aria-hidden="true"
                                    />
                                    {{
                                        $t(
                                            'accounts.instagram_connect.convert_hint',
                                        )
                                    }}
                                </p>
                            </CollapsibleContent>
                        </Collapsible>
                    </section>

                    <section
                        v-if="showsFacebook"
                        class="row-span-4 grid grid-rows-subgrid gap-5 rounded-xl border border-border bg-card p-6"
                        data-testid="instagram-connect-facebook-card"
                    >
                        <div class="flex flex-col items-start gap-2">
                            <span
                                class="flex items-center gap-1 text-foreground"
                                aria-hidden="true"
                            >
                                <PlatformLogo platform="instagram" :size="24" />
                                <span class="text-xs">+</span>
                                <PlatformLogo platform="facebook" :size="24" />
                            </span>
                            <h3 class="text-lg leading-tight text-foreground">
                                <span class="font-medium">{{
                                    $t(
                                        'accounts.instagram_connect.professional_title',
                                    )
                                }}</span>
                                {{
                                    $t('accounts.instagram_connect.facebook_types')
                                }}
                            </h3>
                            <span
                                class="inline-flex items-center gap-1 rounded-md bg-primary-subtle px-1.5 py-0.5 text-xs text-primary-text"
                            >
                                <IconRefresh class="size-3.5" aria-hidden="true" />
                                {{ $t('accounts.instagram_connect.badge') }}
                            </span>
                        </div>

                        <ul class="flex flex-col gap-3">
                            <li
                                v-for="feature in FACEBOOK_FEATURES"
                                :key="feature"
                                class="flex gap-3 text-sm leading-[21px] text-foreground"
                                :data-testid="`instagram-connect-facebook-feature-${feature}`"
                            >
                                <IconCheck
                                    class="mt-[2.5px] size-4 shrink-0 text-success"
                                    aria-hidden="true"
                                />
                                <span>
                                    <span class="font-medium">{{
                                        $t(
                                            `accounts.instagram_connect.features.${feature}.title`,
                                        )
                                    }}</span>
                                    -
                                    {{
                                        $t(
                                            `accounts.instagram_connect.features.${feature}.description`,
                                        )
                                    }}
                                </span>
                            </li>
                        </ul>

                        <Button
                            size="lg"
                            class="w-full"
                            data-testid="instagram-connect-facebook"
                            @click="connectFacebook"
                        >
                            {{
                                $t(
                                    'accounts.instagram_facebook_requirements.connect',
                                )
                            }}
                        </Button>

                        <Collapsible
                            v-model:open="facebookRequirementsOpen"
                            class="border-t border-border pt-5"
                        >
                            <CollapsibleTrigger
                                class="flex w-full items-center gap-3 text-start text-sm leading-[21px] font-medium text-foreground"
                                data-testid="instagram-connect-facebook-requirements-toggle"
                            >
                                <IconInfoCircle
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="flex-1">{{
                                    $t(
                                        'accounts.instagram_facebook_requirements.heading',
                                    )
                                }}</span>
                                <IconChevronDown
                                    class="size-4 shrink-0 text-muted-foreground transition-transform"
                                    :class="{ 'rotate-180': facebookRequirementsOpen }"
                                    aria-hidden="true"
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent
                                class="pt-3"
                                data-testid="instagram-connect-facebook-requirements"
                            >
                                <InstagramFacebookRequirementsList compact />
                            </CollapsibleContent>
                        </Collapsible>
                    </section>
                </div>
            </div>
        </div>

        <footer class="shrink-0 border-t border-border px-4 py-4 sm:px-6">
            <InstagramHelpMenu />
        </footer>
    </div>
</template>
