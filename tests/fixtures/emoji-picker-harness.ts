/**
 * Prints every emoji the picker offers (`data/emojis.ts`), so
 * `SingleEmojiTest.php` can check the server takes each one as a reaction.
 */
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir] = process.argv.slice(2);

const { EMOJIS } = await import(pathToFileURL(path.join(path.resolve(resourcesDir), 'data/emojis.ts')).href);

console.log(JSON.stringify(EMOJIS.map((emoji: { c: string }) => emoji.c)));
