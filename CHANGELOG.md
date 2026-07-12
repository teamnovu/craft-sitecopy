# Changelog

## 4.0.4 - 2026-07-10
### Fixed

- Fixed queued copies overwriting content that was edited on the target site after the copy was requested. The copy runs as a background job that re-reads the source element when it executes; if an editor opened the target site and changed its content while the job was still queued, the job would overwrite those changes with the source content. Each target site's modification date is now captured when the copy is requested, and any site edited after that point is skipped (with a warning logged) instead of being overwritten. Jobs queued by an earlier version are unaffected and run as before.

## 4.0.3 - 2026-07-01
### Fixed

- Fixed a fatal error when editing an element with the Site Copy widget in the sidebar: the widget's asset bundle was still registered under the pre-rebrand `neustadt\sitecopy` namespace in a Twig template and could not be found after the 4.0.2 rename. This restores the sidebar widget, which was broken in 4.0.2.

## 4.0.2 - 2026-07-01
### Changed

- **Breaking:** rebranded from Neustadt to Novu. The Composer package is now `teamnovu/craft-sitecopy` (was `nst/craft-sitecopy`) and the PHP namespace is now `teamnovu\sitecopy` (was `neustadt\sitecopy`). If your project extends any of the plugin's classes, update your `use` statements accordingly.
- Update your `composer.json` to require `teamnovu/craft-sitecopy` instead of `nst/craft-sitecopy`, then run `composer update`. The plugin handle (`site-copy-x`) and all functionality are unchanged.

## 3.4.2 - 2026-06-18
### Added

