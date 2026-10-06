# Magnus Chat for WordPress

**English** · [Español](README.es.md)

A WordPress plugin that puts a [Magnus](https://iamagnus.com) agent on a site: a
chat window that answers visitors. The site owner pastes a System API key; the
key stays on the server and visitors never see it.

```
visitor's browser ──POST /wp-json/iamagnus-chat/v1/message──▶ WordPress ──POST /v1/chat/completions──▶ Magnus
                   ◀──────────────── { reply } ───────────────            (adds the key)
```

- **Corner button or inside a page.** A button on every page, or the shortcode
  `[magnus_chat]` where the chat should be. Where the shortcode is, the corner
  button is not shown.
- **One conversation per browser.** The browser keeps a random id; the plugin
  sends Magnus a keyed hash of it as `user`, which is how Magnus threads a
  conversation (30 idle minutes). "New conversation" starts over.
- **Limits before Magnus.** Up to 2,000 characters; 8 messages per minute and 60
  per hour per visitor (an IPv6 visitor is its /64), and 100 per hour for the
  whole site, below the key's own 120. Only pages of the site itself may call the
  route. Magnus's reserved inputs (`/bot`, `/behavior`, `### Task:`, a lone
  `reset`) are refused even behind Unicode spaces. Every turn carries an
  `Idempotency-Key`, and "Retry" resends the same turn, so Magnus replays it
  instead of running it twice.
- **The key goes only where it was saved for.** No redirects are followed (they
  would carry the key along), internal addresses are refused, and changing the
  Magnus address deletes the saved key unless a new one comes with it.
- **Failures are told to the right person.** A visitor reads "I can't answer
  right now"; the owner sees the cause in Settings → Magnus Chat and can test
  the connection there.
- **English and Spanish** (neutral Spanish for every Spanish locale). The texts of
  the window can be changed in the settings.

Requires WordPress 6.2+ and PHP 7.4+. No dependencies: it calls Magnus with the
WordPress HTTP API, so it honours the host's proxy and certificate settings.

## Install

1. Build the zip (`bin/build-zip.sh`) or take it from a release, and upload it in
   Plugins → Add New → Upload Plugin.
2. In the Magnus dashboard, create a System API key for the agent that should
   answer on the site.
3. Settings → Magnus Chat: paste the key, save, press **Test the connection**.

## Try it locally

[WordPress Playground](https://wordpress.github.io/wordpress-playground/) runs
WordPress in Node, with no PHP or Docker on the machine:

```bash
npx @wp-playground/cli@3 start --path=.
```

It opens a WordPress with this plugin active and you logged in as admin.

## Develop

```bash
bin/test.sh          # the window in jsdom, and the plugin in WordPress on PHP 8.3 and 7.4
bin/test.sh live     # against the real Magnus with a made-up key: the path and the refusals
MAGNUS_API_KEY=... bin/test.sh live   # one real turn (spends tokens)
bin/build-zip.sh     # dist/iamagnus-chat-<version>.zip, and the tests against that copy
bin/i18n.py          # regenerate the .pot, check the Spanish .po, compile the .mo files
```

The PHP tests run inside a real WordPress (Playground) and answer every request
to Magnus from a `pre_http_request` filter, which also records what the plugin
sent. Any PHP warning raised from the plugin fails the run.

The wire format the plugin relies on is Magnus's `/v1` contract, the same one the
[SDKs](https://github.com/ABZ-LABS/magnus-python-sdk/blob/main/CONTRACT.md)
follow.

## Release

1. Set the version in three places: the `Version:` header and
   `IAMAGNUS_CHAT_VERSION` in `iamagnus-chat.php`, and `Stable tag:` in
   `readme.txt`. `bin/build-zip.sh` refuses to build when they differ.
2. `bin/test.sh && bin/build-zip.sh`.
3. Tag the commit (`v0.1.0`) and attach the zip to the release.

For the WordPress.org directory, add a `Contributors:` line with the
wordpress.org usernames to `readme.txt` before submitting.

## License

GPL-2.0-or-later, as WordPress plugins are. See [LICENSE](LICENSE).
