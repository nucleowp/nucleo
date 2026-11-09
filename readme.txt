=== Nucleo ===
Contributors: nucleowp
Tags: blocks, page builder, layout, mcp, ai
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.4.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Builds WordPress pages from JSON documents compiled on save, with an MCP server for AI agents. No runtime cost.

== Description ==

Nucleo describes pages as JSON documents. The compiler turns them into HTML and CSS when you save, so every frontend request receives a page that is already built. Nucleo does not track visitors and does not call home. Third-party services load only when you turn them on: see External services.

Free version features:

* JSON document schema with field validation;
* core blocks: section, container, heading, text, image, button, menu, accordion, tabs, slider and more;
* compiler with HTML and CSS output;
* header, footer, single, archive, search and 404 layouts;
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

= What is the difference with the Pro version? =

The Pro version adds WooCommerce, multilingual sites, popups, the CSS purger, the asset audit and starter kits. Pro updates come from the Nucleo Pro server.

== External services ==

Nucleo works without external services. The services below are used only when you configure them. Nucleo sends no data to its author.

= Form captcha: Google reCAPTCHA, hCaptcha, Cloudflare Turnstile, Friendly Captcha =

Used only when you choose a captcha provider in Nucleo > Settings > Captcha and a page contains a Nucleo native form.

* When the page with the form loads, the visitor's browser loads the provider's widget script and the provider receives the visitor's IP address and browser data. Friendly Captcha is the exception: its widget script ships with the plugin, and the widget contacts Friendly Captcha from the browser to solve the puzzle.
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

All PHP and JavaScript of Nucleo ships unminified. The only minified files are the Friendly Captcha widget scripts in assets/lib/friendlycaptcha (version 0.1.37, MPL-2.0). Their source is at https://github.com/FriendlyCaptcha/friendly-captcha-sdk and on npm as @friendlycaptcha/sdk.

== Changelog ==

= 0.4.1 =

* New: Repeater and relation fields and field groups on a taxonomy work in Free too.
* Action needed: The artifact store moves from wp-content/cache/nucleo to uploads/nucleo/store; the update moves the site folder.
* Action needed: The transients and the cookie of OTP codes and collections use the nucleo_ prefix instead of nc_.
* Improved: Captcha scripts, the menu style and the preview style load with wp_enqueue_script and wp_enqueue_style.

= 0.4.0 =

* New: WooCommerce product page, archive, cart and checkout run on Nucleo, without woocommerce.css or the classic jQuery scripts.
* New: Composable Nucleo cart, and a WooCommerce checkout you configure with woo-checkout-parts-set.
* New: The mini-cart block has a configurable panel and a side drawer variant, with item cards, coupon and totals.
* New: Product page with Reviews and Additional information tabs and a review form in Nucleo style, even when the theme does not declare WooCommerce support.
* Action needed: doc-create with a layout kind and no postType creates a nucleo_layout document; with postType page or post it rejects the layout, popup and section kinds.
* Action needed: Over MCP an insufficient permission (insufficient_scope) arrives as JSON with the missing scope, like the other errors.
* Improved: The CLI prints errors, warnings and output in English, and nucleo component new generates descriptions, comments and examples in English.
* Improved: WooCommerce abilities are hidden when WooCommerce is not active.
* Improved: woo-checkout-parts-set sets company, phone and address line 2 with the classic checkout too; reading no longer counts as a write.
* Improved: doc-validate and quality-check accept postType and check new content against the layouts of its type.
* Improved: MCP tool descriptions now list error codes and cross-references; new woocommerce topic in catalog-get and rule R4.7 in the guide.
* Improved: After a Nucleo update, script URLs carry the new version and documents are recompiled.
* Fixed: In the Free package with WooCommerce active, Add to cart no longer causes a fatal error.
* Fixed: An item read with content-list or content-get can be sent back to content-upsert as is; content-get returns the hash to use as the base.
* Fixed: content-upsert and terms-set no longer rewrite items identical to the saved ones: they answer unchanged and post_modified stays as it is. The CLI push lists unchanged content separately (content unchanged).
* Fixed: A CLI push interrupted halfway no longer reports changes the site already accepted as conflicts; a conflict in content/ and terms/ suggests the right command.
* Fixed: layout-explain follows the runtime: on a product it marks single as replaced by product and lists the printed layouts in applied.
* Fixed: doc-create gives drafts a unique slug from the title too; the slug of an exported document is normalized instead of rejected.
* Fixed: A Cart or Checkout page with a Nucleo document keeps the WooCommerce block, and woo-checkout-parts-set edits it without losing it.
* Fixed: Product variations also show terms without a saved order.
* Fixed: The URL of a script with a parameter such as ?server= gets the version.

= 0.3.5 =

