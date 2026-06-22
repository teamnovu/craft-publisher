# Changelog

## 5.1.0 - 2026-06-18
### Added

- Added `EVENT_AFTER_PUBLISH_ENTRY` event on the `Entries` service, fired after each successful scheduled publication. Useful for triggering server-side cache purges. The event carries the canonical entry, the applied draft (or `null` for non-draft schedules), and the `EntryPublish` schedule record. See `EntryPublishedEvent`. ([#8](https://github.com/neustadt-swiss/craft-publisher/issues/8))

### Fixed
- Fixed redundant DB queries in `EntryPublish::getDraft()` and `getEntry()`: when the result was found, it was not cached, causing a new query on every subsequent call
- Fixed fatal error in `EntriesController::actionSave()` when the entry's section had been deleted (`$entry->getSection()` could return null)
- Fixed fatal error in `EntriesController::actionDelete()` when both the draft and the entry were null (entry deleted), causing a null dereference on `getCpEditUrl()`
- Fixed unclosed database transaction in `Entries::saveEntryPublish()` when `saveElement()` returned false without throwing an exception
- Fixed infinite retry loop in `Entries::publishDueEntries()`: if both the entry and draft of a scheduled record were not found (e.g. entry deleted), the record was never cleaned up and kept being retried on every cron run. Orphaned records are now deleted with an error log entry

## 5.0.3 - 2026-01-08
### Fixed
- Fixed metabox styling issues in Craft CMS 5.8+ - improved layout, spacing, and visual consistency with Craft's native UI

## 5.0.2 - 2026-01-08
### Improved
- After deleting a scheduled publication, now redirects to the draft edit page (if the scheduled item was a draft) instead of always redirecting to the main entry

## 5.0.1 - 2026-01-07
### Fixed
- Fixed critical bug where scheduled drafts were not being published when the parent entry was already published
- Improved error handling in publishDueEntries() method - now only deletes scheduled publish entries after successful publication
- Added comprehensive logging for draft publication success and failure cases
- Wrapped applyDraft() calls in try-catch blocks to prevent silent failures

## 4.0.1 - 2024-06-17
### Fixed
- Fixed a bug with matrix blocks if they weren't displayed inline

## 4.0.0 - 2024-05-27
### Changed
- Updated dependencies for craft 5

## 3.0.1 - 2023-04-17
### Fixed
- Fixed a bug where non-admin users were unable to publish drafts ([#1](https://github.com/neustadt-swiss/craft-publisher/issues/1))

## 3.0.0 - 2022-11-22
### Added
- Support for craft 4!

### Changed
- Its now possible to have both a postDate in the future and an expiration date in the future at the same time
- Its now possible to publish a draft which is disabled for the currently viewed site
- Changed the plugin handle and name to Publisher X

## 2.1.0 - 2022-03-30
### Fixed
- Fixed a deprecation error for craft 3.7
- Fixed the entry expire and publishing workflow (previously a draft save would also invalidate the cache)

### Changed
- Show the side panel when editing drafts too (previously it worked only on entry edit view)
- The plugin now requires craft 3.7 or later

## 2.0.6 - 2021-05-12
### Fixed
- Fixed a bug where drafts which didn't exist on the primary site couldn't be published

## 2.0.5 - 2020-05-18
### Fixed
- Fixed installation migration

### Changed
- Changed UI to resemble new CP UI

## 2.0.4 - 2020-04-23
### Fixed
- Error on plugin re-install and upgrade from craft 2

## 2.0.3 - 2020-03-02
### Fixed
- Exception when entry due to publish has been deleted

## 2.0.2 - 2019-09-16
### Fixed
- Wrong permission check that resulted in non-admin users not having permission to publish entries

## 2.0.1 - 2019-08-21
### Fixed
- Allow anonymous for publish route

## 2.0.0 - 2019-07-26
### Changed
- Make Craft 3.2 compatible
