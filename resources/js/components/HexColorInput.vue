<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

interface Props {
    modelValue: string | null | undefined;
    name?: string;
    placeholder?: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: '#000000',
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const HEX_RE = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/;
const PRESETS = [
    '#000000',
    '#737373',
    '#ffffff',
    '#ef4444',
    '#f97316',
    '#f59e0b',
    '#eab308',
    '#84cc16',
    '#22c55e',
    '#10b981',
    '#14b8a6',
    '#06b6d4',
    '#0ea5e9',
    '#3b82f6',
    '#6366f1',
    '#8b5cf6',
    '#d946ef',
    '#ec4899',
];

const text = ref(props.modelValue ?? '');
const open = ref(false);

// HSV state
const hue = ref(0);
const saturation = ref(1);
const value = ref(1);

const isValid = computed(() => text.value === '' || HEX_RE.test(text.value));
const swatchColor = computed(() =>
    isValid.value && text.value !== '' ? text.value : '',
);

// --- color math -------------------------------------------------------------

const hexToRgb = (hex: string): [number, number, number] | null => {
    const clean = hex.replace('#', '');
    if (clean.length === 3) {
        return [
            parseInt(clean[0] + clean[0], 16),
            parseInt(clean[1] + clean[1], 16),
            parseInt(clean[2] + clean[2], 16),
        ];
    }
    if (clean.length === 6 || clean.length === 8) {
        return [
            parseInt(clean.slice(0, 2), 16),
            parseInt(clean.slice(2, 4), 16),
            parseInt(clean.slice(4, 6), 16),
        ];
    }
    return null;
};

const rgbToHex = (r: number, g: number, b: number): string => {
    const toHex = (n: number) =>
        Math.round(Math.max(0, Math.min(255, n)))
            .toString(16)
            .padStart(2, '0');
    return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
};

const rgbToHsv = (
    r: number,
    g: number,
    b: number,
): [number, number, number] => {
    const rn = r / 255,
        gn = g / 255,
        bn = b / 255;
    const max = Math.max(rn, gn, bn);
    const min = Math.min(rn, gn, bn);
    const d = max - min;
    let h = 0;
    if (d !== 0) {
        if (max === rn) h = ((gn - bn) / d) % 6;
        else if (max === gn) h = (bn - rn) / d + 2;
        else h = (rn - gn) / d + 4;
        h *= 60;
        if (h < 0) h += 360;
    }
    const s = max === 0 ? 0 : d / max;
    return [h, s, max];
};

const hsvToRgb = (
    h: number,
    s: number,
    v: number,
): [number, number, number] => {
    const c = v * s;
    const hh = h / 60;
    const x = c * (1 - Math.abs((hh % 2) - 1));
    let r = 0,
        g = 0,
        b = 0;
    if (hh >= 0 && hh < 1) [r, g, b] = [c, x, 0];
    else if (hh < 2) [r, g, b] = [x, c, 0];
    else if (hh < 3) [r, g, b] = [0, c, x];
    else if (hh < 4) [r, g, b] = [0, x, c];
    else if (hh < 5) [r, g, b] = [x, 0, c];
    else [r, g, b] = [c, 0, x];
    const m = v - c;
    return [(r + m) * 255, (g + m) * 255, (b + m) * 255];
};

const syncHsvFromHex = (hex: string) => {
    const rgb = hexToRgb(hex);
    if (!rgb) return;
    const [h, s, v] = rgbToHsv(...rgb);
    if (s > 0) hue.value = h;
    saturation.value = s;
    value.value = v;
};

const emitFromHsv = () => {
    const [r, g, b] = hsvToRgb(hue.value, saturation.value, value.value);
    const hex = rgbToHex(r, g, b);
    text.value = hex;
    emit('update:modelValue', hex);
};

watch(
    () => props.modelValue,
    (incoming) => {
        text.value = incoming ?? '';
        if (incoming && HEX_RE.test(incoming)) syncHsvFromHex(incoming);
    },
    { immediate: true },
);

// --- pointer interactions ---------------------------------------------------

const svRef = ref<HTMLElement | null>(null);
const hueRef = ref<HTMLElement | null>(null);

const setSvFromEvent = (event: PointerEvent) => {
    const el = svRef.value;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const x = Math.min(Math.max(event.clientX - rect.left, 0), rect.width);
    const y = Math.min(Math.max(event.clientY - rect.top, 0), rect.height);
    saturation.value = rect.width === 0 ? 0 : x / rect.width;
    value.value = rect.height === 0 ? 0 : 1 - y / rect.height;
    emitFromHsv();
};

const setHueFromEvent = (event: PointerEvent) => {
    const el = hueRef.value;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const x = Math.min(Math.max(event.clientX - rect.left, 0), rect.width);
    hue.value = rect.width === 0 ? 0 : (x / rect.width) * 360;
    emitFromHsv();
};

const onSvPointerDown = (event: PointerEvent) => {
    (event.target as HTMLElement).setPointerCapture(event.pointerId);
    setSvFromEvent(event);
};
const onSvPointerMove = (event: PointerEvent) => {
    if (event.buttons === 0) return;
    setSvFromEvent(event);
};

const onHuePointerDown = (event: PointerEvent) => {
    (event.target as HTMLElement).setPointerCapture(event.pointerId);
    setHueFromEvent(event);
};
const onHuePointerMove = (event: PointerEvent) => {
    if (event.buttons === 0) return;
    setHueFromEvent(event);
};

// --- text input -------------------------------------------------------------

const onTextInput = (event: Event) => {
    const target = event.target as HTMLInputElement;
    let next = target.value;
    if (next !== '' && !next.startsWith('#')) next = `#${next}`;
    text.value = next.slice(0, 9);

    if (text.value === '') {
        emit('update:modelValue', null);
    } else if (HEX_RE.test(text.value)) {
        emit('update:modelValue', text.value.toLowerCase());
        syncHsvFromHex(text.value);
    }
};

const pickPreset = (hex: string) => {
    text.value = hex;
    syncHsvFromHex(hex);
    emit('update:modelValue', hex);
};

const hueColor = computed(() => {
    const [r, g, b] = hsvToRgb(hue.value, 1, 1);
    return rgbToHex(r, g, b);
});

const svPointerStyle = computed(() => ({
    left: `${saturation.value * 100}%`,
    top: `${(1 - value.value) * 100}%`,
}));

const huePointerStyle = computed(() => ({
    left: `${(hue.value / 360) * 100}%`,
}));
</script>

<template>
    <div class="flex items-center gap-2">
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    :disabled="disabled"
                    class="size-10 shrink-0 cursor-pointer rounded-md border border-border bg-card shadow-xs transition-shadow hover:shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                    :style="
                        swatchColor
                            ? { backgroundColor: swatchColor }
                            : undefined
                    "
                    :aria-label="placeholder"
                />
            </PopoverTrigger>

