---
paths:
  - 'app/Actions/Media/**'
  - app/Models/Media.php
---

# Media

## Media rows have one owner; write via SyncOwnedMedia, delete via DeleteOwnedMedia
Every media row has exactly one owner (post_id, idea_id, rss_feed_item_id, a workspace temporary upload, or a mediable logo/avatar); the Media saving guard enforces it and owner FKs are restrictOnDelete, so never cascade media. posts.media / ideas.media are written only by SyncOwnedMedia (inside MediaCopyBatch::run) and by AdoptWorkspaceLibrary::adopt (the one-off library migration). Rows are deleted through DeleteOwnedMedia (locked in id order, files removed after commit by DeleteOrphanedMediaFiles); the only direct deleters are PurgeUserAccess (user avatars, files after commit) and HasMedia::clearMediaCollection (logo/avatar replacement). A temporary upload is adopted (moved) once; every later use copies it. The same holds for rows of an owner the batch deletes before commit (`MediaCopyBatch::releaseOwner`, used by RecoverEmptyDraft). The library collection ('assets') is read only by the adoption (media:adopt-library / release:trypost-2) — no code may write it; the fold_legacy_workspace_media_into_library migration moved the legacy 'ai-generated' workspace rows into it once.

## A save never resolves another workspace's media
Every save resolves media ids and upload tokens within the owner's workspace (ResolveWorkspaceMedia); only the owner's own stored items and same-workspace duplicate/recovery items pass through without a row. media:adopt-library relies on this: it computes the library rows other workspaces reference once per adoption window and trusts that map in the locked delete, which is only safe if no save can create a new cross-workspace reference.
