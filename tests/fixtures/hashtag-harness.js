/**
 * Runs the pattern in `resources/js/lib/hashtags.ts` over a corpus and prints the
 * counts as JSON, so `tests/Feature/Services/Social/HashtagParityTest.php` can
 * compare them with `App\Support\Hashtags`. Reads the source directly instead of
 * importing it, which keeps the harness free of a bundler.
 */
import fs from 'node:fs';

const [sourceFile, corpusFile] = process.argv.slice(2);

const pattern = new RegExp(
    fs.readFileSync(sourceFile, 'utf8').match(/const HASHTAG_PATTERN =\s*\/(.*)\/gu;/s)[1],
    'gu',
);

console.log(JSON.stringify(JSON.parse(fs.readFileSync(corpusFile, 'utf8')).map((text) => text.match(pattern)?.length ?? 0)));
