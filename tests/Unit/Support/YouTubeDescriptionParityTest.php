<?php

use App\Support\YouTubeDescription;
use Symfony\Component\Process\Process;

test('youtube description browser validation matches the backend byte contract', function () {
    $corpus = [null, '', 'Title', str_repeat('a', 5000), str_repeat('a', 5001), str_repeat('é', 2500), str_repeat('é', 2500).'a', str_repeat('😀', 1250), str_repeat('😀', 1250).'a', "Line one\nhttps://example.com", 'a < b', 'a > b', ['invalid']];
    $script = <<<'JS'
        import fs from 'node:fs';
        import ts from 'typescript';
        const source = fs.readFileSync(process.argv[1], 'utf8');
        const js = ts.transpileModule(source, {compilerOptions: {module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022}}).outputText;
        const {getYouTubeDescriptionIssue, youtubeDescriptionBytes} = await import('data:text/javascript;base64,' + Buffer.from(js).toString('base64'));
        const corpus = JSON.parse(fs.readFileSync(0, 'utf8'));
        console.log(JSON.stringify({
            results: corpus.map(getYouTubeDescriptionIssue),
            bytes: youtubeDescriptionBytes('ação😀'),
            surrogate: getYouTubeDescriptionIssue('\uD800'),
        }));
    JS;
    $process = new Process(['node', '--input-type=module', '-e', $script, resource_path('js/lib/youtubeDescription.ts')], base_path());
    $process->setInput(json_encode($corpus, JSON_THROW_ON_ERROR));
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['results'])->toEqual(array_map(YouTubeDescription::violation(...), $corpus))
        ->and($result['bytes'])->toBe(strlen('ação😀'))
        ->and($result['surrogate'])->toBe('posts.form.youtube.description_invalid');
});