* Fixed: With several videos, forms, embeds or sliders on one page, each instance loads its script on first use, not only the first one.
* Fixed: With a single layout, the forms, collections and linked fields of the page display correctly.
* Fixed: A Nucleo page with blocking quality issues no longer goes live from the WordPress editor either.
* Fixed: With an OAuth token, editing a page that is already live requires the publish scope.
* Fixed: The CLI push validates the whole folder before changing the site and exits with an error when the site rejects the import.
* Fixed: The CLI pull keeps a copy of overwritten local files and reports pages deleted from the site.
* Fixed: Title and status changed in the admin are no longer silently overwritten by a push; wp.status draft moves a published page back to draft.
* Fixed: The rebuild queue no longer loses documents that fail and lists them in status.
* Fixed: The editor Publish button also saves the texts of the Text only view; the editor asks for confirmation before leaving with unsaved changes.
* Fixed: The mini-cart works with WordPress in a subfolder.

= 0.3.4 =

* Improved: Requests for several blocks from the catalog return a compact form under 25,000 characters. AI agents read the blocks of a page in one call.
* Fixed: The quality check no longer reports a missing nav landmark when the menu is in the site header.
* Fixed: Validating a page that is not saved yet takes the site header and footer into account, as saving does.

= 0.3.3 =

* Improved: Admin links to nucleowp.dev use the new site addresses for documentation, pricing, customer area and feedback.
* Fixed: The plan comparison link opens the pricing section of the Pro page. The renewal link opens the licenses in the customer area.
* Fixed: The feedback link opens the board in the user's language.

= 0.3.2 =

* Fixed: With WordPress in English, parts of the plugin, editor, WP-CLI and block scripts showed Italian text. The interface now follows the WordPress language, in English and Italian.
* Fixed: Labels written into pages, such as slider, video, embed and menu labels, use the site language instead of the language of the user who saves. With Polylang or WPML they use the page language.
* Fixed: Admin dates use the user's language.
* Action needed: Pages saved before this version get the right labels at the next save or with a recompile from Tools.
* Improved: The site documentation is complete in English and Italian.

= 0.3.1 =

* Action needed: The theme is now Nucleo Canvas (folder nucleo-canvas). Sites with nucleo-theme get Nucleo Canvas as an update, and WordPress activates it in place of the old theme.
* Improved: Nucleo Canvas keeps the logo, menu locations and options of the old theme. After the update you can delete nucleo-theme.
* New: The Free plugin downloaded from nucleowp.dev updates from updates.nucleowp.dev. The Free plugin from WordPress.org updates from the directory.
* New: The theme adds comments, pagination and entry templates, and works without the plugin.

= 0.3.0 =

