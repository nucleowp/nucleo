# Nucleo: WordPress page builder with an MCP server for AI agents

Nucleo builds WordPress pages from JSON documents compiled on save. Every frontend request receives HTML and CSS that are already built, so the page builder adds no runtime cost.

Nucleo includes an MCP server with OAuth 2.1. Claude, Cursor and other MCP clients read the site and create drafts with the permissions you approve. A quality engine checks SEO and accessibility before a page goes live.

- Website: [nucleowp.dev](https://nucleowp.dev/en/)
- Documentation: [nucleowp.dev/en/docs](https://nucleowp.dev/en/docs/)
- Version in this repository: 0.5.2

## Features

- JSON document schema with field validation.
- Core blocks: section, container, heading, text, image, button, menu, accordion, tabs, slider and more.
- Compiler with HTML and CSS output, on save.
- Header, footer, single, archive, search and 404 layouts.
- Quality engine with SEO and accessibility checks.
- Content model: custom post types, taxonomies and field groups, with repeater and relation fields.
- MCP server for AI agents, with OAuth 2.1.
- WP-CLI commands.

## Requirements

- WordPress 6.9 or later.
- PHP 8.2 or later.
- MySQL 5.7 or MariaDB 10.4 or later. OPcache is recommended.

## Install

1. Download the zip of the latest release from the [Releases page](https://github.com/nucleowp/nucleo/releases), or from [nucleowp.dev/en/download](https://nucleowp.dev/en/download/).
2. In WordPress, open **Plugins → Add New → Upload Plugin** and install the zip.
3. Activate Nucleo. On activation the plugin checks PHP, WordPress, OPcache and write permissions.

## Connect an AI agent

The MCP server answers at this address on your site:

```text
https://your-site.com/wp-json/nucleo/v1/mcp
```

Claude Code:

```bash
claude mcp add --transport http nucleo https://your-site.com/wp-json/nucleo/v1/mcp
```

WP-CLI, over STDIO, with the permissions of the given user:

```bash
wp nucleo mcp --user=admin
```

The client opens the authorization page in WordPress. You choose the scopes and approve. The full guide is at [Connect an AI agent](https://nucleowp.dev/en/docs/connect-agent/).

## About this repository

This repository is a read-only mirror of the free plugin, the same code published on WordPress.org. Each Nucleo release updates it automatically, with a commit and a `vX.Y.Z` tag. Pull requests are not merged here.

To report a bug or ask for a feature, use [nucleowp.dev/en/feedback](https://nucleowp.dev/en/feedback).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
