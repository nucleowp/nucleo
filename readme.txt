=== Nucleo ===
Contributors: nucleowp
Tags: blocks, page builder, layout, mcp, ai
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.5.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Builds WordPress pages from JSON documents compiled on save, with an MCP server for AI agents. No runtime cost.

== Description ==

Nucleo describes pages as JSON documents. The compiler turns them into HTML and CSS when you save, so every frontend request receives a page that is already built. Nucleo does not track visitors and does not call home. Third-party services load only when you turn them on: see External services.

Free version features:

* JSON document schema with field validation;
* core blocks: section, container, heading, text, image, button, menu, accordion, tabs, slider and more;
* compiler with HTML and CSS output;
* header, footer, single, archive, search and 404 layouts, printed by the Nucleo Canvas theme (see the FAQ);
* quality engine with SEO and accessibility checks (for example, a page with two h1 headings);
* content model: custom post types, taxonomies and field groups, with repeater and relation fields;
* MCP server to connect AI agents, with OAuth 2.1;
* WP-CLI commands.

No runtime cost: the work happens on save, not on every visit.

== Installation ==

1. Upload the nucleo folder to wp-content/plugins, or install the zip from Plugins, Add New.
2. Activate Nucleo from the Plugins screen. On activation the plugin checks PHP, WordPress, OPcache and write permissions, and shows the recommended actions.
3. Requirements: WordPress 6.9 or later, PHP 8.2 or later, MySQL 5.7 or MariaDB 10.4 or later. OPcache is recommended. Node and Chrome are not required.

== Frequently Asked Questions ==

= Do I need a license? =

No. The free version works without a key and without an account.

= Does Nucleo slow down my site? =

No. The compiler works on save. The frontend receives ready-made HTML and CSS. It contacts a third-party service only if you add one, for example a captcha on a form or a YouTube video.

= Do layouts need a specific theme? =

Pages built with Nucleo work with any theme: Nucleo fills the content area of the page. Header, footer and template layouts are printed by Nucleo Canvas, a free GPL theme that you can download from https://nucleowp.dev/download/. With another theme, the layouts you create are saved but not shown.

= What is the difference with the Pro version? =

The Pro version adds WooCommerce, multilingual sites, popups, the CSS purger, the asset audit and starter kits.

== External services ==

Nucleo works without external services. The services below are used only when you configure them. Nucleo sends no data to its author.

= Form captcha: Google reCAPTCHA, hCaptcha, Cloudflare Turnstile, Friendly Captcha =

Used only when you choose a captcha provider in Nucleo > Settings > Captcha and a page contains a Nucleo native form.

* When the page with the form loads, the visitor's browser loads the provider's widget script and the provider receives the visitor's IP address and browser data. Friendly Captcha is the exception: its widget script ships with the plugin, and the widget contacts Friendly Captcha from the browser to solve the puzzle (global.frcapi.com or eu.frcapi.com, with the fallback hosts global0, global1, eu0 and eu1 on the same domain).
* When the visitor submits the form, your server sends the captcha token and your secret key to the provider's verification endpoint, once per submission:
  * Google reCAPTCHA: www.google.com/recaptcha/api/siteverify, with the visitor's IP address too;
  * hCaptcha: api.hcaptcha.com/siteverify, with your site key too;
  * Cloudflare Turnstile: challenges.cloudflare.com/turnstile/v0/siteverify;
  * Friendly Captcha: global.frcapi.com/api/v2/captcha/siteverify, with your site key too. The API key travels in the X-API-Key header.

Terms and privacy:

