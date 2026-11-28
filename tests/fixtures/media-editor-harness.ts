/**
 * Runs the real `resources/js/lib/app/mediaEditor.ts` preset functions over the
 * `ContentType::mediaRules()` payload of every content type and prints the
 * preset values per content type as JSON, so `MediaEditorPresetsParityTest.php`
 * can assert the editor offers exactly `ContentType::cropPresets()`, and
 * draws every `CropPreset` the server can send.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * and extension-less specifiers onto `resources/js/**.ts`.
 */
import fs from 'node:fs';
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir, inputFile] = process.argv.slice(2);

registerHooks({
    resolve: (specifier, context, nextResolve) =>
        specifier.startsWith('@/')
            ? nextResolve(pathToFileURL(path.join(path.resolve(resourcesDir), `${specifier.slice(2)}.ts`)).href, context)
            : nextResolve(specifier, context),
});

const { coverCropPresetFor, cropPresetsFor, editorTabsFor, presetValuesFor } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/app/mediaEditor.ts')).href
);
const { toMediaRules } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/app/contentTypeMediaRules.ts')).href
);

type Bounds = { aspectRatioMin: number | null; aspectRatioMax: number | null };

type Preset = { value: string; ratio: number | null };

const { rules, defaults, every }: { rules: Record<string, unknown>; defaults: unknown; every: string[] } = JSON.parse(
    fs.readFileSync(inputFile, 'utf8'),
);

const boundsOf = (rule: Bounds) => ({ min: rule.aspectRatioMin, max: rule.aspectRatioMax });

const summarize = (presets: Preset[]) => presets.map((preset) => ({ value: preset.value, ratio: preset.ratio }));

const valuesFor = (rule: Bounds) => summarize(cropPresetsFor(presetValuesFor([rule], defaults), boundsOf(rule)));

const noChannelValuesFor = (rule: Bounds) => summarize(cropPresetsFor(defaults, boundsOf(rule)));

const results = Object.fromEntries(
    Object.entries(rules).map(([contentType, rule]) => [contentType, valuesFor(toMediaRules(rule))]),
);

const video = { id: 'video', type: 'video', url: 'https://example.com/clip.mp4', mime_type: 'video/mp4' };

const videoTabs = Object.fromEntries(
    Object.entries(rules).map(([contentType, rule]) => [contentType, editorTabsFor(video, [toMediaRules(rule)])]),
);

const coverPresets = Object.fromEntries(
    Object.entries(rules).map(([contentType, rule]) => [contentType, coverCropPresetFor([toMediaRules(rule)])]),
);

const mixedCoverPresets = {
    facebook_post_and_instagram_reel: coverCropPresetFor([toMediaRules(rules.facebook_post), toMediaRules(rules.instagram_reel)]),
    facebook_post_and_instagram_feed: coverCropPresetFor([toMediaRules(rules.facebook_post), toMediaRules(rules.instagram_feed)]),
};

const noChannelResults = Object.fromEntries(
    Object.entries(rules).map(([contentType, rule]) => [contentType, noChannelValuesFor(toMediaRules(rule))]),
);

console.log(
    JSON.stringify({
        contentTypes: results,
        noChannelWithinBounds: noChannelResults,
        videoTabs,
        coverPresets,
        mixedCoverPresets,
        every: summarize(cropPresetsFor(every)),
        noChannel: cropPresetsFor(presetValuesFor([], defaults)).map((preset: Preset) => preset.value),
    }),
);
