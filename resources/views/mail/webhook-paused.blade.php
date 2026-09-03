<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
  <meta charset="utf-8">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  @if(isset($title))
  <title>{{ $title }}</title>
  @endif
</head>
<body style="margin: 0; width: 100%; padding: 0; -webkit-font-smoothing: antialiased; word-break: break-word">
  @if(isset($previewText))
  <div style="display: none">{{ $previewText }}</div>
  @endif
  <div role="article" aria-roledescription="email" aria-label="{{ $title }}" lang="en">
    <div style="background-color: #fafafa; font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; padding: 24px">
      <table align="center" cellpadding="0" cellspacing="0" role="none">
        <tr>
          <td style="width: 552px; max-width: 100%">
            <div style="margin-top: 24px; margin-bottom: 24px; text-align: center">
              <a href="https://trypost.it" target="_blank">
                <img src="{{ asset('/images/emails/logo-header.png') }}" width="160" alt="TryPost" style="max-width: 100%; vertical-align: middle">
              </a>
            </div>
            <table style="width: 100%" cellpadding="0" cellspacing="0" role="none">
              <tr>
                <td style="border-radius: 4px; background-color: #fffffe; padding: 48px; font-size: 16px; color: #3f3f46; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)">
                  <h1 style="margin: 0 0 24px; font-size: 24px; font-weight: 600; color: #000001">
                    {{ $title }}
                  </h1>
                  <p style="margin: 0; line-height: 24px">
                    {{ $body }}
                  </p>
                  <div style="line-height: 24px">&nbsp;</div>
                  <div style="text-align: center">
                    <a href="{{ $url }}" style="display: inline-block; text-decoration: none; padding: 16px 24px; font-size: 16px; line-height: 1; border-radius: 8px; background-color: #262626; color: #ffffff">
                      {{ __('webhooks.mail.paused_cta') }}
                    </a>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </div>
  </div>
</body>
</html>
