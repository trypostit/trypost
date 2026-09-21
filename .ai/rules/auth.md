---
paths:
  - app/Http/Controllers/Auth/GoogleBusinessController.php
---

# Auth

## Clear google_business_oauth on every terminal connect path
google_business_oauth holds access and refresh tokens until the user picks a location. Forget it on every terminal select() exit, including the generic Exception catch, and in ConnectPopupException::render() alongside social_connect_workspace. Leaving it after uploadFromUrl / fetchLocationPhoto / connectIdentity failures leaks tokens into the session. On reconnect, keep the existing refresh_token when Google omits a new one.
