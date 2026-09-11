'use strict';

/**
 * Loads inline-editor.js into a bare Node global scope (no DOM) and returns
 * its internal helpers. A fake CKEditor namespace stands in for a UMD build.
 */
const path = require('node:path');

function loadInlineEditor() {
    globalThis.InlineEditorConfig = {};
    globalThis.InlineEditorCKConfigs = {};
    delete require.cache[require.resolve(path.join(__dirname, '..', '..', 'src/web/assets/editor/dist/js/inline-editor.js'))];
    require(path.join(__dirname, '..', '..', 'src/web/assets/editor/dist/js/inline-editor.js'));
    return globalThis.InlineEditor._internal;
}

/** A fake ckeditor5.umd.js namespace exposing the given plugin names. */
function fakeNamespaceBuild(pluginNames, version) {
    const ns = { version: version || '48.0.1' };
    ns.ClassicEditor = function ClassicEditor() {};
    pluginNames.forEach((name) => {
        const plugin = function () {};
        plugin.pluginName = name;
        ns[name] = plugin;
    });
    return { kind: 'namespace', ns, Editor: ns.ClassicEditor, version: ns.version };
}

/** A fake legacy classic build with a fixed plugin set. */
function fakePrebuiltBuild(pluginNames) {
    const Editor = function ClassicEditor() {};
    Editor.builtinPlugins = pluginNames.map((name) => {
        const plugin = function () {};
        plugin.pluginName = name;
        return plugin;
    });
    return { kind: 'prebuilt', ns: null, Editor, version: '41.4.2' };
}

const ALL_PLUGINS = [
    'Essentials', 'Paragraph', 'SelectAll', 'Clipboard', 'Autoformat', 'PasteFromOffice', 'GeneralHtmlSupport', 'HtmlComment',
    'Alignment', 'Link', 'AutoLink', 'LinkImage', 'BlockQuote', 'Bold', 'Bookmark', 'Code', 'CodeBlock', 'List', 'ListProperties',
    'Font', 'FindAndReplace', 'Fullscreen', 'Heading', 'HorizontalLine', 'HtmlEmbed', 'Indent', 'IndentBlock', 'Italic',
    'MediaEmbed', 'PageBreak', 'RemoveFormat', 'SourceEditing', 'Strikethrough', 'Style', 'Subscript', 'Superscript',
    'Table', 'TableCaption', 'TableCellProperties', 'TableProperties', 'TableToolbar', 'TableUI', 'TextPartLanguage',
    'TodoList', 'Underline', 'Image', 'ImageCaption', 'ImageStyle', 'ImageToolbar', 'AutoImage',
];

module.exports = { loadInlineEditor, fakeNamespaceBuild, fakePrebuiltBuild, ALL_PLUGINS };
