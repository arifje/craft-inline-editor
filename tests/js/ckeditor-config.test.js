'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadInlineEditor, fakeNamespaceBuild, fakePrebuiltBuild, ALL_PLUGINS } = require('./helpers');

const internal = loadInlineEditor();

test('detectBuild recognises UMD namespace builds and legacy classic builds', () => {
    const ns = { CKEDITOR: { ClassicEditor: function () {}, version: '48.0.1' } };
    assert.equal(internal.detectBuild(ns).kind, 'namespace');
    assert.equal(internal.detectBuild(ns).version, '48.0.1');

    const classic = { ClassicEditor: function () {}, CKEDITOR_VERSION: '41.4.2' };
    assert.equal(internal.detectBuild(classic).kind, 'prebuilt');
    assert.equal(internal.detectBuild(classic).version, '41.4.2');

    assert.equal(internal.detectBuild({}), null);
});

test('resolveToolbar keeps supported items and loads the matching plugins', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS);
    const result = internal.resolveToolbar(['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'insertTable', 'undo', 'redo'], build, 'test build');

    assert.deepEqual(result.items, ['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'insertTable', 'undo', 'redo']);
    assert.deepEqual(result.dropped, []);
    for (const name of ['Essentials', 'Paragraph', 'GeneralHtmlSupport', 'Heading', 'Bold', 'Italic', 'Link', 'AutoLink', 'List', 'ListProperties', 'Table', 'TableUI']) {
        assert.ok(result.pluginNames.includes(name), name + ' should be loaded');
    }
    assert.ok(!result.pluginNames.includes('Underline'), 'plugins for absent buttons are not loaded');
});

test('resolveToolbar drops items whose plugins the build lacks, with a reason', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS.filter((n) => n !== 'Fullscreen' && n !== 'Bookmark'), '43.3.1');
    const result = internal.resolveToolbar(['bold', '|', 'bookmark', 'fullscreen', '|', 'italic'], build, 'cdn, CKEditor 43.3.1');

    assert.deepEqual(result.items, ['bold', '|', 'italic']);
    assert.deepEqual(result.dropped.map((d) => d.item), ['bookmark', 'fullscreen']);
    assert.match(result.dropped[0].reason, /does not include the Bookmark plugin/);
    assert.match(result.dropped[1].reason, /cdn, CKEditor 43\.3\.1/);
});

test('resolveToolbar drops control-panel-only items but keeps image plugins for existing images', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS);
    const result = internal.resolveToolbar(['insertImage', 'createEntry', 'bold'], build, 'test');

    assert.deepEqual(result.items, ['bold']);
    assert.deepEqual(result.dropped.map((d) => d.item), ['insertImage', 'createEntry']);
    assert.match(result.dropped[0].reason, /control panel/);
    assert.ok(result.pluginNames.includes('Image'));
    assert.ok(result.pluginNames.includes('ImageCaption'));
});

test('resolveToolbar reports unknown (third-party) toolbar items', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS);
    const result = internal.resolveToolbar(['bold', 'myCustomButton'], build, 'test');

    assert.deepEqual(result.items, ['bold']);
    assert.equal(result.dropped.length, 1);
    assert.match(result.dropped[0].reason, /no known CKEditor plugin/);
});

test('resolveToolbar verifies against builtinPlugins for legacy classic builds', () => {
    const build = fakePrebuiltBuild(['Essentials', 'Paragraph', 'Bold', 'Italic', 'Heading', 'Link', 'AutoLink', 'LinkImage', 'Image']);
    const result = internal.resolveToolbar(['heading', 'bold', 'underline', 'link'], build, 'classic');

    assert.deepEqual(result.items, ['heading', 'bold', 'link']);
    assert.equal(result.dropped[0].item, 'underline');
});

test('cleanSeparators removes leading, trailing and duplicate separators', () => {
    assert.deepEqual(internal.cleanSeparators(['|', 'bold', '|', '|', 'italic', '-', '|']), ['bold', '|', 'italic']);
});

test('buildHeadingConfig mirrors Craft: paragraph plus the allowed levels only', () => {
    assert.deepEqual(internal.buildHeadingConfig([2, 3]).options.map((o) => o.model), ['paragraph', 'heading2', 'heading3']);
    assert.deepEqual(internal.buildHeadingConfig(false).options.map((o) => o.model), ['paragraph']);
    assert.deepEqual(internal.buildHeadingConfig([]).options.map((o) => o.model), ['paragraph']);
    assert.deepEqual(internal.buildHeadingConfig(['9', 'x', 1]).options.map((o) => o.model), ['paragraph', 'heading1']);
});

