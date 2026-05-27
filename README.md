# Rocket Maps theme

A demo/marketing theme for the Rocket Maps WordPress plugin.

## What you get

- **Two page layouts** — standard centered (`page.php`) and 100% full-width (`page-fullwidth.php`, picked via the *Template* dropdown in the page editor).
- **Header and footer menus** — `Header menu` and `Footer menu` nav locations, both support a dropdown on `Demo`.
- **Engine switcher strip** — drop-in 100%-width block with three buttons (Google Maps / Mapbox / MapLibre). Clicking a button sets `?rmaps_engine=<slug>` in the URL and reloads. Server reads the same param and re-renders the active map shortcode under the chosen engine, reusing the per-engine style from `map_style_history` postmeta.
- **Dark / light theme toggle** — persists to `localStorage`, defaults to the user's `prefers-color-scheme`.
- **Responsive** — mobile drawer with submenu, breakpoint-aware engine switcher.
- **SEO-friendly** — `<title>` via `add_theme_support('title-tag')`, automatic meta description from excerpt/content, Open Graph + Twitter card tags, semantic landmarks, skip link.

## Setup

1. Drop the folder into `wp-content/themes/rocket-maps-theme/`.
2. **Activate the plugin** [`rocket-maps`](../w2gm) first so the plugin's helpers (`rmaps_get_active_map_engine`, `rmaps_get_map_style_for_engine`) are loaded.
3. Activate the theme in **Appearance → Themes**.
4. **Enable URL engine switching** by adding this line to `wp-config.php` near the other `define()` lines:

   ```php
   define( 'RMAPS_ALLOW_ENGINE_URL_OVERRIDE', true );
   ```

   Without this flag, the switcher buttons still render but the plugin ignores the `?rmaps_engine=…` parameter (a yellow notice in the switcher block points this out).
5. Go to **Appearance → Menus**:
   - create a *Header* menu with `Home`, `Dashboard`, `Demo` (parent of `Map 1`, `Map 2`, …), `Docs`, assign it to **Header menu**;
   - reuse the same menu for **Footer menu**, or build a slimmer one.
6. *(optional)* Set the **Buy plugin** URL via **Appearance → Customize → Theme Options → Buy plugin URL** (theme mod `rmaps_buy_url`). Defaults to https://www.salephpscripts.com/wordpress_maps/.

## How to use

### Standard demo page

Create a page (e.g. *Demo · Restaurants*), pick template *Default template*, drop:

```
[rmaps-engine-switcher]

[webmap id="42"]

## What this map shows
Description, features, screenshots — whatever you want under the map.
```

Switcher and map both render inside the standard container. Page header above, content centered.

### Full-width demo page

Same content, but pick template *Full width (100%)*. The engine switcher then spans the full viewport edge to edge, while text content keeps a sensible reading width via the `.rmaps-page-content-full` wrapper.

### Engine switcher placement

Two ways:

- **Shortcode** anywhere in post content: `[rmaps-engine-switcher]` (the old `[rmaps_engine_switcher]` underscore form still works for back-compat). Optional attrs: `compact="yes"` (icon-only), `label="Pick an engine"` (small caption above).
- **Template part** from inside a custom page template:

  ```php
  <?php get_template_part( 'template-parts/engine-switcher' ); ?>
  ```

## Plugin integration

The theme depends on two plugin helpers added alongside this theme (see plugin's `functions.php`):

| Helper | Used for |
| --- | --- |
| `rmaps_get_active_map_engine()` | Returns the live engine slug, honouring `?rmaps_engine=` when the wp-config constant is set. Falls back to the `rmaps_map_engine` site option otherwise. |
| `rmaps_get_map_style_for_engine( $post_id, $engine )` | Reads the per-engine style slug from postmeta `map_style_history` (JSON keyed by engine). Empty → caller falls back to the engine's default style. |

These are called inside `wp_enqueue_scripts` (so the right engine library loads), the `/maps/{id}/config` REST endpoint (so the right style URL/JSON is returned), and the listings form-context endpoint (so listing forms render under the same engine).

## File map

```
rmaps-theme/
├── style.css                 — WP theme header
├── functions.php             — setup, enqueue, shortcode, helpers
├── header.php / footer.php   — site chrome (nav + Buy CTA + theme toggle)
├── front-page.php            — homepage
├── page.php                  — standard page template
├── page-fullwidth.php        — full-width page template
├── single.php / index.php / 404.php / searchform.php
├── template-parts/
│   ├── engine-switcher.php   — three-button engine strip
│   └── feature-grid.php      — feature cards below maps
├── assets/
│   ├── css/theme.css         — single bundled stylesheet
│   └── js/theme.js           — burger + theme toggle + switcher
└── README.md
```

## Browser support

Targets evergreen Chrome / Firefox / Safari / Edge. CSS uses `color-mix()`, `backdrop-filter`, and CSS custom properties — no IE11 fallback shipped.
