# Independent social posts

**Date:** 2026-09-24  
**Status:** proposed for review

## Goal

Create and manage one independent post per selected social account. A person may compose several posts in one dialog, but saving four destinations creates four `posts` rows, whether the result is a draft or a scheduled publication. Editing a card changes only its own post.

## Decisions from the product discussion

| Concern | Decision |
| --- | --- |
| Creation | Two-step dialog: shared caption/media/account selection, then expandable customization for each selected account. The browser holds one composition with account-keyed overrides until submission. |
| Persistence | One `posts` row for every selected account. No batch/group entity, no synchronized edits, and no grouping in the post list. All rows are created in one database transaction. |
| Drafts | “Save Draft” creates one draft per selected account, just as “Schedule” creates one scheduled post per account. A new post always has one selected destination. |
| Editing | The social account and network are fixed. Caption, media, crop, supported network settings, `content_type` (e.g. Instagram Post/Reel/Story), and schedule can change on that one post while its status permits editing. |
| Media upload | Reuse the existing workspace Asset Library and chunked upload. Assets may be uploaded while composing, before a post exists. The post rows are created at Save Draft/Schedule/Publish. |
| Navigation | The proposed sidebar and tabs (Todos, Agendados, Postados, Rascunhos) are deferred by the user's later “esquece isso por enquanto”. Keep current navigation/routes in this refactor. The screenshot informs the independent card/dialog pattern only. |
| Surfaces | Web, REST API, and MCP use the same post Actions and offer the same single-account and multi-account creation capabilities. |

## Physical model decision

Keep `posts` and `post_platforms` for this refactor. **New posts have exactly one enabled `post_platforms` row** and no disabled placeholder rows. Four selected accounts still create four actual `posts` rows. `posts` owns caption, media, schedule, labels, comments, and the post status; its one target owns the account snapshot, `content_type`, platform metadata, provider ID/URL, provider status, retry/review state, and errors.

This preserves the existing publisher contract (`publish(PostPlatform $postPlatform)`), queue checkpoints, Google Business review/derivative cleanup, analytics foreign key (`analytics_publications.post_platform_id`), and historical external IDs. Collapsing the physical tables now would require changing the publishers and every API/MCP, analytics, observer, webhook, recovery, and notification path before the new user flow could ship. The second table is a delivery record, not a user-visible group. Physical consolidation can be reconsidered after the one-destination invariant has run in production; it is not a prerequisite for this feature.

The application must never infer a one-account limit from the network name. Two Instagram accounts selected together produce two posts. `social_account_id` is the identity of a destination; `content_type` is mutable only among types compatible with that account's platform.

## Composition and media semantics

The dialog state is:

```ts
type DestinationDraft = {
    social_account_id: string;
    content_type: string;
    meta: Record<string, unknown>;
    content?: string;
    media?: MediaItem[];
};

type PostComposition = {
    content: string;
    media: MediaItem[];
    scheduled_at: string | null;
    status: 'draft' | 'scheduled' | 'publishing';
    label_ids: string[];
    destinations: DestinationDraft[];
};
```

Overrides are keyed by **social account ID**, not network. An unset override inherits the current shared value. An explicit empty caption or empty media array replaces the shared value. Going back to step one changes only destinations still inheriting that field. Removing and re-adding an account starts with shared defaults, avoiding stale hidden overrides. The final request materializes one complete content/media/meta record per account; it never persists a shared parent post.

Each post may reference the same original uploaded asset, because `posts.media` stores media snapshots and the workspace owns the asset. A user-selected crop creates a new uploaded asset and changes only that destination's media snapshot. Reuse the existing `ImageCropperDialog`/`imageCrop` geometry and the existing asset upload endpoint, extending the cropper beyond its current square output. Crop only static raster images; video, PDF, GIF animation, and unsupported image formats keep the original. Previews display the exact asset URL that publishing will use. No publisher silently applies a second user crop; existing platform-required automatic fitting remains.

Drafts may be incomplete. Scheduling and immediate publishing validate each resulting post against its own `content_type`, media rules, caption limit after `ContentSanitizer`, and required platform metadata. An invalid destination rejects the **entire** batch with errors keyed by destination index; no partial set of post rows is committed. Uploaded assets remain available in the workspace library if the final request fails. Prevent double submission while the request is in flight.

## Shared Action contract

`CreateChannelPost::execute(Workspace $workspace, User $user, array $data): Post` creates **one** post with exactly one destination; it does not call `SyncPostPlatforms` or create disabled destinations. Internal callers (AI and repurpose) pass one destination. `CreatePosts::execute(Workspace $workspace, User $user, array $composition): Collection` validates the complete destination set, starts one transaction, materializes each destination with `CreateChannelPost`, attaches labels, sets the requested state, and returns posts in the same order as the input. Queue dispatch for immediate publication occurs after commit. The existing grouped `CreatePost` Action is retired once its callers have moved. `UpdatePost::execute(...)` edits one post and its one enabled target. It may change `content_type` and `meta` but may not replace `social_account_id` or enable a sibling target.

