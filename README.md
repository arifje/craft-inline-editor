# Inline Editor for Craft CMS

Edit Craft entry titles and field values directly on the rendered front-end page. When an administrator is logged in, an edit icon appears on any value wrapped with the plugin's Twig helper; clicking it swaps the value out for an inline form, and clicking **Save** persists the change via Craft's element API.

Compatible with **Craft 4** and **Craft 5**.

Supported field types out of the box:

- Element **title**
- **Plain Text** (`craft\fields\PlainText`) — input or textarea, auto-detected
- **URL** (`craft\fields\Url`) — `type="url"` input
- **CKEditor** (`craft\ckeditor\Field`) — full CKEditor 5 editor, lazy-loaded from CDN
- **Tags** (`craft\fields\Tags`) — chip-based editor with autocomplete search and new-tag creation

## Installation

Require the plugin with Composer and install it from Craft's control panel, or via CLI:

```bash
composer require arifje/craft-inline-editor
./craft plugin/install inline-editor
```

## Usage

Wherever you would render a field on the front-end, swap the value for the `inlineEditable()` Twig function.

```twig
{# Replace this... #}
<h1>{{ entry.title }}</h1>

{# ...with this. #}
<h1>{{ inlineEditable(entry, 'title', { tag: 'span' }) }}</h1>

{# Plain text custom field #}
<p>{{ inlineEditable(entry, 'summary') }}</p>

{# URL field #}
<a href="{{ entry.website }}">{{ inlineEditable(entry, 'website') }}</a>

{# CKEditor field — renders inside a <div> by default #}
{{ inlineEditable(entry, 'body') }}

{# Tags field — renders as chips, click to open chip editor with autocomplete #}
{{ inlineEditable(entry, 'tags') }}
```

For anonymous visitors (and non-admin users) the function falls through to a plain, properly HTML-encoded value — there's no edit icon, no extra markup, and no JavaScript loaded.

### Options

The third argument is a map of options:

| Option        | Default              | Description                                                                                  |
|---------------|----------------------|----------------------------------------------------------------------------------------------|
| `tag`         | `span` / `div`*      | HTML tag used for the wrapper. CKEditor and Tags fields default to `div`; everything else to `span`. |
| `class`       | `''`                 | Extra CSS class names appended to the wrapper.                                              |
| `attributes`  | `{}`                 | Extra attributes, e.g. `{ id: 'main-title' }`.                                              |
| `inputType`   | auto                 | For plain text fields, force `'input'` or `'textarea'`. Auto-detected from value otherwise. |
| `placeholder` | `''`                 | Empty-state placeholder shown when the field has no value.                                  |

### Tags editor interaction

When the admin clicks the edit icon on a Tags field:

- Existing tags appear as chips with a **×** remove button.
- Typing in the input searches existing tags in the group (debounced autocomplete).
- Select a result to add it, or choose **Create "…"** to create a brand-new tag on save.
- **Backspace** on an empty input removes the last chip.
- Arrow keys navigate the dropdown; **Enter** confirms the highlighted item or adds the typed text.

### JavaScript events

The wrapper element dispatches `inline-editor:save` and `inline-editor:cancel` custom events when the user finishes an edit. They bubble, and `event.detail.editor` holds the editor instance.

```js
// Plain text / URL / CKEditor / title fields
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

## How it works

- A small Twig extension renders a wrapper `<span>`/`<div>` with `data-element-id`, `data-site-id`, `data-field` and `data-type` attributes. The wrapper is only added for site requests where the current user passes `Craft::$app->user->getIsAdmin()`.
- A vanilla-JS asset bundle scans for those wrappers, draws an edit icon, and binds the swap-to-form, save and discard interactions.
- Save POSTs to `actions/inline-editor/default/save`, which double-checks the admin permission, enforces Craft's CSRF token, and writes the value via `Craft::$app->elements->saveElement()`. Standard Craft field validation applies.
- Special characters and emoji are preserved end-to-end (UTF-8 transport, `htmlspecialchars` with `ENT_SUBSTITUTE` on output, raw HTML for CKEditor).

## Security model

- All edits require an authenticated administrator. Non-admin users see a plain rendered value with no edit affordances.
- The save action enforces Craft's CSRF token and rejects non-JSON-accepting requests.
- Field validation runs through `saveElement()`, so existing validators on the field still fire.

## License

MIT — see [LICENSE.md](LICENSE.md).
