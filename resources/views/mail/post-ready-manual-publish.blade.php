<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>{{ $title }}</title>
</head>
<body style="margin: 0; width: 100%; padding: 0; -webkit-font-smoothing: antialiased; word-break: break-word">
  <div style="display: none">{{ $previewText }} &#8199;&#65279;&#847;</div>
  <div role="article" aria-roledescription="email" aria-label="{{ $title }}" lang="en">
    <div style="background-color: #fafafa; font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif">
      <table align="center" cellpadding="0" cellspacing="0" role="none" style="width: 100%">
        <tr>
          <td style="padding: 24px">
            <table style="width: 100%" cellpadding="0" cellspacing="0" role="none">
              <tr>
                <td style="border-radius: 8px; background-color: #fffffe; padding: 32px; font-size: 16px; line-height: 24px; color: #3f3f46; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05)">
                  <h1 style="margin: 0 0 16px; font-size: 24px; font-weight: 600; color: #000001">{{ $title }}</h1>
                  <p style="margin: 0 0 16px">{{ $body }}</p>

                  @if(!empty($platforms))
                  <div style="margin: 16px 0; padding: 12px 16px; border-radius: 6px; background-color: #f4f4f5; font-size: 14px">
                    <strong style="color: #18181b">Publish to:</strong>
                    <span style="color: #52525b">{{ implode(', ', $platforms) }}</span>
                  </div>
                  @endif

                  @if(!empty($caption))
                  <div style="margin: 16px 0; padding: 16px; border-radius: 6px; background-color: #faf5ff; font-size: 14px; color: #3f3f46; white-space: pre-wrap">{{ $caption }}</div>
                  @endif

                  @if(!empty($media))
                  <div style="display: flex; gap: 8px; margin: 16px 0; flex-wrap: wrap">
                    @foreach($media as $item)
                    <img src="{{ $item->url }}" alt="{{ $item->altText() ?? '' }}" width="72" height="90" style="border-radius: 4px; object-fit: cover; border: 1px solid #e4e4e7" />
                    @endforeach
                  </div>
                  @endif

                  <div role="separator" style="line-height: 24px">&zwj;</div>
                  <div style="display: flex; align-items: center; justify-content: center">
                    <a href="{{ $url }}" style="display: inline-block; text-decoration: none; padding: 16px 24px; font-size: 16px; line-height: 1; border-radius: 8px; background-color: #262626; color: #ffffff">
                      View Post &rarr;
                    </a>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding: 24px; text-align: center; font-size: 12px; color: #52525b">
            <p style="margin: 0 0 8px">Open-source social media scheduling tool</p>
            @if(isset($unsubscribe_url))
            <p style="margin: 8px 0 0">
              <a href="{{ $unsubscribe_url }}" target="_blank" style="color: #52525b; text-decoration: none">Unsubscribe</a>
            </p>
            @endif
          </td>
        </tr>
      </table>
    </div>
  </div>
</body>
</html>
