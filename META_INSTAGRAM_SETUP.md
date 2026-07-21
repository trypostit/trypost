# Meta Instagram setup

TryPost uses **Instagram API with Instagram Login**. The Instagram app ID is
`1307273551605418`.

## OAuth redirect URLs

In the [Meta Developer Dashboard](https://developers.facebook.com/apps/), open
the app and go to:

1. **Use cases**
2. **Instagram API**
3. **Set up Instagram business login**
4. **Business login settings**
5. **OAuth settings** → **Valid OAuth Redirect URIs**

Add the TryPost production callback:

```text
https://trypost.superclerk.com/accounts/instagram/callback
```

The Instagram app is shared with AGENTFATHER. Do not remove its registered
callbacks. The complete callback list used by both products is:

```text
https://trypost.superclerk.com/accounts/instagram/callback
https://api.aidven.com/connect/oauth/callback
https://local4000.inteclab.org/accounts/instagram/callback
https://local4096.inteclab.org/connect/oauth/callback
```

The dashboard's generated **Embed URL** is only an example and may show one of
the local callbacks. TryPost builds its own authorization URL using
`INSTAGRAM_CLIENT_REDIRECT`; Meta only requires that value to exactly match a
registered callback.

## Application configuration

Production must use:

```dotenv
APP_URL=https://trypost.superclerk.com
INSTAGRAM_CLIENT_ID=1307273551605418
INSTAGRAM_CLIENT_REDIRECT=https://trypost.superclerk.com/accounts/instagram/callback
```

Keep the Instagram app secret and webhook verify token in the deployment secret
store. Never commit them.

## Webhook

The webhook is configured separately from OAuth:

```text
Callback URL: https://trypost.superclerk.com/instagram/webhook
Verify token:  INSTAGRAM_WEBHOOK_VERIFY_TOKEN from the production secret store
Fields:        comments, messages
```

The OAuth callback route is defined in `routes/app.php`. The authorization URL
is built in `app/Http/Controllers/Auth/InstagramController.php`.
