---
paths:
  - 'resources/js/components/posts/editor/**'
---

# Editor

## Per-network composer settings are label/control rows; content type lives outside them
Every *Settings.vue renders only a SettingsSection of SettingsRow (130px label column, control on the right) and never its own collapsible card, header or "posting to" block (TikTok keeps a Posting-to row: its UX guidelines require the creator name). The content-type picker is ContentTypeRadioGroup, mounted by the caller (ComposerNetworkCard / ChannelConfigurator) from getContentTypeOptions(); media warnings go through ChannelMediaWarnings. Do not re-add variant pickers inside a settings component.

## The AI-generated row is shared
The AI-generated disclosure row is the shared AiGeneratedRow, rendered last by ComposerNetworkSettings for every network that supports it. Do not add it inside a per-network settings component.
