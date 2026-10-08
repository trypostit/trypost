<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Enums\User\Locale::tryFrom(app()->getLocale())?->direction() ?? 'ltr' }}" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
  <meta charset="utf-8">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings xmlns:o="urn:schemas-microsoft-com:office:office">
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <style>
    td,th,div,p,a,h1,h2,h3,h4,h5,h6 {font-family: "Segoe UI", sans-serif; mso-line-height-rule: exactly;}
  </style>
  <![endif]-->
  @if(isset($title))
  <title>{{ $title }}</title>
  @endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Outfit:wght@600&display=swap" rel="stylesheet" media="screen">
  <style>
    @media (max-width: 600px) {
      .sm-p-4 {
        padding: 16px !important
      }
      .sm-p-6 {
        padding: 24px !important
      }
    }
  </style>
</head>
<body style="margin: 0; width: 100%; background-color: #f7f6f3; padding: 0; -webkit-font-smoothing: antialiased; word-break: break-word">
  @if(isset($previewText))
  <div style="display: none">
    {{ $previewText }}
    &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847;
  </div>
  @endif
  <div role="article" aria-roledescription="email" aria-label="{{ $title }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <table role="presentation" style="width: 100%; background-color: #f7f6f3; font-family: Inter, Arial, Helvetica, sans-serif" cellpadding="0" cellspacing="0">
      <tr>
        <td align="center" style="padding-left: 16px; padding-right: 16px">
          <!--[if mso]>
      <table role="presentation" width="552" align="center"><tr><td>
      <![endif]-->
          <table role="presentation" align="center" style="width: 100%; max-width: 552px" cellpadding="0" cellspacing="0">
            <tr>
              <td style="padding-top: 32px; padding-bottom: 32px">
                <div style="text-align: center">
                  <a href="https://trypost.it" target="_blank">
                    <img src="{{ asset('/images/emails/logo-header.png') }}" width="150" height="auto" alt="TryPost" style="max-width: 100%; vertical-align: middle; width: 150px; height: auto">
                  </a>
                </div>
              </td>
            </tr>
            <tr>
              <td align="{{ \App\Enums\User\Locale::tryFrom(app()->getLocale())?->direction() === 'rtl' ? 'right' : 'left' }}" class="sm-p-6" style="border-radius: 12px; border: 1px solid #eae8e5; background-color: #ffffff; padding: 32px; font-size: 16px; line-height: 24px; color: #292928">
                <h1 style="margin: 0 0 24px; font-family: Outfit, Inter, Arial, Helvetica, sans-serif; font-size: 24px; font-weight: 600; line-height: 32px; color: #292928"> {{ __('mail.post_publish_failed.heading') }}</h1>
                <p style="margin: 0; line-height: 24px">
                  {{ __('mail.post_publish_failed.body', ['workspace' => $workspaceName]) }}
                </p>
                @if($publication)
                <table role="presentation" style="margin-top: 24px; width: 100%" cellpadding="0" cellspacing="0">
                  <tr>
                    <td class="sm-p-4" style="border-radius: 12px; border: 1px solid #eae8e5; padding: 20px">@php
                      $emailChannelPlatform = $publication['platform'];
                      $emailChannelName = $publication['accountName'];
                      @endphp
                      <table role="presentation" style="width: 100%" cellpadding="0" cellspacing="0">
                        <tr>
                          <td style="width: 44px; vertical-align: middle">
                            <img src="{{ asset('images/accounts/'.$emailChannelPlatform->network().'.png') }}" width="32" height="32" alt style="max-width: 100%; vertical-align: middle; display: block; border-radius: 8px">
                          </td>
                          <td style="vertical-align: middle">
                            <p style="margin: 0; font-size: 16px; font-weight: 600; line-height: 20px; color: #292928">{{ $emailChannelName }}</p>
                            @if($emailChannelName !== $emailChannelPlatform->label())
                            <p style="margin: 2px 0 0; font-size: 13px; line-height: 18px; color: #5a5a59">{{ $emailChannelPlatform->label() }}</p>
                            @endif
                          </td>
                        </tr>
                      </table> @if($postPreview['text'] !== '')
                      <p style="white-space: pre-line; margin: 20px 0 0; line-height: 24px; color: #292928">{{ $postPreview['text'] }}</p>
                      @endif
                      @if($postPreview['image'])
                      <img src="{{ $postPreview['image']['url'] }}" alt="{{ $postPreview['image']['alt'] }}" width="{{ $postPreview['image']['width'] }}" style="vertical-align: middle; margin-top: 20px; height: auto; max-width: 100%; border-radius: 8px" height="auto">
                      @elseif($postPreview['text'] === '')
                      <p style="margin: 20px 0 0; font-size: 14px; color: #5a5a59">{{ $postPreview['attachment'] ?: __('mail.post_preview.no_text') }}</p>
                      @endif
                    </td>
                  </tr>
                </table>
                @if($publication['error'])<table role="presentation" style="margin-top: 16px; width: 100%" cellpadding="0" cellspacing="0">
                  <tr>
                    <td class="sm-p-4" style="border-radius: 12px; border: 1px solid #eae8e5; background-color: #f7f6f3; padding: 20px">
                      <p style="margin: 0; font-size: 14px; font-weight: 600; line-height: 20px; color: #7f0f00">{{ __('mail.post_preview.error') }}</p>
                      <p style="margin: 6px 0 0; font-size: 14px; line-height: 22px; color: #292928">
                        {{ $publication['error'] }}
                      </p>
                    </td>
                  </tr>
                </table>
                @endif
                @endif <div role="separator" style="line-height: 24px">&zwj;</div>
                <div style="text-align: center">
                  <a href="{{ $url }}" style="display: inline-block; text-decoration: none; font-weight: 600; border-radius: 8px; border: 1px solid #ddd6fe; background-color: #ddd6fe; padding: 12px 24px; text-align: center; font-size: 16px; line-height: 24px; color: #292928">
                    <!--[if mso]><i style="mso-font-width: 150%; mso-text-raise: 31px" hidden>&emsp;</i><![endif]-->
                    <span style="mso-text-raise: 16px">{{ __('mail.post_publish_failed.button') }}</span>
                    <!--[if mso]><i hidden style="mso-font-width: 150%">&emsp;&#8203;</i><![endif]-->
                  </a>
                </div>
              </td>
            </tr>
            <tr>
              <td align="center" style="padding: 24px; text-align: center; font-size: 12px; line-height: 20px; color: #5a5a59">
                <p style="margin: 0 0 8px">
                  {!! str_replace(':brand', '<a href="https://trypost.it" target="_blank" style="color: inherit; font-weight: 600; text-decoration: none;">TryPost</a>', e(__('mail.layout.tagline'))) !!}
                </p>
                <p style="margin: 8px 0 0">
                  <a href="{{ route('app.notifications.preferences') }}" target="_blank" style="color: #5a5a59; text-decoration: underline">
                    {{ __('mail.layout.manage_notifications') }}
                  </a>
                </p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-left: auto; margin-right: auto; margin-top: 12px">
                  <tr>
                    <td style="padding-left: 4px; padding-right: 4px">
                      <a href="https://github.com/trypostit/trypost" target="_blank">
                        <img src="{{ asset('/images/emails/social/github.png') }}" width="20" height="20" alt="GitHub" style="max-width: 100%; vertical-align: middle">
                      </a>
                    </td>
                    <td style="padding-left: 4px; padding-right: 4px">
                      <a href="https://x.com/trypostit" target="_blank">
                        <img src="{{ asset('/images/emails/social/x.png') }}" width="20" height="20" alt="X" style="max-width: 100%; vertical-align: middle">
                      </a>
                    </td>
                    <td style="padding-left: 4px; padding-right: 4px">
                      <a href="https://www.youtube.com/@trypostit" target="_blank">
                        <img src="{{ asset('/images/emails/social/youtube.png') }}" width="20" height="20" alt="YouTube" style="max-width: 100%; vertical-align: middle">
                      </a>
                    </td>
                    <td style="padding-left: 4px; padding-right: 4px">
                      <a href="https://trypost.it/discord" target="_blank">
                        <img src="{{ asset('/images/emails/social/discord.png') }}" width="20" height="20" alt="Discord" style="max-width: 100%; vertical-align: middle">
                      </a>
                    </td>
                    <td style="padding-left: 4px; padding-right: 4px">
                      <a href="https://www.instagram.com/trypost.it" target="_blank">
                        <img src="{{ asset('/images/emails/social/instagram.png') }}" width="20" height="20" alt="Instagram" style="max-width: 100%; vertical-align: middle">
                      </a>
                    </td>
                  </tr>
                </table>
                <p style="margin: 12px 0 0">
                  &copy; {{ date('Y') }} TryPost.it
                </p>
              </td>
            </tr>
          </table>
          <!--[if mso]>
      </td></tr></table>
      <![endif]-->
        </td>
      </tr>
    </table>
  </div>
</body>
</html>