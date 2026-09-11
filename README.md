# Inline Editor for Craft CMS

Edit Craft entry titles and field values directly on the rendered front-end page. When a permitted user is logged in, an edit icon appears on any value wrapped with the plugin's Twig helper; clicking it swaps the value out for an inline form, and clicking **Save** persists the change through Craft's element API.

## Compatibility

| Craft CMS | craftcms/ckeditor | CKEditor build used on the frontend |
|-----------|-------------------|-------------------------------------|
| 4.3+      | 3.x               | CKEditor CDN 43.3.1 (`ckeditor5.umd.js`), or a self-hosted build you configure |
| 5.0+      | 4.x               | CKEditor CDN 43.3.1, or a self-hosted build you configure |
| 5.9+      | 5.x               | The build shipped with the CKEditor plugin, published through Craft's asset manager (same version and plugins as the control panel, no third-party requests) |

The CKEditor plugin is optional; without it, CKEditor fields are simply not available for inline editing.

Why the CDN for 3.x/4.x: those plugin versions ship a DLL build that only works inside the control panel, and the CKEditor CDN refuses the open-source `GPL` licence key from 44.0.0 on. 43.3.1 is the last CDN release that needs no key. If you prefer not to load anything from the CDN, point the plugin at a self-hosted build (see [Editor build](#editor-build)).

Supported field types out of the box:

- Element **title** (for entry types with a Title field)
- **Plain Text** (`craft\fields\PlainText`) — input or textarea, auto-detected
- **URL** (`craft\fields\Url`, Craft 4) and **Link** (`craft\fields\Link`, Craft 5.3+) — URL / email / phone values; links to elements are rendered read-only
- **CKEditor** (`craft\ckeditor\Field`) — full CKEditor 5 editor, lazy-loaded when an edit starts
- **Tags** (`craft\fields\Tags`) — chip-based editor with autocomplete search and new-tag creation
- **Assets** (`craft\fields\Assets`) — replace or remove a single image on hover

## Installation

Require the plugin with Composer and install it from Craft's control panel, or via CLI:

```bash
composer require arifje/craft-inline-editor
./craft plugin/install inline-editor
```

Then choose the user groups that may use the inline editor under **Settings → Plugins → Inline Editor**. Administrators always have access.

## Usage

Wherever you would render a field on the front-end, swap the value for the `inlineEditable()` Twig function.

```twig
{# Replace this... #}
<h1>{{ entry.title }}</h1>

{# ...with this. #}
<h1>{{ inlineEditable(entry, 'title', { tag: 'span' }) }}</h1>

{# Plain text custom field #}
<p>{{ inlineEditable(entry, 'summary') }}</p>

{# URL / Link field #}
<a href="{{ entry.website }}">{{ inlineEditable(entry, 'website') }}</a>

{# CKEditor field — renders inside a <div> by default #}
{{ inlineEditable(entry, 'body') }}

{# Tags field — renders as chips, click to open chip editor with autocomplete #}
{{ inlineEditable(entry, 'tags') }}

{# Assets field — the display markup comes from the template #}
{{ inlineEditable(entry, 'image', { innerHtml: '<img src="' ~ entry.image.one().url ~ '" alt="">' }) }}
```

For anonymous visitors and users who may not edit, the function falls through to a plain, properly HTML-encoded value — no edit icon, no extra markup, no JavaScript loaded. `canInlineEdit()` returns whether the current user may use the inline editor, for templates that need to branch on it.

### Options

The third argument is a map of options:

| Option        | Default              | Description                                                                                  |
|---------------|----------------------|----------------------------------------------------------------------------------------------|
| `tag`         | `span` / `div`*      | HTML tag used for the wrapper. CKEditor, Tags and Assets fields default to `div`; everything else to `span`. |
| `class`       | `''`                 | Extra CSS class names appended to the wrapper.                                              |
| `attributes`  | `{}`                 | Extra attributes, e.g. `{ id: 'main-title' }`.                                              |
| `inputType`   | auto                 | For plain text fields, force `'input'` or `'textarea'`. Auto-detected from value otherwise. |
| `placeholder` | `''`                 | Empty-state placeholder shown when the field has no value.                                  |
| `innerHtml`   | field value          | Custom display HTML (e.g. with Twig filters applied). CKEditor fields still edit the stored content. |

### Tags editor interaction

When the user clicks the edit icon on a Tags field:

- Existing tags appear as chips with a **×** remove button.
- Typing in the input searches existing tags in the group (debounced autocomplete).
- Select a result to add it, or choose **Create "…"** to create a brand-new tag on save. A tag whose title already exists in the group is reused.
- **Backspace** on an empty input removes the last chip.
- Arrow keys navigate the dropdown; **Enter** confirms the highlighted item or adds the typed text.

### JavaScript events

The wrapper element dispatches `inline-editor:save` and `inline-editor:cancel` custom events when the user finishes an edit. They bubble, and `event.detail.editor` holds the editor instance.

```js
// Plain text / URL / title fields: e.detail.value is the saved text.
// CKEditor fields: e.detail.value is the rendered HTML, e.detail.rawValue the stored content.
document.addEventListener('inline-editor:save', (e) => {
    console.log('saved', e.detail.editor.field, e.detail.value);
});

// Tags field
document.addEventListener('inline-editor:save', (e) => {
    if (e.detail.editor.type === 'tags') {
        console.log('tags saved', e.detail.tags); // [{id, title}, …]
    }
});
```

### Re-scanning dynamic content

If you inject new editable nodes after page load (e.g. from a Vue/React island), call:

```js
window.InlineEditor.init();
```

It is idempotent — already-initialised nodes are skipped.

## CKEditor configuration

Each inline CKEditor uses the configuration of **the field being edited**, resolved on the server and registered on the page per field (keyed by field UID):

- **craftcms/ckeditor 5.x** (Craft 5.9+): the field's toolbar, heading levels, config options — JSON, JavaScript, or a `.js`/`.json` file from `config/ckeditor/` — and custom styles (inline or a `.css` file).
- **craftcms/ckeditor 3.x/4.x**: the shared config selected on the field (Settings → CKEditor): toolbar, heading levels, JSON/JavaScript config options and custom styles. A field without a selected config uses the CKEditor plugin's defaults, exactly like the control panel.

JavaScript config options are evaluated in the browser as a real function, so they can return plugin classes and callbacks. JSON options and JavaScript options are mutually exclusive, as in Craft. The `sourceEditing` button is only offered to the users the field allows.

### What is not available inline

The frontend editor is a standard CKEditor 5 build without Craft's control-panel integrations, so a few toolbar items are dropped (a message in the browser console says why):

- `insertImage` — inserting images needs the control panel asset picker; existing images stay editable.
- `createEntry` — nested entries can only be managed in the control panel. CKEditor content that already contains nested entries is rendered **read-only** inline, and saves that would drop or inject nested entries are rejected.
- Items provided by third-party CKEditor packages registered in the control panel.
- Items whose plugin the loaded build does not include (for example `fullscreen` and `bookmark` with the CDN 43.3.1 build).

Attributes written by Craft's "Advanced Link Fields" (`target`, `title`, `id`, `rel`, `aria-label`, `download`, `class`) are preserved through General HTML Support. When the field's config options enable link decorators (`link.addTargetToExternalLinks` or `link.decorators`), `target` and `rel` are governed by those decorators instead, as CKEditor requires. Reference tags such as `{entry:12@1:url}` stay intact because the editor works on the stored content rather than the rendered output.

### Diagnostics

Configuration problems are never swallowed. Actionable warnings are logged to Craft's log (prefixed with `Inline Editor:`) for, among others:

- a `config/ckeditor/` file selected on a field that cannot be read or is not valid JSON;
- a field that references a shared CKEditor config that no longer exists;
- the legacy "CKEditor Config" override pointing at a config that no longer exists, or being set on craftcms/ckeditor 5.x;
- the CKEditor plugin's bundled build not being publishable (the CDN build is used instead).

The browser console lists the toolbar items that were dropped for a field and why, once per field.

### Plugin settings

| Setting | Purpose |
|---------|---------|
| Allowed User Groups | Groups whose members may use the inline editor. Every save is still checked against the user's own permissions for the element. |
| CKEditor Config Override (3.x/4.x only) | Leave on "None" to use each field's own config. Select a shared config to apply it to every inline CKEditor instead. Ignored, with a logged warning, on craftcms/ckeditor 5.x. |
| HTML Purifier Config | An extra `config/htmlpurifier/*.json` config applied when saving CKEditor content, on top of the field's own purification. |
| CKEditor build URL / CSS URL / licence key | Use a self-hosted CKEditor 5 build instead of the default (see below). |

All settings can also be set in `config/inline-editor.php`:

```php
<?php
return [
    'allowedGroupIds' => [3],
    'ckeditorBuildUrl' => '/assets/ckeditor5/ckeditor5.umd.js',
    'ckeditorBuildCssUrl' => '/assets/ckeditor5/ckeditor5.css',
    'ckeditorLicenseKey' => 'GPL',
];
```

### Editor build

By default the frontend loads:

- with craftcms/ckeditor 5.x: `lib/ckeditor5.umd.js` and `lib/ckeditor5.css` from the CKEditor plugin's own asset bundle, published to `cpresources` like any Craft asset, with the `GPL` licence key Craft's editor uses;
- with craftcms/ckeditor 3.x/4.x: `https://cdn.ckeditor.com/ckeditor5/43.3.1/ckeditor5.umd.js` and its stylesheet.

To self-host (for a Content Security Policy that blocks the CDN, or to pick a version), download a CKEditor 5 build (`ckeditor5.umd.js` exposes `window.CKEDITOR`; legacy classic builds exposing `window.ClassicEditor` also work) and set `ckeditorBuildUrl`, `ckeditorBuildCssUrl` and, for builds from 44.0.0 on, `ckeditorLicenseKey` (`GPL` for the open-source licence, self-hosted only). The plugin verifies per toolbar item that the loaded build provides the required plugins.

## How it works

- A small Twig extension renders a wrapper `<span>`/`<div>` with `data-element-id`, `data-site-id`, `data-field`, `data-field-uid` and `data-type` attributes. The wrapper is only added for site requests where the current user may use the inline editor.
- A vanilla-JS asset bundle scans for those wrappers, draws an edit icon, and binds the swap-to-form, save and discard interactions. CKEditor is loaded once, on the first edit of a CKEditor field.
- Save POSTs to `actions/inline-editor/default/save`, which re-checks the plugin permission, authorises the element through Craft's own permission checks, enforces Craft's CSRF token, and writes the value via `Craft::$app->elements->saveElement()`. Standard Craft field validation and the field's own HTML purification apply.
- Special characters and emoji are preserved end-to-end (UTF-8 transport, `htmlspecialchars` with `ENT_SUBSTITUTE` on output, raw HTML for CKEditor).

## Security model

- All edits require a logged-in user who is an administrator or a member of an allowed user group. Everyone else sees a plain rendered value with no edit affordances.
- Each save is authorised per element with `Craft::$app->elements->canSave()`, so section, site and peer permissions apply exactly as in the control panel. Asset replacement and removal additionally require the volume permissions (`Elements::canSave()` / `canDelete()` on the asset).
- The actions enforce Craft's CSRF token and reject non-JSON-accepting requests.
- Field validation runs through `saveElement()`, so existing validators on the field still fire; CKEditor content goes through the field's HTML Purifier configuration, plus the optional plugin-level purifier config.
- CKEditor content containing nested entries is never editable inline, and submitted nested-entry markup is rejected.

## Upgrading to 1.8.0

- **CKEditor configuration is now per field.** Inline editors use the configuration of the field being edited. If you had selected a config under "CKEditor Config" in the plugin settings, that config is still applied to every inline editor (the setting became an explicit override); set it to "None" to use each field's own config.
- **craftcms/ckeditor 5.x** is supported. The override setting has no effect there (shared configs no longer exist); the settings page tells you if a stale override is stored and clears it when you save.
- **The frontend editor build changed.** With craftcms/ckeditor 5.x the build shipped with the CKEditor plugin is used; with 3.x/4.x the CDN build moved from the classic 41.4.2 build to the 43.3.1 UMD build, which includes the plugins the field toolbars can reference. If your Content Security Policy allow-lists the CDN, it still needs `cdn.ckeditor.com` on 3.x/4.x only.
- **Craft 4.3 or later** is now required.
- The CKEditor save response and `inline-editor:save` event gained `rawValue` (stored content) next to `value` (rendered content).

## Testing

```bash
composer install
composer test        # PHPUnit (config resolution, content guards) + Node (toolbar/plugin verification, config building)
```

`tests/integration/` contains the fixture and HTTP scripts used to verify real Craft installs (Craft 4 + CKEditor 3.x, Craft 5 + CKEditor 4.x and 5.x); see its README.

## License

MIT — see [LICENSE.md](LICENSE.md).
