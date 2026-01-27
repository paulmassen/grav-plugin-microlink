<div align="center">

# Microlink Plugin for Grav CMS

![](microlink-image.jpg)

[![Buy Me A Coffee](https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png)](https://www.buymeacoffee.com/paulmassendari)
[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/paulmassendari)

Support to the Grav CMS 👇
<a href="https://opencollective.com/grav" target="_blank">
  <img src="https://opencollective.com/webpack/donate/button@2x.png?color=blue" width=250 />
</a>

</div>

## What is Microlink?

[Microlink](https://microlink.io) is a service that generates **previews of web pages**. Given a URL, it returns metadata such as the page title, description, main image (e.g. Open Graph), and more. That allows you to display rich “link cards” (title + image + excerpt) instead of plain URLs when you embed or reference external links in your content.

This plugin adds **server-side caching** for the [Microlink API](https://microlink.io) in Grav CMS. It exposes a local endpoint (`/microlink-cache`) that your frontend can call instead of hitting the Microlink API directly, storing responses in `user/data/microlink/`. This reduces API usage (Microlink’s free tier is about 50 requests/day) and speeds up link previews for returning visitors.

## Features

- **Server-side cache** – Microlink responses are stored in `user/data/microlink/`
- **Configurable TTL** – Cache duration in days (default: 7)
- **Transparent proxy** – Same JSON format as the Microlink API; your existing preview JS can target this endpoint instead
- **URL normalization** – Strips Grav-specific query params (`classes`, `target`, `rel`) before caching
- **No API key** – Uses the public Microlink API; the plugin only adds caching in front of it

## Installation

### Via GPM

1. In the Admin panel, go to **Plugins** and install **Microlink**.
2. Or from the CLI: `bin/gpm install microlink`

### Manual

1. Download or clone this repository.
2. Put the plugin folder under `user/plugins/` and name it `microlink`.

You should end up with:

```
your/site/grav/user/plugins/microlink/
```

## Configuration

Default config and options:

```yaml
enabled: true           # Enable or disable the plugin
cache_ttl_days: 7       # Number of days before a cached response is considered stale (1–365)
```

- **enabled** – Turns the plugin on or off.
- **cache_ttl_days** – How long each cached response is used. After that, the next request will call the Microlink API again and update the cache.

You can edit this under **Plugins → Microlink** in the Admin panel.

## Usage

### Link previews in Markdown

With the default options (**built-in CSS** and **built-in JS** enabled), you only need to tag the links that should show a preview. In your Markdown, add `classes=link-preview` (and optionally `target=_blank`) to the URL:

```markdown
[Read this article](https://example.com/article?classes=link-preview&target=_blank)
```

The plugin loads its own CSS and JavaScript. Any link with the class `link-preview` or `microlink-preview` inside a `.markdown-body` container gets a preview card automatically — no theme changes required. The first time a link is seen, the plugin fetches metadata from Microlink, stores it in `user/data/microlink/`, and displays the card; later visits use the cache.

### When the theme must be adapted

You only need to touch your theme if:

- **Previews don’t show** and your content is inside `.markdown-body`, or
- **The site is in a subdirectory** (e.g. `https://example.com/blog/`).

In that case, make sure the cache URL is passed to the script by adding this on the `<body>` in your layout (e.g. `base.html.twig`):

```twig
<body data-microlink-cache="{{ microlink_cache_url|default((base_url_relative|rtrim('/')) ~ '/microlink-cache') }}">
```

Themes that support the plugin (e.g. [Apple Notes](https://github.com/paulmassen/grav-theme-applenotes)) already set this.

### Custom script or no built-in assets

If you turn off **built-in JS** and use your own script, call the local cache instead of the Microlink API:

```
GET /microlink-cache?url=<url>
```

The plugin returns the same JSON as `https://api.microlink.io`. Use the `data-microlink-cache` attribute on `<body>` as the base URL, or `/microlink-cache` when the site is at the root.

### Cache storage

- **Directory:** `user/data/microlink/`
- **Files:** one JSON per URL, named `{md5(normalized_url)}.json`
- The folder is created on first use; ensure `user/data/` is writable.

## Troubleshooting

- **No previews / 400 or 502**
  - Check that the requested `url` is a valid `http://` or `https://` URL.
  - Confirm the plugin is enabled (Plugins → Microlink).
  - For 502: Microlink may be rate-limiting or the target site may block their crawler.

- **Cache not updating**
  - Increase or decrease `cache_ttl_days` as needed.
  - To force a refresh for one URL, delete the corresponding file in `user/data/microlink/` (filename is `md5(normalized_url).json`).

- **Permission errors**
  - Ensure `user/data/` (and thus `user/data/microlink/`) is writable by the PHP process.

## Requirements

- Grav CMS 1.7+
- PHP 7.3+
- Writable `user/data/` (or `user/data/microlink/` created with correct permissions)

## License

MIT License – see the [LICENSE](LICENSE) file for details.
