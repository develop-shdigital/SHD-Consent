# SHD Consent

A cookie consent banner for WordPress that belongs to the site: no third-party service, no
page-view or domain limits, and no request to another server to draw it. Opt-in by category,
Google Consent Mode v2, tracking scripts held back until the visitor has agreed to their
category, and a consent log as proof of each choice.

| Folder | What it is |
| --- | --- |
| `shd-consent/` | The WordPress plugin. Install it on the site. |
| `tests/block-fixtures.php` | Runs the blocker over made-up and saved pages and checks what is held back, what is left alone, and that a page without tracking comes back unchanged. |

Requires WordPress 6.7 and PHP 7.4. Tested up to WordPress 7.1.

## What it does

### The banner

The stylesheet and both scripts are written into the page (a few kilobytes), so the banner is
painted with the first frame: it neither delays the page nor moves anything, and no other server
is contacted to show it. On phones it is a compact card over about a quarter of the screen.

“Accept all” and “Necessary only” look the same. “Settings” opens the categories with the
services behind them. The admin screen checks every colour pair for a contrast of at least
4.5:1.

The choice can be changed at any time: the small round button, the shortcode
`[shd_cookie_settings]`, any link ending in `#cookie-einstellungen` or `#cookie-settings`, or any
element with the class `shd-consent-open` or the attribute `data-shd-consent-open`.

### Categories and Consent Mode

Necessary (always on) plus three categories that can be switched off: Functional, Statistics and
Marketing. They are stored under the names CookieFirst uses (`functional`, `performance`,
`advertising`), so scripts written for CookieFirst keep working.

Before anything else runs, `consent-head.js` sets the Google Consent Mode v2 defaults to denied
(`wait_for_update: 500`) and, for a visitor who has already chosen, sends the update at once:
`ad_storage`, `ad_user_data`, `ad_personalization` from Marketing, `analytics_storage` from
Statistics, `functionality_storage` and `personalization_storage` from Functional;
`security_storage` is always granted.

The choice is kept in the cookie `shd_consent` (JSON: banner version, a random consent ID, the
time and the three categories; 365 days by default). With the CookieFirst setting on, it is also
written as `cookiefirst-consent` and announced with the `cf_consent` and `cf_consent_loaded`
events on `window` and `document`, and a choice the visitor made in CookieFirst is taken over.
Where a plugin provides the WordPress Consent API, `wp_set_consent()` is called as well.

Withdrawing a category deletes the first-party cookies its services set on the site (`_ga`,
`_hj`, `_fbp`, `_gcl`, `li_*`, `_ttp` and the like) and reloads the page.

### Holding back tracking scripts

An output buffer (`template_redirect`, priority 10000) runs over the finished HTML and gives
every tracking script `type="text/plain"` and `data-shd-consent="<category>"`, so the browser
ignores it. `consent.js` starts them again in page order once their category is allowed, keeping
`async` and the original type.

Recognised out of the box: Google Analytics (including MonsterInsights), Hotjar, Clarity, Google
Ads, Floodlight, DoubleClick, Meta, LinkedIn, TikTok, Bing, Pinterest, Leadinfo, OpenAI and X.
More can be added in the settings as “category | text”, matched against the address and the
inline code.

Two things are deliberately left alone. Scripts that ask for consent themselves (SHD Tracking
Manager, SHD Analytics) and the banner's own scripts are never held back. Neither is the `gtag()`
bootstrap that Google and WordPress write into the page: it only defines `dataLayer` and queues
calls, which Consent Mode sends once the loader is allowed to start — holding it back would lose
them.

A script of a consent tool this banner replaces (CookieFirst) is stopped for good rather than
held: the address is removed and it is marked `data-shd-stopped`, so visitors do not see two
banners and two buttons. The settings page names the script and where it comes from.

