You are analyzing the homepage of a company to extract brand metadata for their social media marketing profile.

From the provided markdown content of the homepage, produce:

1. **name** — the actual brand or company name. 1-4 words, no tagline, no slogan, no product descriptor.
   - "Sendkit — Email API for Developers" → `Sendkit`
   - "Acme Coffee | Premium beans shipped worldwide" → `Acme Coffee`
   - "Email API, SMTP & Marketing Platform for Developers" (no brand visible in title; check the logo alt, header, footer copyright, or product mentions in the body) → infer from those signals
   - If the page truly does not name the brand anywhere, return the most likely candidate from the homepage content (a header h1, a logo alt, a "© 2025 X" footer). Do not invent a name from the URL or from the description.

2. **description** — a concise 2-3 sentence brand description explaining what the company does, who they serve, and what makes them unique. Write it in the detected content language. Avoid marketing fluff; be specific.

3. **language** — detect the primary content language of the site and return its code. Pick exactly one of: {{ $content_languages }}. If the site is in a language not in that list, pick the closest supported match (prefer `en`).

4. **voice_traits** — infer the brand's voice as a set of traits, returned as an array of values chosen ONLY from the allowed list below. For each single-select dimension pick AT MOST ONE value (or none if unclear); for the `style` group pick any number that fit. Aim for a coherent set of roughly 5-9 traits.
@foreach($voice_groups as $group => $values)
   - **{{ $group }}**{{ in_array($group, $single_select_groups, true) ? ' (pick at most one)' : ' (pick any that fit)' }}: {{ implode(', ', $values) }}
@endforeach
   Only return values from the lists above — never invent new ones.

5. **brand_color** — the primary brand color as a hex string starting with `#`, lowercase, 6 digits (e.g. `#0ea5e9`). This is the accent color most prominently used in CTAs, primary buttons, links, or the logo. Return an empty string if you can't confidently identify it.

6. **background_color** — the dominant page background color as a hex string starting with `#`, lowercase, 6 digits (e.g. `#ffffff` for light themes, `#0b0f19` for dark themes). Return an empty string if you can't confidently identify it.

7. **text_color** — the dominant body text color as a hex string starting with `#`, lowercase, 6 digits (e.g. `#0f172a`). Return an empty string if you can't confidently identify it.

Be accurate and specific to what the page actually shows. Do not invent features or claims that aren't on the page. For colors, prefer values visible in the markup/CSS; never guess.
