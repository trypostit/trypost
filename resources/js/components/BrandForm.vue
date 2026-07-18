<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { useHttp } from '@inertiajs/vue3';
import { IconCheck, IconLoader2, IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

import FontPicker from '@/components/FontPicker.vue';
import HexColorInput from '@/components/HexColorInput.vue';
import InputError from '@/components/InputError.vue';
import LanguagePicker from '@/components/LanguagePicker.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldGroup,
    FieldLabel,
    FieldTitle,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { autofill as autofillBrand } from '@/routes/app/workspaces';
import type { ContentLanguageOption } from '@/types';

interface BrandFields {
    name?: string;
    brand_website: string;
    brand_description: string;
    brand_voice_traits: string[];
    brand_color: string | null;
    background_color: string | null;
    text_color: string | null;
    brand_font: string;
    image_style: string;
    content_language: string;
    logo_url?: string | null;
}

interface AutofillResponse {
    name: string | null;
    brand_description: string | null;
    content_language: string | null;
    brand_color: string | null;
    background_color: string | null;
    text_color: string | null;
    logo_url: string | null;
    brand_voice_traits: string[] | null;
}

const props = withDefaults(
    defineProps<{
        fields: BrandFields;
        errors: Partial<Record<keyof BrandFields, string>>;
        availableFonts: string[];
        availableImageStyles: string[];
        availableVoiceTraits: Record<string, string[]>;
        availableContentLanguages: ContentLanguageOption[];
        autofill?: boolean;
        showName?: boolean;
    }>(),
    {
        autofill: false,
        showName: false,
    },
);

const autofillHttp = useHttp<{ url: string }, AutofillResponse>({ url: '' });
const isAutofilling = ref(false);
const logoPreview = ref<string | null>(null);

// Only the `style` group stacks; every spectrum group (pov, formality, …) is
// single-select, so picking an option clears the others in that group.
const isTraitSelected = (value: string): boolean => (props.fields.brand_voice_traits ?? []).includes(value);

const toggleTrait = (group: string, value: string): void => {
    const current = props.fields.brand_voice_traits ?? [];

    if (current.includes(value)) {
        props.fields.brand_voice_traits = current.filter((v) => v !== value);
        return;
    }

    if (group === 'style') {
        props.fields.brand_voice_traits = [...current, value];
        return;
    }

    const groupValues = props.availableVoiceTraits[group] ?? [];
    props.fields.brand_voice_traits = [...current.filter((v) => !groupValues.includes(v)), value];
};

const runAutofill = async () => {
    const url = props.fields.brand_website?.trim() ?? '';
    if (!url) {
        toast.error(trans('workspaces.create.autofill_missing_url'));
        return;
    }

    isAutofilling.value = true;
    try {
        autofillHttp.url = url;
        const data = await autofillHttp.post(autofillBrand.url());

        if (data?.name && props.showName && !props.fields.name) props.fields.name = data.name;
        if (data?.brand_description) props.fields.brand_description = data.brand_description;
        if (data?.content_language) props.fields.content_language = data.content_language;
        if (data?.brand_voice_traits?.length) {
            props.fields.brand_voice_traits = data.brand_voice_traits;
        }
        if (data?.brand_color) props.fields.brand_color = data.brand_color;
        if (data?.background_color) props.fields.background_color = data.background_color;
        if (data?.text_color) props.fields.text_color = data.text_color;
        if (data?.logo_url) {
            logoPreview.value = data.logo_url;
            if ('logo_url' in props.fields) {
                props.fields.logo_url = data.logo_url;
            }
        }
        toast.success(trans('workspaces.create.autofill_success'));
    } catch {
        toast.error(trans('workspaces.create.autofill_error'));
    } finally {
        isAutofilling.value = false;
    }
};
</script>

<template>
    <div class="flex flex-col space-y-6">
        <div class="grid gap-2">
            <Label for="brand_website">{{ $t('settings.brand.website') }}</Label>
            <div :class="autofill ? 'flex gap-2' : ''">
                <Input
                    id="brand_website"
                    v-model="fields.brand_website"
                    type="url"
                    :placeholder="$t('settings.brand.website_placeholder')"
                    :class="autofill ? 'flex-1' : ''"
                />
                <Button
                    v-if="autofill"
                    type="button"
                    variant="default"
                    :disabled="isAutofilling || !fields.brand_website"
                    @click="runAutofill"
                >
                    <IconLoader2 v-if="isAutofilling" class="size-4 animate-spin" />
                    <IconSparkles v-else class="size-4" />
                    {{ $t('workspaces.create.autofill') }}
                </Button>
            </div>
            <p v-if="autofill && logoPreview" class="flex items-center gap-2 text-xs text-muted-foreground">
                <img :src="logoPreview" alt="" class="h-6 w-6 rounded object-cover" />
                {{ $t('workspaces.create.logo_captured') }}
            </p>
            <InputError :message="errors.brand_website" />
        </div>

        <div v-if="showName" class="grid gap-2">
            <Label for="name">{{ $t('settings.brand.name') }}</Label>
            <Input id="name" v-model="fields.name" :placeholder="$t('settings.brand.name_placeholder')" />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="brand_description">{{ $t('settings.brand.brand_description') }}</Label>
            <Textarea
                id="brand_description"
                v-model="fields.brand_description"
                :placeholder="$t('settings.brand.brand_description_placeholder')"
                rows="3"
            />
            <InputError :message="errors.brand_description" />
        </div>

        <div class="grid gap-2">
            <Label for="content_language">{{ $t('settings.brand.content_language') }}</Label>
            <LanguagePicker
                v-model="fields.content_language"
                :options="availableContentLanguages"
                :placeholder="$t('settings.brand.language_placeholder')"
                :search-placeholder="$t('settings.brand.language_search')"
                :empty-text="$t('settings.brand.language_empty')"
            />
            <p class="text-xs font-medium text-foreground/60">
                {{ $t('settings.brand.content_language_description') }}
            </p>
            <InputError :message="errors.content_language" />
        </div>

        <div class="grid gap-5">
            <div class="grid gap-1">
                <Label>{{ $t('settings.brand.voice') }}</Label>
                <p class="text-xs font-medium text-foreground/60">{{ $t('settings.brand.voice_description') }}</p>
            </div>

            <div v-for="(values, group) in availableVoiceTraits" :key="group" class="grid gap-2">
                <Label>{{ $t(`settings.brand.voice_group.${group}`) }}</Label>
                <FieldGroup class="flex flex-row flex-wrap gap-2 [--radius:9999rem]">
                    <FieldLabel v-for="value in values" :key="value" :for="`voice-${value}`" class="!w-fit">
                        <Field
                            orientation="horizontal"
                            class="gap-1.5 overflow-hidden !px-3 !py-1.5 transition-all duration-100 ease-linear group-has-data-[state=checked]/field-label:!px-2"
                        >
                            <Checkbox
                                :id="`voice-${value}`"
                                :model-value="isTraitSelected(value)"
                                class="-ml-6 -translate-x-1 rounded-full opacity-0 transition-all duration-100 ease-linear data-[state=checked]:ml-0 data-[state=checked]:translate-x-0 data-[state=checked]:opacity-100"
                                @update:model-value="toggleTrait(group, value)"
                            />
                            <FieldTitle>{{ $t(`settings.brand.voice_trait.${value}`) }}</FieldTitle>
                        </Field>
                    </FieldLabel>
                </FieldGroup>
            </div>
            <InputError :message="errors.brand_voice_traits" />
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="brand_color">{{ $t('settings.brand.brand_color') }}</Label>
                <HexColorInput v-model="fields.brand_color" />
                <InputError :message="errors.brand_color" />
            </div>
            <div class="grid gap-2">
                <Label for="background_color">{{ $t('settings.brand.background_color') }}</Label>
                <HexColorInput v-model="fields.background_color" />
                <InputError :message="errors.background_color" />
            </div>
            <div class="grid gap-2">
                <Label for="text_color">{{ $t('settings.brand.text_color') }}</Label>
                <HexColorInput v-model="fields.text_color" />
                <InputError :message="errors.text_color" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="brand_font">{{ $t('settings.brand.font') }}</Label>
            <FontPicker
                v-model="fields.brand_font"
                :fonts="availableFonts"
                :placeholder="$t('settings.brand.font_placeholder')"
                :search-placeholder="$t('settings.brand.font_search')"
                :empty-text="$t('settings.brand.font_empty')"
            />
            <InputError :message="errors.brand_font" />
        </div>

        <div class="grid gap-2">
            <Label>{{ $t('settings.brand.image_style') }}</Label>
            <p class="text-xs font-medium text-foreground/60">
                {{ $t('settings.brand.image_style_description') }}
            </p>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <button
                    v-for="style in availableImageStyles"
                    :key="style"
                    type="button"
                    :aria-pressed="fields.image_style === style"
                    :class="[
                        'group relative flex flex-col overflow-hidden rounded-xl border-2 border-foreground bg-card text-left shadow-sm transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        fields.image_style === style
                            ? '-translate-y-0.5 shadow-md'
                            : 'hover:-translate-y-0.5 hover:shadow-md',
                    ]"
                    @click="fields.image_style = style"
                >
                    <div class="relative aspect-square w-full overflow-hidden border-b-2 border-foreground bg-muted">
                        <img
                            :src="`/images/branding/image-styles/${style}.webp`"
                            :alt="$t(`settings.brand.image_style_${style}`)"
                            class="size-full object-cover"
                            loading="lazy"
                        />
                        <div
                            v-if="fields.image_style === style"
                            class="absolute right-2 top-2 flex size-7 items-center justify-center rounded-full border-2 border-foreground bg-primary text-primary-foreground shadow"
                        >
                            <IconCheck class="size-4" stroke-width="3" />
                        </div>
                    </div>
                    <span
                        :class="[
                            'block truncate px-3 py-2 text-center text-sm font-semibold',
                            fields.image_style === style ? 'bg-foreground text-background' : 'bg-card text-foreground',
                        ]"
                    >
                        {{ $t(`settings.brand.image_style_${style}`) }}
                    </span>
                </button>
            </div>
            <InputError :message="errors.image_style" />
        </div>
    </div>
</template>
