---
name: in-app-copy
description: "Use this skill to write, rewrite or review any text the end user reads inside the TryPost app: lang keys in lang/*/*.php, labels, buttons, tabs, menu items, tooltips, popovers that ask for an action, empty states, dialogs and confirmations, errors, validation messages, toasts, settings descriptions and onboarding steps. Trigger on requests like 'improve this text', 'rewrite this popover', 'this copy is bad', 'review the product copy', 'translate this key', or any new UI string. Covers the three-step pattern for action prompts, tone, per-component rules, the 16-locale workflow and a review checklist. Not for marketing pages, emails' visual layout or AI prompts (see copywriting, the Maizzle rules and the AI agents rules)."
metadata:
  author: trypost
---

# In-app copy

Text inside TryPost talks to someone who is in the middle of doing something: scheduling a post, connecting a channel, fixing an error. Every string exists to help them finish that task. Write from their side of the screen, never from how the system is built.

This guide is the reference for writing new copy and for reviewing existing copy. The short version lives in `AGENTS.md` ("Writing in-app copy"); this skill holds the detail, the examples and the sources.

## The core pattern: a prompt that asks the user to act

When the UI stops the user and asks them to do something first (a popover, an empty state with a button, a blocking dialog, a tooltip on a disabled control), write it in three steps, in this order (owner decision, October 2026):

1. **What is missing, in the user's words, addressed to them.** State the situation as it affects them.
2. **Why it matters for what they just tried.** Tie it to the click that opened the prompt, not to how the feature works in general.
3. **The next action, named by its button.** The body leads to the button; the button says exactly what happens. Always keep a way out.

### Reference example: the queue setup popover

Shown by `resources/js/components/publish/AddToQueueButton.vue` when someone clicks "Add to queue" on a channel without posting times (`posts.publish.actions.queue_setup`).

Before (rejected):

> **Set up the queue first**
> This channel has no posting times yet. Add them, and every post you add to the queue takes the next free one.

Also rejected (explains the mechanism before saying what is missing):

> **When should this channel post?**
> Posts in the queue go out at this channel's posting times, and it doesn't have any yet. Choose them once, and every post you add takes the next open time.

After (approved):

> **You haven't set up this channel's queue yet**
> To add posts to the queue, set this channel's posting times. Each queued post goes out at the next open time.
> [Not now] [Set posting times]

For a member who cannot set posting times, same title, and the body names who can:

> To add posts to the queue, a workspace admin needs to set this channel's posting times.

Never show a button the user cannot use.

## Tone and voice

- **Talk to the user.** "You", "your channel", "your posts". Not "the user", not passive constructions that hide who acts.
- **Lead with the point.** The first words carry the message; someone who reads only the title should know what is going on.
- **Short.** A title and one or two sentences. A tooltip under about 80 characters. If a sentence explains how the system works before saying what to do, cut it or move it after.
- **Plain words.** Name things by what the user sees, not by the code: "posting times", not "slots"; "channel", not "social account" or "destination"; "settings", not "meta".
- **Direction, not mood.** No apologies ("Sorry"), no "please", no "Oops", no blame ("You entered an invalid…"). Say what happened and what to do.
- **Active and specific verbs.** "Save changes", not "Submit". "Set posting times", not "Configure". "Connect through Facebook", not "Continue".
- **No filler or generic warnings.** No "This action cannot be undone" line on everyday confirmations (see the delete rules in `AGENTS.md`). No "Are you sure?" as the only content.

## Per component

### Buttons, menu items, tabs, badges

- Verb + object for actions: "Add to queue", "Copy link", "Remove music". A noun alone only for navigation tabs ("Queue", "Drafts").
- **One action, one name, everywhere.** The button that says "Publish" leads to a toast or state that says "Published". The tab "Queue", the button "Add to queue" and a prompt about "the queue" use the same word.
- Single line in every locale. Add the key to `tests/fixtures/single-line-copy.php` with the length of its longest translation (see "Translated copy must fit its UI slot" in `AGENTS.md`).

### Tooltips

- Add what the label does not say: the consequence, the scope, a shortcut. If the tooltip repeats the label, drop it.
- A tooltip on a disabled control says why it is disabled and what unlocks it ("Wait for uploads to finish").
- Nothing essential lives only in a tooltip: touch screens and keyboards may never show it.

### Popovers and prompts that ask for an action

Use the three-step pattern above. One prompt, one action; a way out ("Not now", "Maybe later") next to it. Cancel first, primary action last (`AGENTS.md`, "Dialog and slide-over actions").

### Empty states

Two different empty states (see "Empty states on list pages" in `AGENTS.md`):

- **First use** (nothing exists yet): say what will live here and give the one action that creates it, for example (illustrative) "No labels yet. Labels group posts so you can filter them." + [Create label].
- **No results** (a search or filter found nothing): say that nothing matched and how to widen it. "No posts match your search. Try a different keyword or clear the search." No create button.

Never a bare "Nothing here".

### Dialogs and confirmations

- The title states the action and its object: "Delete this post?", "Discard changes?".
- The body states what will be removed or lost, and nothing more.
- The destructive button repeats the verb ("Delete", "Discard"), never "OK" or "Yes".
- Typing to confirm only for the critical actions listed in `AGENTS.md`.

