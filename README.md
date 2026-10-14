# Discord Webhooks for Pelican Panel

Adds the **Discord** webhook type back to [Pelican Panel](https://pelican.dev). Pelican moved Discord support out of its core webhook system into plugins; this plugin restores it with a message and embed editor, a live preview, and delivery that Discord accepts.

> Maintained fork of [JoanFo1456/discord-webhooks](https://github.com/JoanFo1456/discord-webhooks) by JoanFo. Version 1.1.0 fixes several delivery bugs and hardens the plugin; see [CHANGELOG.md](CHANGELOG.md).

## Features

- **Discord type in the webhook form** for global (admin) and server webhooks, picked automatically when the endpoint is a Discord webhook URL.
- **Message editor**: content, username, avatar, forum thread name, up to 10 embeds with author, title, description, colour, images, footer, timestamp and up to 25 fields.
- **Live preview** that shows exactly what will be delivered, with sample event data filled in.
- **`{{variables}}`** from the event in any text or URL field.
- **Safe delivery**: values are inserted as text, never as JSON, so quotes, newlines or crafted values can't break or rewrite the message. Output is trimmed to Discord's limits and rate limits (HTTP 429) are retried.
- **No surprise pings**: mentions are off unless you allow them.

## Requirements

- Pelican Panel **1.0.0-rc.1 or newer** (the first release with the webhook type API). Canary builds are always treated as compatible.
- A running queue worker. Webhooks are sent from the queue, like everything else in Pelican.

## Installation

1. Download `discord-webhooks.zip` from the [latest release](https://github.com/MaxGojko/pelican-discord-webhooks-plugin/releases/latest).
2. In the admin area open **Plugins**, choose **Import from File** (or **Import from URL** with the release asset link), then install the plugin.
3. Restart the queue worker so it loads the plugin: `php artisan queue:restart`.

From the command line instead: unpack the zip into the panel's `plugins/` directory (so you get `plugins/discord-webhooks/plugin.json`), then run `php artisan p:plugin:install discord-webhooks` and `php artisan queue:restart`.

Installing claims existing webhooks whose endpoint is a Discord webhook URL (`https://discord.com/api/webhooks/…`, also `discordapp.com`, `canary.` and `ptb.`) and that are still on the built-in type. **Open each claimed webhook once and give it a message or an embed**, because a webhook without either sends a short default message (`Pelican webhook event: …`).

### Updating

From 1.1.0 on, the panel finds updates by itself through `update.json`; use the update button on the Plugins page or `php artisan p:plugin:update discord-webhooks`.

**Coming from 1.0.0** (it had no update URL), update once by hand:

```bash
php artisan p:plugin:uninstall discord-webhooks --delete
# then import and install the new zip as above
php artisan queue:restart
```

Your webhooks and their messages are kept: they stay on the Discord type and keep delivering their saved payload while the plugin is missing. Read the [behaviour changes in 1.1.0](CHANGELOG.md#110) before updating, especially the one about mentions.

## Usage

1. Create a webhook under **Webhooks** (admin) or a server's **Webhooks** page.
2. Paste the Discord webhook URL as the **Endpoint**; the type switches to **Discord**.
3. Fill in **Content**, one or more **Embeds**, or both. At least one of them is required.
4. Pick the events, save, and use the test action to send a sample event.

### Variables

Write `{{path}}` anywhere in a text or URL field; it is replaced with the event's data when the webhook fires. Dot paths reach into nested data.

| Scope | Examples |
| --- | --- |
| Every event | `{{event}}` (event name) |
| Global webhooks, server events | `{{name}}`, `{{uuid}}`, `{{description}}`, `{{memory}}`, `{{allocation.ip}}`, `{{allocation.port}}` |
| Server webhooks | `{{server.name}}`, `{{server.uuid}}`, `{{server.node}}`, `{{user.username}}`, `{{user.email}}` |

The preview fills variables with the panel's sample data, which shows which paths exist. An unknown path is left in the message as its own name, the same as in the panel's other webhook types. Lists and objects are inserted as JSON, `true`/`false` as words, and empty values as nothing.

### Mentions

`@everyone`, role and user mentions only ping when you tick them under **Allowed Mentions**. With nothing ticked, the text still shows but nobody is notified. This matters when variables such as server names or descriptions come from users.

### What Discord accepts, and what the plugin does about it

Discord rejects messages that break its rules with HTTP 400. The plugin checks what it can when you save and repairs the rest just before sending, because variables are only known then.

| Rule | When you save | When it sends |
| --- | --- | --- |
| Length limits (content 2000, username 80, title 256, description 4096, field name 256 and value 1024, footer 2048, 6000 characters across all embeds) | rejected | text that grew through variables is shortened and ends in `…` |
| Username must not contain `discord` or `clyde` | rejected | a username that becomes one through a variable is dropped, and Discord uses the webhook's own name |
| URLs must be `http(s)` | rejected (`{{variables}}` allowed) | invalid URLs are dropped |
| Fields need a name and a value; footers need text; authors need a name | rejected | incomplete parts are dropped |
| A message needs content or an embed | rejected | a default `Pelican webhook event: <event>` message is sent |

A few options are easy to misread:

- **Forum Thread Name** only works when the webhook posts into a forum or media channel. Discord rejects it for normal text channels.
- **Suppress Embeds** hides link previews only. It is ignored when the message has embeds of its own.
- **Suppress Notifications** posts silently.

### API

Webhooks of type `discord` can be created and updated through the application API with a ready-made `payload` in Discord's [execute webhook](https://discord.com/developers/docs/resources/webhook#execute-webhook) format. Accepted keys: `content`, `username`, `avatar_url`, `tts`, `embeds`, `allowed_mentions`, `flags`, `thread_name`, `applied_tags`; inside embeds `title`, `description`, `url`, `timestamp`, `color` (integer), `footer`, `image`, `thumbnail`, `author`, `fields`. Other keys are dropped when the webhook fires. Values the editor has no field for, such as mention id lists or a fixed embed timestamp, are kept when the webhook is edited in the panel. A `PATCH` without `payload` leaves the message alone.

## Troubleshooting

**No message arrives.** Check the webhook's delivery history in the panel, then `storage/logs/laravel-*.log`. Failed deliveries are logged as `Webhook #<id> delivery to discord.com failed with status <code>` (only the host is logged, never the token).

| Status | Usual cause |
| --- | --- |
| 400 | A setting Discord rejects. Most often a forum thread name on a normal channel, or a webhook claimed on install that still has no message. |
| 401 / 404 | The webhook was deleted in Discord or the URL is incomplete. |
| 429 | Rate limited. Retried automatically after the time Discord asks for. |

Nothing at all in the history usually means the queue worker isn't running or still runs old code; restart it with `php artisan queue:restart`.

To rule out the URL, post to it directly from the panel host:

```bash
curl -H "Content-Type: application/json" -d '{"content":"test"}' "https://discord.com/api/webhooks/<id>/<token>"
```

## Development

The rules the plugin applies live in framework-free classes under `src/Support/` and are covered by a small test runner that needs only PHP:

```bash
php tests/run.php            # tests
.github/scripts/check.sh     # php -l on every PHP file, then the tests
```

| Path | Purpose |
| --- | --- |
| `src/Schemas/DiscordSchema.php` | The webhook type: form, save/fill, API rules, delivery hook |
| `src/Support/Discord.php` | Discord limits, flags, mention types, allowed keys |
| `src/Support/DiscordPayload.php` | Form state ⇄ stored payload |
| `src/Support/PayloadRenderer.php` | Variables and clean-up before sending |
| `src/Support/DiscordEndpoint.php` | Detects Discord webhook URLs |
| `src/Livewire/DiscordPreview.php` | Live preview |
| `database/migrations/` | Claims Discord webhooks on install, removes the 1.0.0 stylesheet copy |

CI runs the checks on every push and pull request. Publishing a GitHub release tagged `vX.Y.Z` runs the checks on the tag, builds `discord-webhooks.zip` from the tag, attaches it and its SHA-256 to the release, and updates `plugin.json` and `update.json` on `main` so panels are offered the update. Pre-releases get a zip but aren't offered as updates.

## Credits and license

Originally written by [JoanFo](https://github.com/JoanFo1456), who also moved Discord webhooks out of Pelican's core. Maintained by [MaxGojko](https://github.com/MaxGojko).

Licensed under the [GNU GPL v3.0](LICENSE).
