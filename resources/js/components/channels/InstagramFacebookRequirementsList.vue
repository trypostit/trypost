<script setup lang="ts">
import { IconCheck, IconExternalLink } from '@tabler/icons-vue';

withDefaults(defineProps<{ compact?: boolean }>(), { compact: false });

const REQUIREMENTS = ['account_type', 'page', 'admin', 'permissions'] as const;

const LINK_PAGE_HELP_URL =
    'https://www.facebook.com/business/help/connect-instagram-to-page';
</script>

<template>
    <ul class="flex flex-col" :class="compact ? 'gap-2' : 'gap-3'">
        <li
            v-for="requirement in REQUIREMENTS"
            :key="requirement"
            class="flex text-foreground"
            :class="
                compact
                    ? 'gap-2 text-xs leading-[18px]'
                    : 'gap-3 text-sm leading-[21px]'
            "
        >
            <IconCheck
                class="shrink-0 text-success"
                :class="compact ? 'mt-px size-3.5' : 'mt-[2.5px] size-4'"
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
                    {{ $t('accounts.instagram_facebook_requirements.learn_how') }}
                    <IconExternalLink
                        :class="compact ? 'size-3' : 'size-3.5'"
                        aria-hidden="true"
                    />
                </a>
            </span>
        </li>
    </ul>
</template>