* New: Adds the Support page with a link to the feedback form and your site details.
* Improved: The Agents page has setup guides for Claude, ChatGPT, Cursor, VS Code, Codex, Gemini CLI, Windsurf and STDIO.
* New: Adds the Page settings tab in the Nucleo editor, with theme, layout and title per page.
* New: The Back to the WordPress editor button keeps the Nucleo document; Edit with Nucleo restores it.
* New: Adds the Embed, Off-canvas, HTML and Shortcode blocks.
* Improved: Shows the inserter with icons and lets you drag blocks into the preview.
* New: Edits titles, texts, buttons and lists directly in the preview with a double click.
* New: Adds quality checks for images, DOM size, CSS, JS, fonts and embeds.
* Security: Limits Bearer tokens to Nucleo REST routes and hardens the OAuth server.
* Action needed: Bearer tokens work only on /nucleo/v1/*. Update clients that call other paths.

= 0.2.0 =

* Pro: Nucleo Pro and the theme update from the updates.nucleowp.dev server.
* Pro: Pro receives updates with a license active on the site.
* Action needed: The theme updates without a license, only after the plugin: it requires at least the Nucleo version it ships with.
* Action needed: The license, update and store domain moves to nucleowp.dev.

= 0.1.12 =

* New: Adds captcha to the native nc/native-form forms.
* New: Each site uses one provider: reCAPTCHA v2, reCAPTCHA v3, hCaptcha, Cloudflare Turnstile, or Friendly Captcha.
* New: Keys are set in settings-set, group captcha, or in the Captcha tab of the settings.
* Security: The secret is write-only.
* New: Each native form shows the widget above the buttons.
* New: With settings.captcha set to false, the form does not show the widget.
* Security: The server verifies the token on submit and on OTP code submit.
* New: If the provider does not respond, the form accepts the submission.

= 0.1.11 =

* Fixed: Nucleo reads page titles from every results page of doc-list, not only the first.
* Fixed: Pages beyond the first results page now receive their correct title.
* Fixed: SEO titles now cover all pages of the site.

= 0.1.10 =

* New: New siteIcon key: sets the site favicon from an image in the media library.
* New: WordPress uses the same image as the app icon.
* New: A null value removes the favicon.
* New: With the URL of another site, the image is found by file name.
* New: The export includes siteIcon only when the site has a favicon.

= 0.1.9 =

* Improved: The critical CSS includes the rule that hides closed elements, such as the menu close icon and closed panels.
* Fixed: The preview no longer changes the header height.
* Improved: The loader marks the stylesheets it loads, and the check waits for them.
* Improved: On slow servers, the check starts only after the full CSS loads.
* Improved: The critical CSS check ignores the height of elements below the first screen.

= 0.1.8 =

* New: With the site option imageFormats, off by default, Nucleo creates AVIF and WebP versions next to each uploaded image.
* New: Images use the modern format and show the original if the browser does not support it.
* New: The image block and post images use the picture tag, with the original image as fallback.
* New: You can turn off the formats for a single image block.
* New: The image block accepts hand-written sources.
* New: Sources exclude crops. Without sizes, the image block follows the WordPress width rule.
* New: The MCP tool nucleo-media-formats generates the formats for images that are already uploaded.
* New: The picture tag does not change the image layout and classes.

= 0.1.7 =

* New: Each block declares its internal parts, such as labels, legends, and placeholders.
* New: Parts accept style keys, including breakpoints and states.
* New: style.custom adds CSS to the node for rules that style keys do not cover.
* New: The size, spacing, typography, color, border, and motion groups apply to every block. supports.styleExclude removes them.
* Improved: Units follow the CSS property. transition accepts any property and multiple transitions.
* Improved: Each page outputs one JSON-LD @graph in the footer. Company data accepts any schema.org property.
* New: Two new MCP tools, nucleo-gap-report and nucleo-gap-list, record what Nucleo lacks.
* New: New style keys: accentColor, fontStyle, minWidth, maxHeight, and transform.
* Improved: The sitemap filter runs only on sitemap requests. Export omits company data and empty icons.
* Fixed: The new browser test of the parts found two defects, now fixed: the index declared a part that does not exist, and the form numbering won over the part style.

= 0.1.6 =

* Fixed: optionalWeight and numberWeight stay numbers: 400 does not become 400px.
* New: numberFont sets the font family ($font.mono).
* New: numberFontSize and numberWeight set the size and weight of the numbering.

= 0.1.5 =

* New: The nc/breadcrumbs block prints the BreadcrumbList of its path.
* New: Company data accepts legalName, vatID and taxID.
* Improved: transition accepts filter, background, border and text-decoration-color.
* Improved: nc/native-form accepts a width with maxWidth or width.
* New: nc/native-form adds letters-box and numbers-box numbering.
* Improved: nc/native-form accepts the optionalSize, optionalWeight, number*, controlMinHeight, placeholderColor, checkSize, checkAccent and navJustify styles.
* Fixed: The legendLine rule is now visible and reaches the edge.
* Improved: nc/button accepts size styles, such as width 100%.

= 0.1.4 =

* New: Adds the Hover and Current style states.
* New: Adds the nc/toc index block, which highlights the entry of the visible section while the page scrolls.
* New: Adds markers and styles to the fields of the native form block.
* New: Imports site icons from a folder of SVG files with the nucleo icons import command.
* New: Downloads icons from Iconify once with the nucleo icons add command.
* New: Lists and removes site icons with the nucleo icons list and nucleo icons remove commands.
* New: Adds and removes single site icons through the dedicated MCP tool. The set accepts up to 300 icons.
* New: Replaces a built-in icon with the site icon that has the same name, across the whole site.
* New: Generates structured data for the company, SEO schema in the metadata, and automatic breadcrumbs.
* New: Excludes pages marked noindex from the sitemap.
* New: Adds rule R4.6 for agents: no mu-plugins, theme code or scripts in wp_head; site PHP goes in components.

= 0.1.3 =

* New: The build writes the changelog entry automatically for each new version.
* New: The changelog uses the commits since the last version.
* New: If the claude CLI is missing or fails, the build shows a warning and continues.

= 0.1.2 =

* New: Styles support one-side borders, including dashed borders, whiteSpace, grayscale filter and container opacity.
* Improved: Cards with a link become clickable across the whole card.
* New: Sections hide their node when the parameter is empty.
* New: Sections support the list parameter and the repeat binding.
* New: New icons: car, bus, tractor, search, gauge, layers and refresh.
* Improved: Accordion shows a chevron or plus icon, and its text inherits the block typography.
* New: Forms have style groups for controls and buttons.
* New: Form fields can take half width and group into fieldsets.
* New: Invalid form fields show a highlighted border.
* Improved: All 1797 strings are translated.

= 0.1.1 =

* Improved: The validator checks array items, media: and menu: and proposes the fix with the node id.
* Improved: Push lists allowed values in its errors and fetches missing media from the library.
* Improved: Push reports the page that names a section not imported.
* Improved: Form emails to the admin now include the recipient.
* Improved: Form errors show the cause to the admin and the developer.
* New: Blocks add 8 icons and support text transform.
* Fixed: The link button no longer shows an underline.
* New: Nucleo generates title, description, Open Graph and noindex. With Yoast or Rank Math active, Nucleo's SEO turns off.
* Improved: New menu items appear right after push.
* Improved: Translations are regenerated: 1793 strings, all translated.

= 0.1.0 =

* New: First release.