            <PopoverContent class="w-64 space-y-3 p-3" align="start">
                <!-- Saturation / Value -->
                <div
                    ref="svRef"
                    class="relative h-40 w-full cursor-crosshair touch-none overflow-hidden rounded-md"
                    :style="{
                        backgroundColor: hueColor,
                        backgroundImage:
                            'linear-gradient(to top, #000, transparent), linear-gradient(to right, #fff, transparent)',
                    }"
                    @pointerdown="onSvPointerDown"
                    @pointermove="onSvPointerMove"
                >
                    <div
                        class="pointer-events-none absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow ring-1 ring-black/40"
                        :style="svPointerStyle"
                    />
                </div>

                <!-- Hue slider -->
                <div
                    ref="hueRef"
                    class="relative h-3 w-full cursor-pointer touch-none rounded-full"
                    style="
                        background: linear-gradient(
                            to right,
                            #f00 0%,
                            #ff0 17%,
                            #0f0 33%,
                            #0ff 50%,
                            #00f 67%,
                            #f0f 83%,
                            #f00 100%
                        );
                    "
                    @pointerdown="onHuePointerDown"
                    @pointermove="onHuePointerMove"
                >
                    <div
                        class="pointer-events-none absolute top-1/2 size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-transparent shadow ring-1 ring-black/40"
                        :style="huePointerStyle"
                    />
                </div>

                <!-- Hex input -->
                <Input
                    :model-value="text"
                    :placeholder="placeholder"
                    class="font-mono"
                    :class="
                        !isValid
                            ? 'border-destructive focus-visible:ring-destructive'
                            : ''
                    "
                    spellcheck="false"
                    @input="onTextInput"
                />

                <!-- Presets -->
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="hex in PRESETS"
                        :key="hex"
                        type="button"
                        class="size-5 cursor-pointer rounded-full border border-border transition-transform hover:scale-110"
                        :style="{ backgroundColor: hex }"
                        :aria-label="hex"
                        @click="pickPreset(hex)"
                    />
                </div>
            </PopoverContent>
        </Popover>

        <Input
            :model-value="text"
            :name="name"
            :placeholder="placeholder"
            :disabled="disabled"
            class="font-mono"
            :class="
                !isValid
                    ? 'border-destructive focus-visible:ring-destructive'
                    : ''
            "
            spellcheck="false"
            @input="onTextInput"
        />
    </div>
</template>
