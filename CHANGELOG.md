# Release Notes for Inline Editor

## 1.8.0 - 2026-09-11

### Added
- Craft 5 **Link** fields (the replacement for URL fields since Craft 5.3) can be edited inline when they hold a URL, email or phone value. Links to elements are rendered read-only.
- Per-field CKEditor configuration: every inline CKEditor now uses the toolbar, heading levels, config options (JSON, JavaScript or a `config/ckeditor/` file) and custom styles of the field being edited, on craftcms/ckeditor 3.x, 4.x and 5.x.
- The loaded CKEditor build is verified against the field's toolbar: items whose plugins the build does not include, and control-panel-only items (`insertImage`, `createEntry`), are dropped and reported in the browser console with the reason.
- Settings to point the frontend at a self-hosted CKEditor 5 build (`ckeditorBuildUrl`, `ckeditorBuildCssUrl`, `ckeditorLicenseKey`), also configurable via `config/inline-editor.php`.
- Nested entries (craftcms/ckeditor 4.x/5.x) are protected: CKEditor content containing `<craft-entry>` is rendered read-only, and saves that would drop or inject nested entries are rejected.
- The save response for CKEditor fields now includes `rawValue` (the stored content) next to `value` (the rendered content); the `inline-editor:save` event exposes both.
- Unit tests (PHPUnit + Node) and an integration test harness for real Craft installs (`tests/`).

### Changed
- craftcms/ckeditor 5.x is supported. It removed the shared CKEditor configs (`Plugin::getCkeConfigs()`) the plugin relied on; configuration is now resolved from the field with version-specific checks instead of a version-agnostic call that failed silently.
- The frontend loads the CKEditor build shipped with craftcms/ckeditor 5.x (same version and plugins as the control panel, no third-party requests, `GPL` licence key like Craft's own editor). For craftcms/ckeditor 3.x/4.x, whose DLL build cannot be reused outside the control panel, the CDN build is pinned to 43.3.1, the last release the CKEditor CDN serves without a licence key.
- The "CKEditor Config" plugin setting is now an explicit override for craftcms/ckeditor 3.x/4.x ("None" = use each field's own config, the new default). On 5.x it is ignored with a logged warning and cleared when the settings are saved.
- Toolbar item `sourceEditing` is only offered to users the field allows (same rule as the control panel).
- CKEditor fields are edited from the stored content (reference tags intact), and the rendered content is only used for display. Previously the rendered content was loaded into the editor, which turned reference tags into fixed URLs on save.
- The CKEditor field type is now detected with `instanceof craft\ckeditor\Field` instead of a class-name substring match.
- Upload folders for Assets fields are resolved through Craft's own `Assets::resolveDynamicPathToFolderId()`, which honours "Restrict assets to a single location" (previously the restricted location was ignored and a non-existent property was read).
- Configuration problems (missing config file, deleted shared config, ignored override, unusable build) are logged as actionable warnings instead of being swallowed.
- `craftcms/cms` requirement raised to `^4.3.0|^5.0.0` (element authorisation API).

### Fixed
- Saving a CKEditor field before the editor had finished loading submitted an empty value.
- Discarding an edit while CKEditor was still loading left an orphaned editor instance behind.
- Fields whose heading levels were all disabled fell back to CKEditor's default headings.
- Saving a Tags or Assets field through the text save endpoint caused a PHP warning instead of a clear 400 error.
- Adding a tag whose title already exists in the group now reuses that tag instead of failing.
- Titles of entry types without a Title field are rendered read-only instead of being wiped on save.

### Security
- Every save is authorised per element with Craft's permission checks (`Elements::canSave()`), so members of an allowed user group can only edit content they may save in the control panel.
- Asset uploads and deletions triggered from the frontend require the corresponding volume permissions (`Elements::canSave()` / `canDelete()` on the asset).

## 1.7.0 and earlier

See the Git history for changes before 1.8.0.
