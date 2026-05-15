# WordPress.org Assets

This folder is consumed by [`10up/action-wordpress-plugin-deploy`](https://github.com/10up/action-wordpress-plugin-deploy) and pushed to the SVN `assets/` directory of the WP.org plugin page.

## Required files

| File | Size | Purpose |
| --- | --- | --- |
| `icon-128x128.png` | 128 × 128 px | Plugin icon (retina source: `icon-256x256.png`) |
| `icon-256x256.png` | 256 × 256 px | High-DPI icon |
| `banner-772x250.png` | 772 × 250 px | Header banner on the plugin page |
| `banner-1544x500.png` | 1544 × 500 px | High-DPI banner |

## Optional files

| File | Size | Purpose |
| --- | --- | --- |
| `screenshot-1.png` … `screenshot-N.png` | any | Match the `Screenshots` entries in `readme.txt` |
| `icon.svg` | vector | Used if present instead of PNG icons |

## Design notes

Brand alignment: lean on the Pied Web identity — Ascent Brown `#582908`, Beige `#CFA566`, with forest green `#066400` as a trust accent. Keep the banner type-led (the plugin name in a strong sans, "Master/Shadow i18n for WordPress + WooCommerce" as the tagline). Avoid stock imagery.
