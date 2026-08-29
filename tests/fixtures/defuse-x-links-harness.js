/**
 * Runs `resources/js/lib/defuseXLinks.ts` over a corpus and prints the results as
 * JSON, so `tests/Feature/Services/Social/XLinkDefusingParityTest.php` can diff the
 * TypeScript against the PHP it mirrors. Reads the source directly rather than
 * importing it, which keeps the harness free of a bundler.
 *
 * The TLD set is handed in by the PHP side, exactly as the editor receives it as a
 * page prop, so the corpus exercises the regex rather than a second copy of the list.
 */
import fs from 'node:fs';

const [tldsFile, defuserFile, corpusFile] = process.argv.slice(2);

const tlds = new Set(JSON.parse(fs.readFileSync(tldsFile, 'utf8')));
const pattern = new RegExp(
    fs.readFileSync(defuserFile, 'utf8').match(/const LINK_PATTERN =\s*\/(.*)\/giu;/s)[1],
    'giu',
);

const defuse = (content) =>
    content.replace(pattern, (whole, boundary, prefix, host, tld, path) =>
        prefix === '' && !tlds.has(tld.toLowerCase()) ? whole : boundary + host.replaceAll('.', '(.)') + path);

console.log(JSON.stringify(JSON.parse(fs.readFileSync(corpusFile, 'utf8')).map(defuse)));