test('buildCKEditorConfig uses the field config, verified toolbar and licence key', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS);
    const fieldConfig = { toolbar: ['heading', 'bold', 'fullscreen'], headingLevels: [2], options: null, handle: 'body', name: 'Body' };
    const result = internal.buildCKEditorConfig(fieldConfig, build, { licenseKey: 'GPL', uiLanguage: 'nl', contentLanguage: 'nl-NL', source: 'craft-bundled' });

    assert.deepEqual(result.config.toolbar.items, ['heading', 'bold', 'fullscreen']);
    assert.equal(result.config.licenseKey, 'GPL');
    assert.deepEqual(result.config.language, { ui: 'nl', content: 'nl-NL' });
    assert.deepEqual(result.config.heading.options.map((o) => o.model), ['paragraph', 'heading2']);
    assert.ok(result.config.plugins.includes(build.ns.Heading));
    assert.ok(result.config.plugins.includes(build.ns.Fullscreen));
    assert.ok(!result.config.plugins.includes(build.ns.Underline));
    assert.equal(result.config.htmlSupport.allow[0].name, 'a');
    assert.deepEqual(result.dropped, []);
});

test('buildCKEditorConfig falls back to the default toolbar when no field config is registered', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS);
    const result = internal.buildCKEditorConfig(null, build, {});

    assert.deepEqual(result.config.toolbar.items, internal.CK_DEFAULT_TOOLBAR);
    assert.equal(result.config.licenseKey, undefined);
    assert.deepEqual(result.config.heading.options.map((o) => o.model), ['paragraph']);
});

test('buildCKEditorConfig merges JSON options like the control panel (toolbar items still win)', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS);
    const fieldConfig = {
        toolbar: ['heading', 'bold'],
        headingLevels: [2, 3],
        options: {
            placeholder: 'Type…',
            toolbar: { items: ['underline'], shouldNotGroupWhenFull: true },
            heading: { options: [
                { model: 'heading2', view: 'h2', title: 'Section', class: 'custom' },
                { model: 'heading1', view: 'h1', title: 'Not allowed' },
                { model: 'lead', view: { name: 'p', classes: 'lead' }, title: 'Lead' },
            ] },
            htmlSupport: { allow: [{ name: 'div', classes: true }] },
            language: 'de',
        },
    };
    const result = internal.buildCKEditorConfig(fieldConfig, build, { uiLanguage: 'en', contentLanguage: 'en' });

    assert.equal(result.config.placeholder, 'Type…');
    assert.deepEqual(result.config.toolbar, { items: ['heading', 'bold'], shouldNotGroupWhenFull: true });
    assert.deepEqual(result.config.heading.options.map((o) => o.title), ['Paragraph', 'Section', 'Heading 3', 'Lead']);
    assert.deepEqual(result.config.htmlSupport.allow.map((r) => r.name), ['a', 'div']);
    assert.deepEqual(result.config.language, { ui: 'de', content: 'en' });
});

test('buildCKEditorConfig evaluates the JavaScript config and appends extraPlugins', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS);
    const MyPlugin = function () {};
    const fieldConfig = {
        toolbar: ['bold'],
        headingLevels: false,
        js: () => ({ extraPlugins: [MyPlugin, 'not-a-plugin'], link: { addTargetToExternalLinks: true } }),
    };
    const result = internal.buildCKEditorConfig(fieldConfig, build, {});

    assert.ok(result.config.plugins.includes(MyPlugin));
    assert.ok(result.config.plugins.includes(build.ns.Bold));
    assert.equal(result.config.extraPlugins, undefined);
    assert.deepEqual(result.config.link, { addTargetToExternalLinks: true });
    assert.deepEqual(result.warnings, []);
});

test('buildCKEditorConfig survives a broken JavaScript config and reports it', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS);
    const result = internal.buildCKEditorConfig({ toolbar: ['bold'], js: () => { throw new Error('boom'); } }, build, {});

    assert.deepEqual(result.config.toolbar.items, ['bold']);
    assert.equal(result.warnings.length, 1);
    assert.match(result.warnings[0], /boom/);
});

test('lookupFieldConfig finds configs by UID, then by handle', () => {
    globalThis.InlineEditorCKConfigs = {
        'uid-1': { handle: 'body', toolbar: ['bold'] },
        'uid-2': { handle: 'intro', toolbar: ['italic'] },
    };
    assert.deepEqual(internal.lookupFieldConfig('uid-2', 'intro').toolbar, ['italic']);
    assert.deepEqual(internal.lookupFieldConfig(null, 'body').toolbar, ['bold']);
    assert.equal(internal.lookupFieldConfig('uid-9', 'missing'), null);
});

