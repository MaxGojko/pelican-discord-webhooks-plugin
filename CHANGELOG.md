# Changelog

All notable changes to this plugin. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [semantic versioning](https://semver.org/).

## [1.1.1] - 2026-10-14

### Fixed

- `plugin.json` now declares `"api_version": 1`, so the panel's plugin list no longer warns that it is missing. CI rejects a `plugin.json` without it.

## [1.1.0] - 2026-10-14

### Behaviour changes

Read these before updating from 1.0.0.

- **Mentions no longer ping unless allowed.** 1.0.0 sent no `allowed_mentions` when no box was ticked, so Discord pinged every `@everyone`, role and user mention in the text. Now nothing pings unless it is ticked under *Allowed Mentions*. Tick the boxes again on webhooks that are meant to ping.
- **Usernames containing `discord` or `clyde`** are rejected when saving (form and API), instead of failing at Discord.
- **A webhook without content or embeds** now sends `Pelican webhook event: <event>` instead of an empty request that Discord rejected.
- **Only Discord's documented keys are sent.** Unknown keys in API payloads, including the unused top-level `image`/`thumbnail` keys 1.0.0 stored, are dropped when the webhook fires.
- **Discord endpoints are detected by host and path** (`discord.com`, `discordapp.com`, their `canary.`/`ptb.` hosts, `/api/webhooks/…`). URLs that merely contain `discord.com`, such as a relay, are no longer treated as Discord.
- Requires Pelican Panel 1.0.0-rc.1 or newer (`panel_version` is now set).

### Fixed

- Event values containing quotes, backslashes or newlines (e.g. a server named `My "Cool" Server`) produced invalid JSON, an empty payload and HTTP 400. Variables are now substituted inside each value instead of in the JSON text.
- Invalid UTF-8 in event data no longer breaks delivery.
- Text that grew past Discord's limits through variables caused HTTP 400; it is now shortened, including the 6000-character total across embeds.
- Invalid URLs, fields without name or value, footers without text and authors without name no longer cause HTTP 400; they are dropped.
- Embed colours were reloaded without zero padding (`#ff00` for green, `#0` for none), corrupting the colour picker; black could not be saved.
- Embed text of `"0"` was treated as empty and dropped.
- Renaming a Discord webhook through the API (`PATCH` without `payload`) erased its message.
- Editing an API-created webhook in the panel erased values the editor has no field for (mention id lists, `replied_user`, `tts`, `applied_tags`, fixed embed timestamps).
- The preview now shows exactly what will be delivered, including truncation, dropped parts and the default message.
- The flags checkboxes did not restore their state reliably.

### Security

- Event values could inject JSON keys into the message, e.g. a server name that rewrites the content to `@everyone`.
- The preview's avatar URL was not checked like the other URLs, and embed colours were written into a `style` attribute unchecked (CSS injection). Preview images are now loaded with `referrerpolicy="no-referrer"`.
- The release workflow interpolated the release tag into shell scripts (script injection). Tags are now validated and passed through environment variables, and actions are pinned to commit SHAs.

### Changed

- The preview stylesheet is served inline. 1.0.0 copied it into the panel's `public/` directory on every request, which failed on read-only installs; a migration removes the old copy.
- Form fields enforce Discord's limits, validate URLs (while allowing `{{variables}}`) and validate colours. Content is a multi-line field.
- Clearer help texts for *Forum Thread Name* and *Suppress Embeds*.
- Discord's rules (limits, flags, mention types, allowed keys, colour parsing) live in one place, `src/Support/Discord.php`, instead of three copies with different rules.

### Added

- `update_url`, so panels are offered updates. Release zips are built from the release tag, carry a SHA-256 checksum the panel verifies, and pre-releases are never offered as updates.
- Framework-free tests for the payload logic (`php tests/run.php`) and CI that runs them on every push, pull request and release.
- README with installation, usage, variables, delivery rules and troubleshooting.

## [1.0.0] - 2026-08-23

- Initial release by JoanFo: Discord webhook type with message and embed editor, live preview and a migration that claims existing Discord webhooks.

[1.1.1]: https://github.com/MaxGojko/pelican-discord-webhooks-plugin/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/MaxGojko/pelican-discord-webhooks-plugin/compare/4b47a62...v1.1.0
[1.0.0]: https://github.com/JoanFo1456/discord-webhooks/releases/tag/v1.0.0