* Google reCAPTCHA: [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy)
* hCaptcha: [Terms](https://www.hcaptcha.com/terms), [Privacy](https://www.hcaptcha.com/privacy)
* Cloudflare Turnstile: [Terms](https://www.cloudflare.com/website-terms/), [Privacy](https://www.cloudflare.com/privacypolicy/)
* Friendly Captcha: [Terms](https://friendlycaptcha.com/legal/terms/), [Privacy](https://friendlycaptcha.com/legal/privacy-end-users/)

= Google Fonts =

Used when you import a Google Fonts family into the design tokens. Your server downloads the CSS from fonts.googleapis.com and the font files from fonts.gstatic.com once, and stores them on your site. Visitors never contact Google. The request contains the family name and weights. [Terms](https://developers.google.com/fonts/terms), [Privacy](https://policies.google.com/privacy)

= YouTube and Vimeo =

Used when a page contains a Video or Embed block with a YouTube or Vimeo address. The frontend shows the thumbnail from i.ytimg.com for YouTube and loads the player from youtube-nocookie.com or player.vimeo.com when the visitor clicks play. The provider receives the visitor's IP address and browser data.

* YouTube: [Terms](https://www.youtube.com/t/terms), [Privacy](https://policies.google.com/privacy)
* Vimeo: [Terms](https://vimeo.com/terms), [Privacy](https://vimeo.com/privacy)

= AI agents over MCP (Claude, ChatGPT, VS Code and other MCP clients) =

Used only when an administrator connects an AI client to the site's MCP endpoint (Nucleo > AI agents). The site does not contact the AI provider. The AI client calls the site.

* Sign-in: the administrator approves the client on a WordPress consent page. Then WordPress redirects the browser to the client's callback address with a one-time authorization code. Examples: claude.ai/api/mcp/auth_callback for Claude and chatgpt.com/connector_platform_oauth_redirect for ChatGPT. Local clients use a localhost address. The client exchanges the code for an access token limited to the approved scopes.
* Client metadata: if the client identifies itself with a URL, the site downloads that public JSON document once to read the client's name and callback addresses.
* Use: the agent reads and writes pages, layouts, design tokens and content through the site's REST API, with the permissions of the approving user. The content it reads reaches the AI provider, which processes it under its own terms.

* Anthropic (Claude): [Terms](https://www.anthropic.com/legal/consumer-terms), [Privacy](https://www.anthropic.com/legal/privacy)
* OpenAI (ChatGPT, Codex): [Terms](https://openai.com/policies/terms-of-use/), [Privacy](https://openai.com/policies/privacy-policy/)
* Microsoft (VS Code, GitHub Copilot): [Terms](https://www.microsoft.com/en-us/servicesagreement), [Privacy](https://www.microsoft.com/en-us/privacy/privacystatement)

= Addresses you enter =

These features contact only the address that you or a connected AI agent provide:

* Embed block: an address other than YouTube or Vimeo, for example a Google Maps page, loads in a frame when the visitor clicks it. That site receives the visitor's IP address and browser data.
* Form webhook: on submit, the form fields are sent to the webhook URL set in the form.
* Media import: the image at the given URL is downloaded into the media library.

== Source code ==

All PHP and JavaScript of Nucleo ships unminified. The only minified files are the Friendly Captcha widget scripts in assets/lib/friendlycaptcha (version 1.1.5, MPL-2.0). Their source is at https://github.com/FriendlyCaptcha/friendly-captcha-sdk and on npm as @friendlycaptcha/sdk.

The font metrics in data/font-metrics.json come from @capsizecss/metrics 3.7.0 (MIT, https://github.com/seek-oss/capsize). The license is in data/LICENSE-capsize-metrics.

== Changelog ==

= 0.5.2 =

* Action needed: Saved form submissions use the meta keys _nucleo_form, _nucleo_data and _nucleo_context instead of _nc_form, _nc_data and _nc_context. The update renames the existing ones.
* Improved: The Friendly Captcha widget is updated to 1.1.5.
* Improved: JSON-LD is printed with wp_get_inline_script_tag.
* Improved: The readme says that layouts are printed by the Nucleo Canvas theme.
* Fixed: Three PHP files were missing the ABSPATH check.
* Fixed: The package for WordPress.org no longer shows settings and integrations whose code it does not contain.

= 0.5.1 =

* Security: forms decide the captcha exemption from their configuration, not from the request fields; an unknown OTP code gets a 400 error.
* Security: layouts and global sections require edit_theme_options; with an OAuth token, writing also requires the nucleo:design scope.
* Security: a section included in a page shows drafts, private and protected content only to users who can edit them; publishing and rebuilds use the permissions of the page author.
* Security: media import, form webhooks and the OAuth client connect only to the verified IP address and do not follow unverified redirects; the 100.64.0.0/10 and 198.18.0.0/15 ranges are blocked too.
* Security: nc/query lists only the content types allowed by collections; nc/collection parts reject visibility conditions.
* Security: in tabs, protected meta keys need the permission to edit the post; doc-preview with contextPostId needs the same permission.

= 0.5.0 =

* Improved: Internal changes that prepare the WPML integration of Nucleo Pro. Nothing changes for sites that use this package.

= 0.4.4 =

* Security: OAuth endpoints, native forms and custom fields read only the keys they expect and sanitize each value by type.
* Security: The document title goes through kses for users without unfiltered_html.
* Security: CSS values in styles, style.custom and tokens must follow a closed grammar: numbers with units, colors, keywords, quoted names and a fixed list of CSS functions.
* Action needed: A generic nc/embed accepts only oEmbed providers known to WordPress. The nucleo/embed_hosts filter adds other hosts.
* Improved: The cache conflict notice has a Dismiss link and stays closed until the conflicts change.
* Improved: Every PHP file checks ABSPATH, and block titles and condition labels are translatable.
* Improved: The Friendly Captcha widget is updated to 1.1.3.
* Action needed: doc-create, doc-patch and layout-conditions-set refuse a new or changed language condition, because this package does not detect the language of the request. Site import and push remove it and return a warning. Conditions saved earlier stay.
* Fixed: The agent guide no longer points to kit-list or to the woo/* blocks, which this package does not contain.

= 0.4.3 =

* Action needed: style.custom is a list of structured rules; Nucleo builds the selector from closed lists (state, inner element, position, pseudo-element). Documents in the previous format are converted on load.
* Action needed: critical-set uses the CSS it receives as a list of selectors and saves the matching rules of the local stylesheets; the others are dropped.
* Security: SVG icons, preview and SEO meta tags go through wp_kses or esc_* where they are printed; JSON-LD uses wp_json_encode.

= 0.4.2 =

* Improved: MCP tool descriptions say when to use each tool and which tool to use instead.

= 0.4.1 =

* New: Repeater and relation fields and field groups on a taxonomy work in Free too.
* Action needed: The artifact store moves from wp-content/cache/nucleo to uploads/nucleo/store; the update moves the site folder.
* Action needed: The transients and the cookie of OTP codes and collections use the nucleo_ prefix instead of nc_.
* Improved: Captcha scripts, the menu style and the preview style load with wp_enqueue_script and wp_enqueue_style.

Older versions: https://nucleowp.dev/en/changelog/
