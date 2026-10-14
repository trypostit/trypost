<script setup lang="ts">
import InstagramFacebookRequirementsList from '@/components/channels/InstagramFacebookRequirementsList.vue';
import InstagramHelpMenu from '@/components/channels/InstagramHelpMenu.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';

const emit = defineEmits<{
    connect: [];
}>();
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div
                class="mx-auto flex w-full max-w-[720px] flex-col gap-8 px-4 pt-2 pb-8 sm:px-6"
            >
                <header class="flex flex-col gap-2 text-center max-sm:px-10">
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

                    <InstagramFacebookRequirementsList />

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