- Cross-sitegroup manual copy: users can now copy content to sites in different site groups, not just within the same site group (requested in [#22](https://github.com/teamnovu/craft-sitecopy/issues/22))
- Inline warning in the sidebar widget and bulk copy overlay: when a selected target site does not have the element yet, a yellow notice lists the affected sites as soon as a checkbox is ticked, and disappears when the checkbox is unchecked
- Warning logs when a target site element doesn't exist during copy operations, helping diagnose why content wasn't copied to all selected sites

### Changed

- Manual copy UI (sidebar widget and bulk copy) now shows all available sites instead of only sites in the same propagation group, enabling more flexible content distribution workflows

## 3.3.0 - 2026-06-15
### Added

- Per-field copy selection: when "Fields (Content)" is enabled in plugin settings, the sidebar widget now shows a checkbox for each field in the element's field layout, letting editors choose which fields to copy on each save

## 3.2.1 - 2026-06-15
### Fixed

- Fixed Matrix (nested) entries being duplicated on the source site when copying to another site ([#24](https://github.com/teamnovu/craft-sitecopy/issues/24))

## 3.2.0 - 2026-06-15
### Added

- Restored bulk copy feature: select multiple entries/assets/categories/products in the element index and copy them to another site in one operation
- Bulk copy is available for entries (section-filtered), assets, categories, and Commerce products in multisite setups
- Commerce variant titles are now copied when `title` is included in the attributes to copy

### Fixed

- Fixed "Select All" checkbox scoping in element slideout containers — the toggle now correctly targets only checkboxes within its own widget instead of all checkboxes on the page
- Fixed variant custom fields being wiped when copying variant titles (regression that would have been introduced by PR #30)

## 3.1.6 - 2026-06-09
### Fixed
- Fixed "Element query executed before Craft is fully initialized" warning by deferring event listener registration using `Craft::$app->onInit()` [#25](https://github.com/teamnovu/craft-sitecopy/issues/25)

## 3.1.5 - 2026-06-09
### Fixed
- Fixed Link field remapping - selected entries now correctly link to the target site instead of remaining linked to the source site
- Improved element ID mapping across multiple sites

### Changed
- Updated copyright and author information
- Added official support for Craft CMS 5 (compatible with both Craft 4 and 5)

## 1.1.0 - 2024-01-22

### Changed

- Site Copy X now requires Craft CMS 4.5.11

### Fixed
- Fixed a database error that could occur when saving an entry while the copy-to-site job is running [#9](https://github.com/teamnovu/craft-sitecopy/issues/9)

## 1.0.10 - 2023-10-19

### Changed

- The list of sites to copy to is now sorted by site group to provide a better ui for multisite setups [#6](https://github.com/teamnovu/craft-sitecopy/issues/6)


## 1.0.9 - 2023-10-16

### Added

- Added a new setting option that allows administrators to set a queue priority to the plugin jobs if they desire (thanks @Alxmerino)

## 1.0.8 - 2023-09-11

### Changed

- Rolled back the changes from version 1.0.7 as the related issue got fixed in craft 4.5.1

## 1.0.7 - 2023-08-23

### Fixed
- Fixed an issue with craft 4.5.0 matrix field values not being copied

## 1.0.6 - 2023-07-06

### Added
- Added a new "select all" checkbox

## 1.0.5 - 2023-04-03

### Added
- Added the ability to copy commerce variant fields

## 1.0.4 - 2022-11-24

### Added
- Site Copy now works with categories too!

## 1.0.3 - 2022-11-11

### Changed
- Changed the plugin handle and name to not confuse it with the old plugin

## 1.0.2 - 2022-09-23

### Fixed
- Fixed bugs caused by the changing of the plugin handle

## 1.0.1 - 2022-09-21

### Changed
- Changed the plugin handle to separate it from the craft 3 plugin

## 1.0.0 - 2022-09-20
### Added
- Support for craft 4!

### Changed
- The user can now only copy to sites he is allowed to edit (previously he could copy to **any** site, regardless of permissions)

## 0.8.0 - 2022-07-22
### Fixed
- Fixed an issue where the content of nested blocks with the propagation method "all" would be copied to too many sites ([#33](https://github.com/Goldinteractive/craft3-sitecopy/issues/33))

## 0.7.1 - 2022-06-16
### Fixed
- Fixed a bug on siteGroups where element sections are disabled for the primary site

## 0.7.0 - 2021-12-23
### Changed
- Sitecopy now requires Craft 3.7 or later

### Fixed
- Fixed compatibility for new Craft 3.7 draft system ([#28](https://github.com/Goldinteractive/craft3-sitecopy/issues/28))

## 0.6.5 - 2021-11-23
### Changed
- Better German translations

## 0.6.4 - 2021-04-21
### Added
- Ability to copy asset fields

## 0.6.3 - 2021-01-11
### Fixed
- Fixed an error that was thrown on copy of a craft commerce product

## 0.6.2 - 2020-09-02
### Fixed
- Fixed an error that would be thrown when copying a deactivated entry

## 0.6.1 - 2020-08-27
### Fixed
- Possible error when trying to copy global set ([#23](https://github.com/Goldinteractive/craft3-sitecopy/issues/23))

## 0.6.0 - 2020-08-13
### Added
- Ability to copy global sets

### Changed
- Automatic copy: Renamed current OR implementation to XOR and added new non-breaking "OR" check method.

### Fixed
- Deactivated fields now get copied to the target site too ([#21](https://github.com/Goldinteractive/craft3-sitecopy/issues/21))

## 0.5.3 - 2020-08-10
### Fixed
- Fixed an issue where unchanged neo blocks on the target site would be wiped ([#19](https://github.com/Goldinteractive/craft3-sitecopy/issues/19))

## 0.5.2 - 2020-04-28
### Fixed
- Fixed a bug where integer site ids would break the plugin functionality ([#13](https://github.com/Goldinteractive/craft3-sitecopy/issues/13))

## 0.5.1 - 2020-03-02
### Fixed
- Craft 3.4 compatibility

## 0.5.0 - 2020-02-05
### Added
- Possibility to choose what fields you want to copy

## 0.4.1 - 2019-12-06
### Fixed
- Fixed method parameter type "object" to ensure PHP 7.1 compatibility

## 0.4.0 - 2019-11-04
### Added
- Possibility to copy to multiple sites at once
- Compatibility for commerce products

### Changed
- Outsource element syncing to queue

