<script setup lang="ts">
import {
    IconCheck,
    IconExternalLink,
} from '@tabler/icons-vue';

import InstagramHelpMenu from '@/components/channels/InstagramHelpMenu.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';

const emit = defineEmits<{
    connect: [];
}>();

const REQUIREMENTS = ['account_type', 'page', 'admin', 'permissions'] as const;

const LINK_PAGE_HELP_URL =
    'https://www.facebook.com/business/help/connect-instagram-to-page';
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
                        {{ $t('accounts.instagram_facebook_requirements.title') }}
                    </DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        {{ $t('accounts.instagram_facebook_requirements.subtitle') }}
                    </DialogDescription>
                </header>

                <section
                    class="mx-auto flex w-full max-w-[500px] flex-col gap-4 rounded-xl border border-border bg-card p-6"
                    data-testid="instagram-facebook-requirements"
                >
                    <h3
                        class="flex items-center gap-2 text-lg leading-tight font-medium text-foreground"
                    >
                        <span
                            class="flex items-center gap-0.5 text-foreground"
                            aria-hidden="true"
                        >
                            <PlatformLogo platform="instagram" :size="16" />
                            <span class="text-xs">+</span>
                            <PlatformLogo platform="facebook" :size="16" />
                        </span>
                        {{ $t('accounts.instagram_facebook_requirements.heading') }}
                    </h3>

                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="requirement in REQUIREMENTS"
                            :key="requirement"
                            class="flex gap-3 text-sm leading-[21px] text-foreground"
                        >
                            <IconCheck
                                class="mt-[2.5px] size-4 shrink-0 text-success"
                                aria-hidden="true"
                            />
                            <span>
                                <strong class="font-medium">{{
                                    $t(
                                        `accounts.instagram_facebook_requirements.items.${requirement}.lead`,
                                    )
                                }}</strong>
                                {{
                                    $t(
                                        `accounts.instagram_facebook_requirements.items.${requirement}.rest`,
                                    )
                                }}
                                <a
                                    v-if="requirement === 'page'"
                                    :href="LINK_PAGE_HELP_URL"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 text-primary-text underline underline-offset-2 hover:text-primary-text-hover"
                                    data-testid="instagram-facebook-requirements-learn-how"
                                >
                                    {{
                                        $t(
                                            'accounts.instagram_facebook_requirements.learn_how',
                                        )
                                    }}
                                    <IconExternalLink
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                </a>
                            </span>
                        </li>
                    </ul>

                    <p
                        class="border-t border-border pt-4 text-xs text-muted-foreground"
                    >
                        {{ $t('accounts.instagram_facebook_requirements.note') }}
                    </p>
                </section>
            </div>
        </div>

        <footer
            class="flex shrink-0 items-center justify-between gap-3 border-t border-border px-4 py-4 sm:px-6"
        >
            <InstagramHelpMenu />
            <Button
                size="lg"
                data-testid="instagram-facebook-requirements-connect"
                @click="emit('connect')"
            >
                {{ $t('accounts.instagram_facebook_requirements.connect') }}
            </Button>
        </footer>
    </div>
</template>
