<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconKey } from '@tabler/icons-vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import { detectPreferences } from '@/lib/detectPreferences';
import { redirect as githubRedirect } from '@/routes/auth/github';
import { redirect as googleRedirect } from '@/routes/auth/google';
import { redirect as oidcRedirect } from '@/routes/auth/oidc';

const props = withDefaults(
    defineProps<{
        mode: 'login' | 'signup';
        hideDivider?: boolean;
        invite?: string | null;
    }>(),
    { hideDivider: false },
);

const page = usePage();
const googleEnabled = computed(() => Boolean(page.props.googleAuthEnabled));
const githubEnabled = computed(() => Boolean(page.props.githubAuthEnabled));
const oidcEnabled = computed(() => Boolean(page.props.oidcAuthEnabled));
const oidcLabel = computed(() => String(page.props.oidcDisplayName || 'SSO'));
const hasSocial = computed(
    () => googleEnabled.value || githubEnabled.value || oidcEnabled.value,
);

const detected = detectPreferences();

const query = computed(() => {
    const params: Record<string, string> = {
        timezone: detected.timezone,
        week_starts_on: detected.week_starts_on,
    };
    if (detected.time_format) params.time_format = detected.time_format;
    if (props.invite) params.invite = props.invite;
    return params;
});

const googleUrl = computed(() => googleRedirect.url({ query: query.value }));
const githubUrl = computed(() => githubRedirect.url({ query: query.value }));
const oidcUrl = computed(() => oidcRedirect.url({ query: query.value }));
</script>

<template>
    <template v-if="hasSocial">
        <div class="flex flex-col gap-2">
            <Button
                v-if="googleEnabled"
                data-testid="social-login-google"
                variant="outline"
                class="w-full bg-card"
                as="a"
                :href="googleUrl"
            >
                <img
                    src="/images/social/google.svg"
                    alt="Google"
                    class="size-4"
                />
                {{
                    mode === 'login'
                        ? $t('auth.google_login')
                        : $t('auth.google_signup')
                }}
            </Button>

            <Button
                v-if="githubEnabled"
                data-testid="social-login-github"
                variant="outline"
                class="w-full bg-card"
                as="a"
                :href="githubUrl"
            >
                <img
                    src="/images/social/github.svg"
                    alt="GitHub"
                    class="size-4 dark:invert"
                />
                {{
                    mode === 'login'
                        ? $t('auth.github_login')
                        : $t('auth.github_signup')
                }}
            </Button>

            <Button
                v-if="oidcEnabled"
                variant="outline"
                class="w-full"
                as="a"
                :href="oidcUrl"
            >
                <IconKey class="size-4" />
                {{
                    mode === 'login'
                        ? $t('auth.oidc_login', { provider: oidcLabel })
                        : $t('auth.oidc_signup', { provider: oidcLabel })
                }}
            </Button>
        </div>

        <div
            v-if="!hideDivider"
            class="flex items-center gap-3 text-sm text-muted-foreground"
        >
            <span class="h-px flex-1 bg-border-strong" />
            {{ $t('auth.or_continue_with') }}
            <span class="h-px flex-1 bg-border-strong" />
        </div>
    </template>
</template>