New writes require at least one account and reject duplicate account IDs. The account must be active, in the current workspace, and compatible with its `content_type`. An account disconnected after draft creation remains represented by its target snapshot; scheduling checks publishability and editing cannot switch it to another account.

`posts.scheduled_at` remains the schedule source. `ProcessScheduledPosts` claims each due post and `PublishPost` dispatches its single target. An error or retry on one post does not alter another post created from the same dialog. `FinalizePostPublication` remains the only settler; with one enabled target, a new post settles as published or failed. `partially_published` remains meaningful for untouched legacy multi-target history only.

## Web, API, MCP, and navigation

- Web: New Post opens the two-step dialog from Posts and Calendar. Edit opens the same dialog in single-destination mode, with its account fixed. Existing deep links to `/posts/create` and `/posts/{post}/edit` open or redirect into the dialog instead of rendering the old full editor. Read-only published detail, comments, metrics, AI functions, and duplication continue to work; duplication makes a new single-destination draft.
- Posts page: retain the current sidebar and status routes for now. Each card identifies one social account and has actions for that post only. The list and calendar include one item per new post. An old multi-target published/failed post is displayed as one **read-only card per enabled target**, using the target snapshot/status/URL, without rewriting its database history.
- API: existing `POST /posts` creates one destination and retains its single-resource response. Add `POST /posts/batch` to create several destinations and return an ordered `posts` array. An old request to `POST /posts` with multiple `platforms` returns a clear 422 directing the caller to the batch endpoint; it must not silently create a grouped post. Existing one-post GET/PUT/DELETE routes operate on one post and reject attempts to switch the destination. The batch input uses `destinations[]` and the composition shape above.
- MCP: `CreatePostTool` accepts one destination, `CreatePostsTool` accepts several and returns the ordered IDs. Both call the same Actions as web and API. `UpdatePostTool` and `PublishPostTool` operate on one post. Read/list/preview/metrics tools show one destination per new post and may still read legacy history.
- Webhooks/Echo/PostHog: the existing `PostCreated`/status/deleted events fire once per created post after commit. Four new posts produce four post IDs. The user-facing list refreshes from those events. No new group event is introduced.

## Production data and legacy behavior

Existing data has many disabled `post_platforms` placeholders because `SyncPostPlatforms` created one row for every active account. **Only enabled targets represent intended destinations.** Never convert a disabled placeholder into a post.

Before rollout, run a read-only production audit of post counts by status and enabled-target count, in-flight jobs, zero-target drafts/scheduled posts, target rows with null account snapshots, comments, labels, analytics references, and media paths. The local database is not evidence of production volume. Test the split command on a restored production snapshot in PostgreSQL and MySQL.

An idempotent `posts:split-legacy-active` command converts legacy `draft` and `scheduled` posts with more than one enabled target: keep the original post ID for its first target (stable order by target ID), clone the parent for every other enabled target, move each target's `post_id` to its clone, copy label links and the comment tree without sending new creation/mention/webhook events, and keep the common schedule and content/media snapshot. It does not duplicate uploaded files. It skips in-flight and settled posts. New clones are checked before commit so every active post has one enabled target. Re-running the command finds no multi-target active rows and changes nothing.

Legacy drafts with **zero** enabled targets are preserved and surfaced in Drafts as “choose an account” recovery items; saving from that dialog creates independent destination posts and removes the empty legacy draft after success. A legacy scheduled post with zero enabled targets is a release blocker: the audit must resolve it before switching the web flow, because the existing scheduler cannot publish it. Legacy in-flight posts finish through the current pipeline and become read-only history. Settled legacy posts remain intact, so comments, analytics FKs, webhook logs, URLs, and external post IDs are preserved. Their cards are flattened in the web list; their API/MCP historical resource remains readable. No deployed migration or historical row is deleted in this release.

The split must run while new post writes and `posts:process-scheduled` dispatch are paused and publishing workers have drained the affected scheduled rows. Deploy the code containing the audit/split command with the new editor disabled; perform the split and assertions before enabling the new write paths. Retain a database backup. If a cutover check fails, keep the old application active and resume its scheduler; the split does not destroy parent IDs or media and is safe to rerun. If new-format posts have already been written, use a forward fix rather than reverting the database to an old snapshot.

## Verification

- One, two, and four accounts; two accounts on the same network; duplicate/inactive/foreign-workspace accounts; one invalid destination in a batch; draft with incomplete media; scheduled validation; double submit.
- Each post can be edited, rescheduled, published, retried, duplicated, and deleted without changing siblings. Changing Instagram Post to Reel/Story revalidates media; changing its account is rejected.
- Per-destination crop and caption override, inheritance after returning to step one, media upload before any post exists, crop failure and unsupported media, previews matching persisted media.
- Legacy active split preserves schedules, IDs, target metadata, labels, nested comments, and media references; repeat run is no-op. Legacy zero-target drafts remain accessible, and settled history keeps analytics/old IDs.
- PostgreSQL and MySQL affected tests, narrow browser tests for dialog and existing navigation, API and MCP contract tests, Wayfinder generation, i18n parity, Pint, Vue typecheck/lint.
