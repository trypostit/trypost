# MCP Asset Library tools

This document describes the Asset Library MCP extension implemented on top of the
existing TryPost workspace media model. The extension is schema-compatible with
existing installations: it does not add database tables, columns, or indexes.

## Tools

### `list_assets`

Lists reusable workspace asset-library media from the authenticated user's
current workspace.

Input:

- `page`: optional positive integer, default `1`.
- `per_page`: optional positive integer, default `25`, max `100`.
- `search`: optional filename substring.
- `mime_type`: optional exact MIME type or family filter such as `image/*`.
- `category`: optional media category: `image`, `video`, or `document`.
- `usage`: optional usage filter: `all`, `unused`, or `used`.
- `sort`: optional sort key: `created_at`, `last_used_at`, or
  `publication_usage_count`, `timestamped_publication_usage_count`, or
  `usage_count`.
- `direction`: optional `asc` or `desc`.

Output:

- `assets[]` contains safe media metadata:
  - `asset_id`
  - `filename`
  - `mime_type`
  - `category`
  - `size_bytes`
  - optional dimensions/duration from existing media metadata
  - `created_at`
  - `preview_available`
  - usage projection fields described below
- `pagination` contains pagination metadata.

The response never includes storage paths, storage bucket names, workspace IDs,
or permanent public media URLs.

### `get_asset_preview`

Returns a short-lived preview URL for a single asset in the current workspace.

Input:

- `asset_id`: required UUID for an asset-library media item.

Output:

- `asset_id`
- `mime_type`
- `size_bytes`
- `expires_at`
- `preview_mode`: `temporary_url` for temporary object-store URLs or
  `signed_route` for the local signed preview route.
- `preview_url`

The preview route is signed, expires after five minutes, re-checks workspace
ownership, and returns `404` when the storage object is missing or the media does
not belong to the requested workspace. For Laravel local filesystem disks,
including NFS mounts exposed through the local driver, the route returns a
`BinaryFileResponse`; Symfony handles `Range` and `If-Range`, enabling `206`
partial responses, `416` invalid-range responses, `Content-Length`,
`Accept-Ranges`, seek, and resumable downloads without loading the whole asset
into PHP memory. If a reliable local filesystem path cannot be resolved inside
the disk root, the route falls back to `readStream()` and `fpassthru()`: memory
usage remains constant, but byte-range seeking is not guaranteed. S3-compatible
object storage continues to use the disk temporary URL mode instead of this local
signed route.

### `attach_existing_asset`

Attaches an existing workspace asset-library item to a draft or scheduled post.
The operation is idempotent for the same `post_id` and `asset_id`; duplicate
checks run inside a row lock on the post.

Input:

- `post_id`: required post UUID in the current workspace.
- `asset_id`: required asset UUID in the current workspace.
- `alt`: optional image alt text, max length follows existing post media rules.

Output:

- `asset_id`
- `attached`: `true` only when a new media snapshot was added.
- `already_attached`: `true` when the asset was already present.
- `post`: refreshed post resource.

The tool rejects cross-workspace posts/assets, unauthorized users, non-editable
post states, and media types that the enabled post platforms cannot publish.

## Usage projection contract

Usage metadata is computed from existing post media snapshots and
`post_platforms`; no denormalized asset usage table is required.

Each asset usage object includes:

- `content_usage_count`: number of workspace posts that reference the asset.
- `publication_usage_count`: number of enabled post-platform rows with status
  `published`, including rows where `published_at` is missing.
- `timestamped_publication_usage_count`: number of enabled published
  post-platform rows with a reliable publication timestamp.
- `configured_platforms`: platforms configured on enabled post-platform rows.
- `configured_content_types`: content types configured on enabled
  post-platform rows.
- `published_platforms`: platforms from enabled post-platform rows whose status
  is `published`.
- `published_content_types`: content types from enabled post-platform rows whose
  status is `published`.
- `latest_content_id`: best available associated post ID.
- `latest_content_basis`: basis used for `latest_content_id`.
- `last_used_at`: latest reliable use timestamp, or `null`.
- `last_use_basis`: timestamp basis for `last_used_at`; `mixed` when multiple
  contexts at the same timestamp use different bases.
- `last_use_contexts`: every context tied exactly to `last_used_at`. It is an
  empty list for draft-only usage.
- `days_since_last_use`: difference between UTC calendar dates, not complete
  24-hour periods.

Each `last_use_contexts[]` item contains:

- `content_id`
- `platform`
- `content_type`
- `content_status`
- `publication_status`
- `used_at`
- `use_basis`

Configured platforms/content types are never presented as published usage unless
the corresponding enabled post-platform row is actually `published`. Missing
timestamps prevent temporal calculations, but they do not remove a published row
from `publication_usage_count`.

## Query and performance notes

`AssetUsageQuery` first scopes posts by workspace and then applies JSON
containment against the requested asset IDs. The implementation eager-loads
`postPlatforms.socialAccount` for the bounded post set and does not issue per
asset or per post-platform follow-up queries.

Usage-derived filtering and sorting happen after loading the matching workspace
asset set because the usage projection is calculated from post media snapshots.
Deployments with very large asset libraries should evaluate a dedicated index or
denormalized usage model separately; that would require an explicit database
schema change.

For PostgreSQL, the asset-reference predicate casts `posts.media` to `jsonb`
before applying containment:

```sql
media::jsonb @> ?::jsonb
```

This keeps the predicate valid when the Laravel `json` column maps to either
`json` or `jsonb` in a target PostgreSQL database.