### Errors and validation

- Say what happened and how to fix it, in the interface's voice: "Couldn't load music from Instagram. Try again." "Enter a valid Instagram username."
- Name the field or the limit with its value: "Add up to 3 collaborators."
- When the network rejects something, say which network and what it needs: "Music needs an Instagram account connected through Facebook."
- Never "Something went wrong" alone, never raw codes or exception text.

### Toasts and success feedback

- No success toast after creating, editing, deleting or copying everyday resources: the visible change (a new row, an inline check) is the feedback (owner decision, September 2026). Errors still toast.
- When a toast is needed, it names the result in the past tense of the action: "Added to queue" after "Add to queue".

### Settings descriptions and helper text

- The line under a settings page title stays on one line in every locale.
- Helper text under a field explains the effect of the setting, not its name.

## Localization (16 locales)

Every string ships in `lang/{ar,de,el,en,es,fr,it,ja,ko,nl,pl,pt-BR,ru,tr,uk,zh}`. `LocalizationParityTest` fails when a key is missing in one.

- **Same structure, written natively.** Keep the three steps and the meaning; do not translate word for word.
- **Reuse each locale's existing word** for the feature: queue is "fila", "Warteschlange", "file", "cola", "kolejka"; posting times is "horários de postagem", "Posting-Zeiten", "horaires de publication". Search the locale's file before choosing a term.
- **Grammatical gender.** When "you haven't…" needs a gendered form for the user (Polish, Russian past tense in some phrasings, Arabic), rephrase impersonally: Polish "Kolejka tego kanału nie jest jeszcze skonfigurowana" ("This channel's queue isn't set up yet").
- **Longest locale decides the layout.** French, German, Ukrainian, Russian, Polish and Portuguese usually run longest. Shorten the copy in that locale rather than letting a control wrap.
- **Placeholders** (`:network`, `:count`, `:username`) stay in every locale; plurals use `trans_choice` with a `[0,*]` last segment.
- Resolve UI keywords with `$t` in templates (see `.ai/rules/js.md`).

## Reviewing existing copy (checklist)

For each key under review:

1. **Find where it renders:** `grep -rn "the.key" resources/js app` and open the screen. Copy is judged in context: what did the user just do, what are they trying to do?
2. **Classify it:** button/label, tooltip, action prompt, empty state, dialog, error, toast, helper text.
3. **Check it against this guide:**
   - Does it talk to the user ("you") and lead with the point?
   - For an action prompt: what is missing, why it matters now, the next action, in that order?
   - Does it use the words the user sees on screen, and the same name as the button or tab it refers to?
   - Is there any system vocabulary, apology, blame, filler, or a mechanism explained before the action?
   - Is a member without permission shown something they cannot do?
4. **Rewrite all 16 locales together**, natively, with each locale's existing terms.
5. **Run the guards:** `php artisan test --compact --filter="LocalizationParity|SingleLineCopy"`, update the budget in `tests/fixtures/single-line-copy.php` for single-line controls, and run the browser tests that assert the text (`grep -rn "the.key" tests`).
6. **Look at it in the browser** in English and in the longest locale before marking it done.

## Sources

The three-step pattern and the rules above follow these references (researched October 2026):

- Nielsen Norman Group, [Empty States in Application Design: 3 Guidelines](https://www.nngroup.com/videos/empty-states-in-application-design-guidelines/): empty states communicate system status, teach what belongs in the space, and give a direct path to the key task; guidance shown in context beats an upfront tutorial.
- Setproduct, [Empty state UI design: turn blank screens into next steps](https://www.setproduct.com/blog/empty-state-ui-design): an empty state states the status, teaches what belongs there, and offers a direct path to the next action.
- SAP Fiori, [Designing for Empty States](https://www.sap.com/design-system/fiori-design-web/v1-96/foundations/best-practices/global-patterns/designing-for-empty-states): add a button or link for the next step where appropriate.
- UX Planet, [Empty state design: a practical guide](https://uxplanet.org/empty-state-design-a-practical-guide-94ad0adbda45) and Babich, [Empty states: 5 practical tips for designers](https://babich.biz/blog/empty-states/): say why the space is empty and what the user can do next; no dead ends.
- Flook, [Tooltips UX: 9 Design Rules](https://flook.co/blog/tooltips-ux): keep tooltips short, put the key point first, do not repeat the label, do not hide essential information in them, make them reachable by hover, focus and tap.
- River Editor, [How to write UX copy (microcopy)](https://rivereditor.com/guides/how-to-write-ux-copy-microcopy-2026): action-led, user-centred microcopy; errors explain what happened and how to fix it.
- UX writing skill references ([Tessl](https://tessl.io/registry/skills/github/Owl-Listener/designer-skills/ux-writing), [skills.sh](https://skills.sh/slb2248/ai-ux-skills/ux-writing)) and the [Harness tooltip and contextual help skill](https://openskillindex.com/skills/intense-visions-harness-engineering-ux-tooltip-contextual-help-15b751): empty states explain what will appear and give a clear call to action; onboarding introduces one concept at a time and points to a first action; tooltip length limits around 80 to 150 characters.