Scripts that another plugin has already held back for the first interaction are recognised as
well (SHD Performance writes `type="shd/delayed"` and moves the address to `data-shd-src`); the
blocker takes them over so they wait for consent instead. See
[Alongside SHD Performance](#alongside-shd-performance).

### The consent log

Every choice is posted to `shd-consent/v1/log` with `sendBeacon` and stored in the table
`<prefix>_shd_consent_log`: time, a random consent ID, the banner version, the three categories, how
the choice was made and the page. **No IP address and no browser details.** The endpoint accepts
at most 600 entries a minute, and entries are deleted automatically after a set number of months
(24 by default). The admin screen shows the shares of the last 30 days, searches by consent ID
and exports the whole log as CSV.

Raising the banner version asks every visitor again on their next visit.

## Install

1. WordPress → Plugins → Add New → Upload Plugin → choose the zip → Install → Activate. For an
   update: “Replace current with uploaded”.
2. *SHD Consent* in the admin menu: check the texts and the services, set the privacy policy and
   imprint links, and the colours.
3. While another banner plugin is active (CookieFirst, Complianz, CookieYes, Borlabs, Cookiebot,
   Real Cookie Banner, Cookie Notice, GDPR Cookie Compliance, iubenda, Usercentrics) this banner
   stays hidden and holds nothing back. Try it first with `?shd-consent-preview=1` as an
   administrator, then deactivate the other plugin: this one takes over at once.
4. Clear any page cache. Saving in the admin screen clears SHD Performance's cache itself and
   fires `shd_consent_saved` for other caches.

## Before you switch it on

- **The texts and the list of services are examples.** They must match the site and its privacy
  policy — the defaults name services a given site may not use at all. Have them checked by
  whoever advises you on data protection.
- **The banner holds one set of texts.** On a multilingual site, whatever translates the rest of
  the page has to translate the banner with it; check each language before going live.
- **Nothing is held back while the banner is hidden**, including while it waits for another
  banner plugin.
- Only `cookiez` is missing from the list of banner plugins it steps aside for.

## Alongside SHD Performance

[SHD Performance](https://github.com/develop-shdigital/SH-Digital-speed-optimization) starts
non-essential scripts on the first interaction. The two are built to nest: its output buffer
starts at `template_redirect` 9999 and this one at 10000, so the inner one runs first and sees
the untouched tags, and the performance plugin then leaves what it marked alone, because it only
rewrites the script types a browser runs.

It has to know this banner as well, because both scripts are written into the page and carry no
address, which is all its `consent_scripts` setting can match. Without that, `shd-consent-js` is
delayed with every other inline script: the banner is drawn but its buttons do nothing until the
first interaction, and the click that starts the delayed scripts is not the one the banner
receives, so the first click on “Accept all” is lost.

The sh-digital.ch build (1.5.2) handles this banner already. The
[LGnet build](https://github.com/develop-shdigital/SH-Digital-speed-optimization-for-LG-Net)
dropped that handling when it was forked in 1.6.0 and got it back in **1.6.1**, which keeps
`shd-consent-head` and `shd-consent-js` by id and ignores focus moved into `#shd-consent`.

## Development

The front-end scripts are inlined from `assets/js/min/` when a build exists and from
`assets/js/` otherwise, so the readable files are enough to work with. The committed builds come
from terser 5.51.2, which reproduces them byte for byte:

```bash
npx --yes terser@5.51.2 assets/js/consent.js --compress --mangle --comments false \
  --output assets/js/min/consent.min.js
```

The fixture test runs inside a WordPress install with the plugin active:

```bash
wp eval-file tests/block-fixtures.php                 # the built-in cases only
wp eval-file tests/block-fixtures.php <folder-with-saved-html>
```

It checks that tracking scripts are held back in the right category whether or not another plugin
has already delayed them, that address, `async` and the original type survive, that a replaced
consent tool stays stopped, and that the banner's own scripts are never touched. Saved pages of a
site without tracking must come back unchanged.
