/**
 * Runs the real `resources/js/lib/mediaEditor.ts` preset functions over the
 * `ContentType::mediaRules()` payload of every content type and prints the
 * preset values per content type as JSON, so `MediaEditorPresetsParityTest.php`
 * can assert the editor offers exactly `ContentType::cropPresets()`.
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

const { cropPresetsFor, presetValuesFor } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/mediaEditor.ts')).href
);
const { toMediaRules } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/contentTypeMediaRules.ts')).href
);

const { rules, defaults } = JSON.parse(fs.readFileSync(inputFile, 'utf8'));

const boundsOf = (rule) => ({ min: rule.aspectRatioMin, max: rule.aspectRatioMax });

const summarize = (presets) => presets.map((preset) => ({ value: preset.value, ratio: preset.ratio }));

const valuesFor = (rule) => summarize(cropPresetsFor(presetValuesFor([rule], defaults), boundsOf(rule)));

const noChannelValuesFor = (rule) => summarize(cropPresetsFor(defaults, boundsOf(rule)));

const results = Object.fromEntries(
    Object.entries(rules).map(([contentType, rule]) => [contentType, valuesFor(toMediaRules(rule))]),
);

const noChannelResults = Object.fromEntries(
    Object.entries(rules).map(([contentType, rule]) => [contentType, noChannelValuesFor(toMediaRules(rule))]),
);

console.log(
    JSON.stringify({
        contentTypes: results,
        noChannelWithinBounds: noChannelResults,
        noChannel: cropPresetsFor(presetValuesFor([], defaults)).map((preset) => preset.value),
    }),
);