test('link attributes are kept by General HTML Support, except target/rel when link decorators are active', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS);

    const plain = internal.buildCKEditorConfig({ toolbar: ['link'] }, build, {});
    assert.deepEqual(plain.config.htmlSupport.allow[0].attributes, ['target', 'title', 'id', 'rel', 'aria-label', 'download']);

    const withDecorator = internal.buildCKEditorConfig({ toolbar: ['link'], js: () => ({ link: { addTargetToExternalLinks: true } }) }, build, {});
    assert.deepEqual(withDecorator.config.htmlSupport.allow[0].attributes, ['title', 'id', 'aria-label', 'download']);

    const withManual = internal.buildCKEditorConfig({ toolbar: ['link'], options: { link: { decorators: { openInNewTab: { mode: 'manual', label: 'New tab', attributes: { target: '_blank' } } } } } }, build, {});
    assert.deepEqual(withManual.config.htmlSupport.allow[0].attributes, ['title', 'id', 'aria-label', 'download']);

    const merged = internal.buildCKEditorConfig({ toolbar: ['link'], options: { htmlSupport: { allow: [{ name: 'span', classes: true }] } } }, build, {});
    assert.deepEqual(merged.config.htmlSupport.allow.map((r) => r.name), ['a', 'span']);
});

// ── Regression: plugincollection-soft-required (1.8.1) ──────────────────────

function pluginClass(name, requires) {
    const plugin = function () {};
    plugin.pluginName = name;
    if (requires) { Object.defineProperty(plugin, 'requires', { get: () => requires }); }
    return plugin;
}

test('link toolbar no longer pulls in LinkImage; image plugins do', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS);
    const linkOnly = internal.resolveToolbar(['heading', '|', 'bold', 'italic', 'link'], build, 'test');
    assert.ok(linkOnly.pluginNames.includes('Link'));
    assert.ok(!linkOnly.pluginNames.includes('LinkImage'), 'LinkImage soft-requires ImageEditing in CKEditor < 44');

    const withImages = internal.resolveToolbar(['link', 'insertImage'], build, 'test');
    assert.ok(withImages.pluginNames.includes('LinkImage'));
    assert.ok(withImages.pluginNames.includes('Image'));
});

test('satisfySoftRequirements adds a soft-required plugin when the build provides it', () => {
    const ImageEditing = pluginClass('ImageEditing');
    const LinkImageEditing = pluginClass('LinkImageEditing', ['ImageEditing']);
    const LinkImage = pluginClass('LinkImage', [LinkImageEditing]);
    const ns = { ImageEditing, LinkImageEditing, LinkImage };

    const result = internal.satisfySoftRequirements([LinkImage], ns, 'test');
    assert.ok(result.plugins.includes(ImageEditing), 'ImageEditing added from the namespace');
    assert.deepEqual(result.droppedItems, []);
    assert.deepEqual(result.warnings, []);
});

test('satisfySoftRequirements drops the toolbar items whose plugin cannot be satisfied', () => {
    const StyleEditing = pluginClass('StyleEditing', ['PremiumOnly']);
    const Style = pluginClass('Style', [StyleEditing]);
    const Bold = pluginClass('Bold');
    const ns = { Style, StyleEditing, Bold };

    const result = internal.satisfySoftRequirements([Style, Bold], ns, 'cdn, CKEditor 43.3.1');
    assert.deepEqual(result.plugins, [Bold]);
    assert.deepEqual(result.droppedItems.map((d) => d.item), ['style']);
    assert.match(result.droppedItems[0].reason, /StyleEditing requires PremiumOnly/);
    assert.match(result.droppedItems[0].reason, /43\.3\.1/);
});

test('satisfySoftRequirements keeps user plugins but warns when their requirement is unmet', () => {
    const Custom = pluginClass('MyCustomPlugin', ['Nope']);
    const result = internal.satisfySoftRequirements([Custom], {}, 'test');
    assert.deepEqual(result.plugins, []);
    assert.deepEqual(result.droppedItems, []);
    assert.equal(result.warnings.length, 1);
    assert.match(result.warnings[0], /MyCustomPlugin was removed because its plugin MyCustomPlugin requires Nope/);
});

test('buildCKEditorConfig removes toolbar items dropped for unmet soft requirements', () => {
    const build = fakeNamespaceBuild(ALL_PLUGINS.filter((n) => n !== 'GeneralHtmlSupport'));
    // Make Style depend on GHS (as the real build does); GHS is absent from this fake build.
    Object.defineProperty(build.ns.Style, 'requires', { get: () => ['GeneralHtmlSupport'] });
    const result = internal.buildCKEditorConfig({ toolbar: ['bold', '|', 'style', '|', 'italic'], headingLevels: false }, build, { source: 'test' });

    assert.deepEqual(result.config.toolbar.items, ['bold', '|', 'italic']);
    assert.ok(!result.config.plugins.includes(build.ns.Style));
    assert.equal(result.dropped.length, 1);
    assert.equal(result.dropped[0].item, 'style');
});
