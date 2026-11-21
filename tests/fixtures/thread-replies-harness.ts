/**
 * Prints what the composer's thread helpers (`lib/threadReplies.ts`) answer for
 * each platform given as an argument (a reply that is a poll included), the
 * post types that stand alone and why a thread is held back, so
 * `ThreadRepliesParityTest.php` can compare them with `ThreadReplies` on the
 * server.
 *
 * Node strips type annotations on import; the resolve hook maps the `@/` alias
 * and extension-less specifiers onto `resources/js/**.ts`.
 */
import { registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [resourcesDir, ...platforms] = process.argv.slice(2);

registerHooks({
    resolve: (specifier, context, nextResolve) =>
        specifier.startsWith('@/')
            ? nextResolve(pathToFileURL(path.join(path.resolve(resourcesDir), `${specifier.slice(2)}.ts`)).href, context)
            : nextResolve(specifier, context),
});

const {
    replyEmptyKey,
    replyTooLongKey,
    startThreadKey,
    threadStandaloneKey,
    threadMaxReplies,
    threadReplyContentType,
} = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/threadReplies.ts')).href
);
const { THREAD_REPLY_CONTENT_TYPES } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'types/network-options.ts')).href
);

const { missingPermissionKey } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'lib/channelPermissions.ts')).href
);
const { GatedFeature } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'types/channel-permission.ts')).href
);
const { STANDALONE_CONTENT_TYPES } = await import(
    pathToFileURL(path.join(path.resolve(resourcesDir), 'types/content-type.ts')).href
);

const pollReply = { text: 'Which one?', media: [], poll: { options: ['Yes', 'No'] } };

const results = Object.fromEntries(
    platforms.map((platform) => [
        platform,
        {
            reply_content_type: THREAD_REPLY_CONTENT_TYPES[platform] ?? null,
            reply_poll_content_type: threadReplyContentType(platform, pollReply) ?? null,
            max_replies: threadMaxReplies(platform),
            empty_key: replyEmptyKey(platform),
            too_long_key: replyTooLongKey(platform),
            start_key: startThreadKey(platform),
        },
    ]),
);

const thread = { thread_replies: [{ text: 'Second', media: [] }] };
const missing = { thread_replies: { message: 'reconnect', member_message: 'ask an admin' } };

console.log(
    JSON.stringify({
        platforms: results,
        standalone_types: STANDALONE_CONTENT_TYPES,
        gated_features: Object.values(GatedFeature),
        standalone_key: threadStandaloneKey('x_post', thread),
        permission_keys: {
            allowed: missingPermissionKey(thread, {}, true),
            not_used: missingPermissionKey({}, missing, true),
            publish_before_replies: missingPermissionKey(thread, { thread_replies: missing.thread_replies, publish: { message: 'publish', member_message: 'publish member' } }, true),
            analytics_only: missingPermissionKey(thread, { post_insights: missing.thread_replies }, true),
            lacks_scope_admin: missingPermissionKey(thread, missing, true),
            lacks_scope_member: missingPermissionKey(thread, missing, false),
        },
    }),
);
