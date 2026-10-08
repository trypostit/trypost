---
paths:
  - app/Http/Controllers/Auth/GoogleBusinessController.php
  - 'app/Http/Controllers/Auth/**'
---

# Auth

## Connection tokens live only in the pending connection
Google Business (like every redirect network) keeps its access and refresh tokens inside `PendingConnection` (session key `social_connect`) between the callback and "Finish connection", never in a separate session bag. Every stop goes through `failConnection()` / `ConnectFlowException`, whose `fail()` drops the identities and their tokens; a successful finish forgets the whole pending connection. The location photo is read in `accountValues()` only for the locations the user picked, and a reconnect keeps the existing refresh_token when Google omits a new one.

## Finish lands on the channel, return_to only serves exits
"Finish connection" (`SocialController@finish`) always redirects to `app.channels.publish` of the connected channel (the new account, or the first one when reconnecting or refreshing) and flashes `connectedChannel` so the posting-goal dialog opens there for a new channel. The `return_to` target kept in `PendingConnection` is used only by close (X), Back, cancel and the error states (`backUrl`, "Try again"), never after a successful finish.

## Bluesky requires a confirmed email before connection
Do not complete a Bluesky connection or reconnect unless emailConfirmed is explicitly true. TryPost requires the account to be eligible for video publishing even if text posting works. Recheck the authenticated session at Finish connection so pending logins and email changes cannot bypass the requirement. Existing accounts can lose email confirmation later: preserve unconfirmed_email as an actionable publish rejection instead of retrying or treating the session token as expired.
