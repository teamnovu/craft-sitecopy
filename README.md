# Site Copy X for Craft CMS 4/5

This plugin makes it easy to copy the content of an element from one site to another, with fine-grained control over what gets copied.

## Supported elements

- Entries
- Global sets
- Assets
- Categories
- Craft Commerce products (including variant fields and titles)

---

## Features

### Copy to another site

When editing an element, the sidebar shows a **"Copy to site"** toggle. Enable it, select one or more target sites, and save — the content is copied in the background via Craft's queue.

> **Attention:** This will **overwrite** all content on the selected target sites.

![Screenshot](resources/screenshots/screenshot1.png)

### Bulk copy from the element index

Select multiple entries, assets, categories, or Commerce products from the element index and use the **"Copy to site"** action to copy them all at once. A modal lets you choose the target sites before confirming.

Bulk copy is available for:

- Entries (section-based sources only)
- Assets
- Categories
- Craft Commerce products

### Per-field selection

When "Fields (Content)" is enabled in plugin settings, the sidebar widget shows a **checkbox for each field** in the element's field layout. Uncheck any field you don't want copied on that specific save. All fields are pre-selected by default.

### Choose which attributes to copy

In the plugin settings you can configure which attributes are copied globally:

| Attribute         | Description                           |
| ----------------- | ------------------------------------- |
| Fields (Content)  | All custom field values               |
| Title             | The element title                     |
| Slug              | The URL slug                          |
| Commerce Variants | Variant custom fields and titles      |

### Automatic copy rules

![Screenshot](resources/screenshots/screenshot2.png)

Configure rules in the plugin settings to automatically pre-select target sites when editing specific entries. Rules can match by entry ID, type, section, site, or other criteria, and support `equals` / `does not equal` operators with `AND`, `OR`, and `XOR` logic.

### Global sets and assets

For global sets, the copy toggle appears at the bottom of the content area. Assets are supported through the element index bulk action and the standard sidebar widget.

### Craft Commerce

Variant custom fields and titles are copied when the corresponding attributes are enabled in plugin settings.

### AI assistants (MCP)

On Craft 5.12 and later, Site Copy X adds four tools to [Craft's MCP server](https://craftcms.com/docs/5.x/extend/mcp.html), so an AI client connected to the site (e.g. Claude) can copy content for an editor:

| Tool                     | What it does                                                                                              |
| ------------------------ | --------------------------------------------------------------------------------------------------------- |
| `site_copy_x_targets`    | Shows the sites an element can be copied to, and its fields                                               |
| `site_copy_x_copy`       | Copies up to 50 elements to one or more sites, with a dry run and the option to include or exclude fields |
| `site_copy_x_job_status` | Shows whether copy jobs are waiting, running, failed or finished, with the result for each target site    |
| `site_copy_x_rules_list` | Lists the automatic copy rules                                                                            |

The tools require control panel access and apply the same permissions as the control panel: edit access to each site and permission to save the element there. They are never available on the public MCP server. The copy tool is marked destructive, so access tokens need the `mcp:write` scope, and it asks the client to confirm every call. Title and slug are only copied if the plugin settings allow it. Commerce variants are not copied through MCP.

To use the tools, enable MCP under **Settings → MCP** and run `php craft oauth/generate-keys` for HTTP clients, or run `php craft mcp/serve --user=<username>` for a local client. Copies run through the Craft queue. `mcp/serve` doesn't run the queue, so keep a queue worker running or run `php craft queue/run`.

---

## How it works

The copy is handled by a **queue job** (`SyncElementContent`), so changes may not appear immediately on target sites. The job:

1. Serializes the selected fields/attributes from the source element
2. Remaps linked element IDs and Link field reference tags to the target site
3. Saves each target site element with propagation disabled to prevent cascading back to the source site
4. Skips target sites that were edited after the copy was requested, and records the outcome for each target site. If a target site can't be saved, the job fails in the queue with the validation errors.

---

## Requirements

Craft CMS 4.5.11 or later (compatible with both Craft 4 and Craft 5). The MCP tools need Craft 5.12 or later.

## Installation

```bash
composer require teamnovu/craft-sitecopy
```
