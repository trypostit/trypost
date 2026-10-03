<script setup lang="ts">
import {
    IconCheck,
    IconInfoCircle,
    IconRefresh,
} from '@tabler/icons-vue';

import InstagramHelpMenu from '@/components/channels/InstagramHelpMenu.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Platform } from '@/types/platform';

const props = defineProps<{
    methods: string[];
}>();

const emit = defineEmits<{
    select: [method: string];
}>();

const FEATURES = ['automatic', 'metrics'] as const;

const showsStandalone = () => props.methods.includes(Platform.Instagram);
const showsFacebook = () => props.methods.includes(Platform.InstagramFacebook);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div
                class="mx-auto flex w-full max-w-[720px] flex-col gap-8 px-4 pt-6 pb-8 sm:px-6"
            >
                <header class="flex flex-col gap-2 text-center">
                    <DialogTitle
                        class="font-sans text-xl leading-tight font-medium text-foreground"
                    >
                        {{ $t('accounts.instagram_connect.title') }}
                    </DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        {{ $t('accounts.instagram_connect.description') }}
                    </DialogDescription>
                </header>

                <section
                    v-if="showsStandalone()"
                    class="mx-auto flex w-full max-w-[432px] flex-col gap-5 rounded-xl border border-border bg-card p-6"
                    data-testid="instagram-connect-professional"
                >
                    <div class="flex flex-col items-start gap-2">
                        <h3 class="text-lg leading-tight text-foreground">
                            <span class="font-medium">{{
                                $t('accounts.instagram_connect.professional_title')
                            }}</span>
                            {{ $t('accounts.instagram_connect.professional_types') }}
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
                            v-for="feature in FEATURES"
                            :key="feature"
                            class="flex gap-3 text-sm leading-[21px] text-foreground"
                        >
                            <IconCheck
                                class="mt-[2.5px] size-4 shrink-0 text-success"
                                aria-hidden="true"
                            />
                            <span>
                                <span class="font-medium">{{
                                    $t(`accounts.instagram_connect.features.${feature}.title`)
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
                        @click="emit('select', Platform.Instagram)"
                    >
                        {{ $t('accounts.instagram_connect.connect') }}
                    </Button>

                    <div
                        class="flex gap-3 border-t border-border pt-5 text-sm leading-[21px] font-medium text-foreground"
                    >
                        <IconInfoCircle
                            class="mt-[2.5px] size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <p>{{ $t('accounts.instagram_connect.convert_hint') }}</p>
                    </div>
                </section>

                <p
                    v-if="showsFacebook()"
                    class="flex items-start justify-center gap-2 text-center text-sm leading-[21px] text-foreground"
                >
                    <PlatformLogo
                        platform="facebook"
                        :size="16"
                        class="mt-[2.5px] shrink-0"
                    />
                    <span>
                        <button
                            type="button"
                            class="cursor-pointer underline underline-offset-2 hover:text-primary-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                            data-testid="instagram-connect-facebook"
                            @click="emit('select', Platform.InstagramFacebook)"
                        >
                            {{ $t('accounts.instagram_connect.facebook_link') }}
                        </button>
                        {{ $t('accounts.instagram_connect.facebook_suffix') }}
                    </span>
                </p>
            </div>
        </div>

        <footer class="shrink-0 border-t border-border px-4 py-4 sm:px-6">
            <InstagramHelpMenu />
        </footer>
    </div>
</template>
