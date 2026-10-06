=== Magnus Chat ===
Tags: chat, chatbot, ai, assistant, customer service
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Puts a Magnus agent on your site: a chat window that answers your visitors. The API key stays on your server.

== Description ==

Magnus Chat connects your WordPress site to an agent you configured in [Magnus](https://iamagnus.com). Visitors write in a chat window; the agent answers with what you set it up to know and to do.

* **The key stays on your server.** The browser talks only to your site, and your site adds the key when it calls Magnus. Visitors never see it.
* **A button in a corner, or the chat inside a page.** Turn on the corner button for every page, or put `[magnus_chat]` where you want the chat.
* **Each visitor keeps their conversation.** A random code stored in the browser continues the same conversation for up to 30 idle minutes. "New conversation" starts over.
* **Limits against abuse.** Messages up to 2,000 characters; 8 per minute and 60 per hour per visitor, and 100 per hour for the whole site; only pages of your own site may use the chat; commands meant for operators are refused before they reach Magnus.
* **In your visitors' language.** Comes in English and in Spanish for every Spanish locale; the texts of the window can be changed in the settings.

= What you need =

A Magnus account and a System API key created in the Magnus dashboard for the agent that should answer on your site.

= External service =

This plugin sends what visitors write in the chat to Magnus, the service that runs the agent, at the address set in Settings → Magnus Chat (by default `https://app.iamagnus.com`). Each message goes with a pseudonymous code derived from a random value in the visitor's browser; the visitor's name, email address and IP address are not sent. Magnus keeps the conversation to continue it.

* Service: [iamagnus.com](https://iamagnus.com)
* Terms of use: [core.iamagnus.com/terminos](https://core.iamagnus.com/terminos)
* Privacy policy: [core.iamagnus.com/privacidad](https://core.iamagnus.com/privacidad)

The plugin adds a suggested paragraph to your site's privacy policy (Settings → Privacy).

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload Plugin, and activate it.
2. In the Magnus dashboard, create a System API key for the agent that should answer on your site.
3. In Settings → Magnus Chat, paste the key, save, and press "Test the connection".
4. Turn on the corner button, or add `[magnus_chat]` to a page.

== Frequently Asked Questions ==

= Where do I get the API key? =

In the Magnus dashboard, under API keys. Create it for the agent that should answer on your site: a key always answers as that agent.

= How many messages can my site handle? =

Magnus limits each key: 120 messages per hour by default. A busy site may need a higher limit; ask Magnus. When the limit is reached, visitors are asked to try again later, and the settings page says why.

= Can visitors see the key? =

No. It is stored in your WordPress database and used only by your server. The settings page never prints it back.

= Does it work with page caching? =

Yes. The chat does not depend on per-visitor data in the page, so a cached page works.

= Can I change the limits? =

Yes, with filters: `iamagnus_chat_rate_limits` (per visitor) and `iamagnus_chat_site_limits` (for the whole site), both as seconds => messages; `iamagnus_chat_max_message_length`; `iamagnus_chat_client_ip` (behind a proxy or CDN, return the visitor's real IP); and `iamagnus_chat_allowed_origins` (other addresses of your own site). `iamagnus_chat_show_floating` decides, page by page, whether the corner button shows. If you raise the site limit, ask Magnus to raise the key's limit too.

== Changelog ==

= 0.1.0 =
* First version: settings page with a connection test, corner button and `[magnus_chat]` shortcode, server-side proxy to Magnus `/v1`, limits per visitor and per site, retry without running a turn twice, English and Spanish.
