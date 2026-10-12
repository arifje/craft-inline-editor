/* Inline Editor — frontend runtime.
   Renders an edit button on every [data-inline-editor] element, handles
   swap-to-form, save (AJAX), and discard. Pure vanilla JS; no jQuery.

   CKEditor fields lazy-load a CKEditor 5 build described by
   window.InlineEditorConfig.ckeditor and use the per-field configuration that
   the server registers in window.InlineEditorCKConfigs (keyed by field UID). */

(function (root) {
    'use strict';

    var EDIT_ICON = '<svg viewBox="0 0 16 16" aria-hidden="true">'
        + '<path d="M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.21.21-.47.364-.756.445l-3.251.93a.75.75 0 0 1-.927-.928l.929-3.25c.081-.286.235-.547.445-.758l8.61-8.609Zm1.414 1.06a.25.25 0 0 0-.354 0L10.811 3.75 12.25 5.189l1.263-1.263a.25.25 0 0 0 0-.354l-1.086-1.085ZM11.189 6.25 9.75 4.811l-6.286 6.287a.25.25 0 0 0-.064.108l-.558 1.953 1.953-.558a.25.25 0 0 0 .108-.064l6.286-6.287Z"/>'
        + '</svg>';

    var UPLOAD_ICON = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2.75 14A1.75 1.75 0 0 1 1 12.25v-2.5a.75.75 0 0 1 1.5 0v2.5c0 .138.112.25.25.25h10.5a.25.25 0 0 0 .25-.25v-2.5a.75.75 0 0 1 1.5 0v2.5A1.75 1.75 0 0 1 13.25 14Z"/><path d="M11.78 4.72a.749.749 0 1 1-1.06 1.06L8.75 3.811V9.5a.75.75 0 0 1-1.5 0V3.811L5.28 5.78a.749.749 0 1 1-1.06-1.06l3.25-3.25a.749.749 0 0 1 1.06 0l3.25 3.25Z"/></svg>';
    var TRASH_ICON  = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M11 1.75V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.748 1.748 0 0 1 10.595 15h-5.19a1.75 1.75 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.15ZM6.5 1.75V3h3V1.75a.25.25 0 0 0-.25-.25h-2.5a.25.25 0 0 0-.25.25Z"/></svg>';

    // Read lazily: the config is registered in <head>, this file runs at the end of <body>.
    function config() {
        return root.InlineEditorConfig || {};
    }

    function warn() {
        if (root.console && typeof root.console.warn === 'function') {
            root.console.warn.apply(root.console, arguments);
        }
    }

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function isPlainObject(value) {
        return Object.prototype.toString.call(value) === '[object Object]';
    }

    // ── CKEditor build loading ─────────────────────────────────────────────────

    var ckeditorPromise = null;

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = function () { resolve(src); };
            script.onerror = function () { reject(new Error('Failed to load ' + src)); };
            document.head.appendChild(script);
        });
    }

    function loadStylesheet(href) {
        return new Promise(function (resolve) {
            var existing = document.querySelector('link[rel="stylesheet"][href="' + href.replace(/"/g, '\\"') + '"]');
            if (existing) { resolve(href); return; }
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.onload = function () { resolve(href); };
            link.onerror = function () {
                warn('Inline Editor: the CKEditor stylesheet could not be loaded from ' + href + '; the editor will look unstyled.');
                resolve(href);
            };
            document.head.appendChild(link);
        });
    }

    /**
     * Detect a loaded CKEditor 5 build.
     *  - "namespace": ckeditor5.umd.js style builds exposing window.CKEDITOR with
     *    every plugin class (the Craft-bundled and CDN builds); plugins are picked per toolbar.
     *  - "prebuilt": legacy classic builds exposing window.ClassicEditor with a fixed
     *    plugin set (ClassicEditor.builtinPlugins).
     */
    function detectBuild(scope) {
        scope = scope || root;
        if (scope.CKEDITOR && typeof scope.CKEDITOR.ClassicEditor === 'function') {
            return {
                kind: 'namespace',
                ns: scope.CKEDITOR,
                Editor: scope.CKEDITOR.ClassicEditor,
                version: scope.CKEDITOR.version || scope.CKEDITOR_VERSION || null
            };
        }
        if (typeof scope.ClassicEditor === 'function') {
            return {
                kind: 'prebuilt',
                ns: null,
                Editor: scope.ClassicEditor,
                version: scope.CKEDITOR_VERSION || null
            };
        }
        return null;
    }

    function loadCKEditor() {
        if (ckeditorPromise) { return ckeditorPromise; }

        var already = detectBuild();
        if (already) {
            ckeditorPromise = Promise.resolve(already);
            return ckeditorPromise;
        }

        var ck = config().ckeditor || {};
        if (!ck.scriptUrl) {
            return Promise.reject(new Error('no CKEditor build is configured (InlineEditorConfig.ckeditor.scriptUrl is empty).'));
        }

        ckeditorPromise = Promise.resolve()
            .then(function () { return ck.cssUrl ? loadStylesheet(ck.cssUrl) : null; })
            .then(function () { return loadScript(ck.scriptUrl); })
            .then(function () {
                if (!ck.translationUrl) { return null; }
                return loadScript(ck.translationUrl).catch(function () {
                    warn('Inline Editor: no CKEditor translation could be loaded from ' + ck.translationUrl + '; the editor UI falls back to English.');
                });
            })
            .then(function () {
                var build = detectBuild();
                if (!build) {
                    throw new Error('the script at ' + ck.scriptUrl + ' loaded, but neither window.CKEDITOR (ckeditor5.umd.js) nor window.ClassicEditor (classic build) was found.');
                }
                return build;
            });

        // Allow a retry after a failed load (e.g. transient network error).
        ckeditorPromise.catch(function () { ckeditorPromise = null; });

        return ckeditorPromise;
    }

    // ── CKEditor configuration ─────────────────────────────────────────────────

    // Mirrors craft\ckeditor\CkeConfig / Field defaults.
    var CK_DEFAULT_TOOLBAR = ['heading', '|', 'bold', 'italic', 'link'];

    // Plugins that are always loaded, like the control panel does (minus Craft-only ones).
    var CK_ALWAYS_PLUGINS = ['Essentials', 'Paragraph', 'SelectAll', 'Clipboard', 'Autoformat', 'PasteFromOffice', 'GeneralHtmlSupport', 'HtmlComment'];

    // LinkImage lives here, not with the link button: in CKEditor < 44 it only
    // soft-requires ImageEditing, so loading it without the image plugins throws
    // plugincollection-soft-required and the editor never starts.
    var CK_IMAGE_PLUGINS = ['Image', 'ImageCaption', 'ImageStyle', 'ImageToolbar', 'LinkImage', 'AutoImage'];

    /**
     * Toolbar item → required plugin classes. Mirrors the CKEditor plugin's own
     * button/plugin map, with Craft's control-panel-only plugins (CraftLink,
     * CraftImageInsertUI, CraftEntries, ImageTransform, ImageEditor) replaced by
     * their standard counterparts or marked as unavailable outside the CP.
     */
    var CK_PLUGIN_BUTTON_MAP = [
        { buttons: ['alignment'], plugins: ['Alignment'] },
        { buttons: ['link'], plugins: ['Link', 'AutoLink'] },
        { buttons: ['blockQuote'], plugins: ['BlockQuote'] },
        { buttons: ['bold'], plugins: ['Bold'] },
        { buttons: ['bookmark'], plugins: ['Bookmark'] },
        { buttons: ['code'], plugins: ['Code'] },
        { buttons: ['codeBlock'], plugins: ['CodeBlock'] },
        { buttons: ['bulletedList', 'numberedList'], plugins: ['List', 'ListProperties'] },
        { buttons: ['fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor'], plugins: ['Font'] },
        { buttons: ['findAndReplace'], plugins: ['FindAndReplace'] },
        { buttons: ['fullscreen'], plugins: ['Fullscreen'] },
        { buttons: ['heading'], plugins: ['Heading'] },
        { buttons: ['horizontalLine'], plugins: ['HorizontalLine'] },
        { buttons: ['htmlEmbed'], plugins: ['HtmlEmbed'] },
        { buttons: ['outdent', 'indent'], plugins: ['Indent', 'IndentBlock'] },
        { buttons: ['italic'], plugins: ['Italic'] },
        { buttons: ['mediaEmbed'], plugins: ['MediaEmbed'] },
        { buttons: ['pageBreak'], plugins: ['PageBreak'] },
        { buttons: ['removeFormat'], plugins: ['RemoveFormat'] },
        { buttons: ['sourceEditing'], plugins: ['SourceEditing'] },
        { buttons: ['strikethrough'], plugins: ['Strikethrough'] },
        { buttons: ['style'], plugins: ['Style'] },
        { buttons: ['subscript'], plugins: ['Subscript'] },
        { buttons: ['superscript'], plugins: ['Superscript'] },
        { buttons: ['insertTable'], plugins: ['Table', 'TableCaption', 'TableCellProperties', 'TableProperties', 'TableToolbar', 'TableUI'] },
        { buttons: ['textPartLanguage'], plugins: ['TextPartLanguage'] },
        { buttons: ['todoList'], plugins: ['TodoList'] },
        { buttons: ['underline'], plugins: ['Underline'] },
        { buttons: ['undo', 'redo'], plugins: [] },   // provided by Essentials
        { buttons: ['selectAll'], plugins: [] },      // provided by SelectAll
        // Control-panel-only items: the plugins that keep existing content intact are
        // still loaded, but the button itself cannot work outside the control panel.
        { buttons: ['insertImage'], plugins: CK_IMAGE_PLUGINS, cpOnly: 'inserting images needs the control panel asset picker (existing images remain editable)' },
        { buttons: ['createEntry'], plugins: [], cpOnly: 'nested entries can only be managed in the control panel' }
    ];

    /**
     * Attributes written by Craft's "Advanced Link Fields" that the standard Link
     * plugin would otherwise strip on load; General HTML Support keeps them intact.
     * When the field config enables link decorators (`link.addTargetToExternalLinks`
     * or `link.decorators`), `target` and `rel` must be left to the decorators:
     * letting both features own them produces nested <a> elements in older builds.
     */
    var CK_LINK_ATTRIBUTES = ['target', 'title', 'id', 'rel', 'aria-label', 'download'];
    var CK_DECORATOR_ATTRIBUTES = ['target', 'rel'];

    function linkDecoratorsActive(linkConfig) {
        if (!isPlainObject(linkConfig)) { return false; }
        if (linkConfig.addTargetToExternalLinks) { return true; }
        return isPlainObject(linkConfig.decorators) && Object.keys(linkConfig.decorators).length > 0;
    }

    function buildHtmlSupportAllow(linkConfig, customAllow) {
        var attributes = linkDecoratorsActive(linkConfig)
            ? CK_LINK_ATTRIBUTES.filter(function (name) { return CK_DECORATOR_ATTRIBUTES.indexOf(name) === -1; })
            : CK_LINK_ATTRIBUTES.slice();
        return [{ name: 'a', attributes: attributes, classes: true }].concat(Array.isArray(customAllow) ? customAllow : []);
    }

    function findButtonGroup(item) {
        for (var i = 0; i < CK_PLUGIN_BUTTON_MAP.length; i++) {
            if (CK_PLUGIN_BUTTON_MAP[i].buttons.indexOf(item) !== -1) { return CK_PLUGIN_BUTTON_MAP[i]; }
        }
        return null;
    }

    function buildHasPlugin(build, name) {
        if (build.kind === 'namespace') {
            return typeof build.ns[name] === 'function';
        }
        var builtin = build.Editor && build.Editor.builtinPlugins;
        if (!Array.isArray(builtin)) { return true; } // cannot be verified for this build
        return builtin.some(function (plugin) { return !!plugin && plugin.pluginName === name; });
    }

    function cleanSeparators(items) {
        var out = [];
        items.forEach(function (item) {
            var isSep = item === '|' || item === '-';
            if (isSep && (out.length === 0 || out[out.length - 1] === '|' || out[out.length - 1] === '-')) { return; }
            out.push(item);
        });
        while (out.length && (out[out.length - 1] === '|' || out[out.length - 1] === '-')) { out.pop(); }
        return out;
    }

    /**
     * Verify every toolbar item against the loaded build.
     * Returns { items, pluginNames, dropped: [{item, reason}] }.
     */
    function resolveToolbar(toolbar, build, buildLabel) {
        var items = [];
        var plugins = {};
        var dropped = [];

        function add(names) {
            names.forEach(function (name) {
                if (buildHasPlugin(build, name)) { plugins[name] = true; }
            });
        }

        add(CK_ALWAYS_PLUGINS);

        (Array.isArray(toolbar) ? toolbar : []).forEach(function (item) {
            if (item === '|' || item === '-') { items.push(item); return; }
            if (typeof item !== 'string' || item === '') {
                dropped.push({ item: String(item), reason: 'not a valid toolbar item' });
                return;
            }
            var group = findButtonGroup(item);
            if (!group) {
                dropped.push({ item: item, reason: 'no known CKEditor plugin provides this toolbar item (custom CKEditor packages are only available in the control panel)' });
                return;
            }
            if (group.cpOnly) {
                add(group.plugins);
                dropped.push({ item: item, reason: group.cpOnly });
                return;
            }
            var missing = group.plugins.filter(function (name) { return !buildHasPlugin(build, name); });
            if (missing.length) {
                dropped.push({ item: item, reason: 'the loaded CKEditor build (' + buildLabel + ') does not include the ' + missing.join(', ') + ' plugin' + (missing.length > 1 ? 's' : '') });
                return;
            }
            add(group.plugins);
            items.push(item);
        });

        return { items: cleanSeparators(items), pluginNames: Object.keys(plugins), dropped: dropped };
    }

    /**
     * CKEditor heading config from Craft's heading levels: paragraph plus the
     * allowed levels — and only those, exactly like the control panel.
     */
    function buildHeadingConfig(headingLevels) {
        var options = [{ model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' }];
        (Array.isArray(headingLevels) ? headingLevels : []).forEach(function (level) {
            var n = parseInt(level, 10);
            if (!isNaN(n) && n >= 1 && n <= 6) {
                options.push({ model: 'heading' + n, view: 'h' + n, title: 'Heading ' + n, class: 'ck-heading_heading' + n });
            }
        });
        return { options: options };
    }

    /**
     * Port of the CKEditor plugin's heading merge: custom options may restyle the
     * allowed heading levels or add fully custom models, but cannot re-enable
     * levels that the field disallows.
     */
    function mergeHeadingOptions(baseOptions, customOptions) {
        var native = ['paragraph', 'heading1', 'heading2', 'heading3', 'heading4', 'heading5', 'heading6'];
        var merged = {};
        baseOptions.forEach(function (option) { merged[option.model] = option; });
        customOptions.forEach(function (option) {
            if (!option || typeof option.model !== 'string') { return; }
            if (Object.prototype.hasOwnProperty.call(merged, option.model) || native.indexOf(option.model) === -1) {
                merged[option.model] = option;
            }
        });
        return Object.keys(merged).map(function (key) { return merged[key]; });
    }

    /**
     * Build the CKEditor config for one field against the loaded build.
     *
     *  fieldConfig: { toolbar, headingLevels, options, js, name, handle, source } (from the server, may be null)
     *  build:       result of detectBuild()
     *  buildInfo:   InlineEditorConfig.ckeditor (licenseKey, languages, source, version)
     *
     * Returns { config, dropped, pluginNames, warnings }.
     */
    function buildCKEditorConfig(fieldConfig, build, buildInfo) {
        fieldConfig = fieldConfig || {};
        buildInfo = buildInfo || {};
        var warnings = [];
        var buildLabel = (buildInfo.source || 'unknown build') + (build.version ? ', CKEditor ' + build.version : '');

        var toolbar = Array.isArray(fieldConfig.toolbar) && fieldConfig.toolbar.length ? fieldConfig.toolbar : CK_DEFAULT_TOOLBAR;
        var resolved = resolveToolbar(toolbar, build, buildLabel);

        var base = {
            toolbar: { items: resolved.items },
            heading: buildHeadingConfig(fieldConfig.headingLevels),
            image: { toolbar: ['toggleImageCaption', 'imageTextAlternative'] },
            table: { contentToolbar: ['tableRow', 'tableColumn', 'mergeTableCells'] },
            htmlSupport: { allow: buildHtmlSupportAllow(null, []) },
            language: {
                ui: buildInfo.uiLanguage || 'en',
                content: buildInfo.contentLanguage || buildInfo.uiLanguage || 'en'
            }
        };
        if (build.kind === 'namespace') {
            base.plugins = resolved.pluginNames.map(function (name) { return build.ns[name]; });
        }
        if (buildInfo.licenseKey) {
            base.licenseKey = buildInfo.licenseKey;
        }

        // Custom config: JSON options, or the JavaScript config body — never both (same as Craft).
        var custom = {};
        if (isPlainObject(fieldConfig.options)) {
            custom = fieldConfig.options;
        } else if (typeof fieldConfig.js === 'function') {
            try {
                custom = fieldConfig.js();
            } catch (err) {
                warnings.push('the custom JavaScript config options threw an error and were ignored: ' + (err && err.message ? err.message : err));
                custom = {};
            }
        }
        if (!isPlainObject(custom)) {
            if (custom !== undefined && custom !== null) {
                warnings.push('the custom config options did not evaluate to an object and were ignored');
            }
            custom = {};
        }

        var cfg = Object.assign({}, base, custom);

        // The verified toolbar items always win; other toolbar props from the custom config are kept.
        cfg.toolbar = Object.assign({}, isPlainObject(custom.toolbar) ? custom.toolbar : {}, { items: resolved.items });

        if (isPlainObject(custom.heading) && Array.isArray(custom.heading.options)) {
            cfg.heading = Object.assign({}, custom.heading, { options: mergeHeadingOptions(base.heading.options, custom.heading.options) });
        }

        cfg.language = Object.assign({}, base.language,
            isPlainObject(custom.language) ? custom.language : (typeof custom.language === 'string' ? { ui: custom.language } : {}));

        var customSupport = isPlainObject(custom.htmlSupport) ? custom.htmlSupport : {};
        cfg.htmlSupport = Object.assign({}, customSupport, {
            allow: buildHtmlSupportAllow(cfg.link, customSupport.allow)
        });

        var dropped = resolved.dropped.slice();
        var pluginNames = resolved.pluginNames.slice();

        if (build.kind === 'namespace') {
            var plugins = base.plugins.slice();
            ['plugins', 'extraPlugins'].forEach(function (key) {
                if (Array.isArray(custom[key])) {
                    custom[key].forEach(function (plugin) {
                        if (typeof plugin === 'function' && plugins.indexOf(plugin) === -1) { plugins.push(plugin); }
                    });
                }
            });

            var satisfied = satisfySoftRequirements(plugins, build.ns, buildLabel);
            if (satisfied.droppedItems.length) {
                var removeItems = satisfied.droppedItems.map(function (d) { return d.item; });
                cfg.toolbar.items = cleanSeparators(cfg.toolbar.items.filter(function (item) { return removeItems.indexOf(item) === -1; }));
                dropped = dropped.concat(satisfied.droppedItems);
            }
            warnings = warnings.concat(satisfied.warnings);
            cfg.plugins = satisfied.plugins;
            pluginNames = satisfied.plugins.map(function (plugin) { return plugin.pluginName; }).filter(Boolean);
            delete cfg.extraPlugins;
        }

        return { config: cfg, dropped: dropped, pluginNames: pluginNames, warnings: warnings };
    }

    /**
     * CKEditor plugins may declare "soft" requirements: plugin names (strings)
     * in `static get requires()` that must be provided by the editor config,
     * otherwise `create()` throws `plugincollection-soft-required`. Resolve them
     * up front for namespace builds: add the required plugin from the build when
     * it exists, otherwise drop the toolbar items whose plugins cannot start.
     *
     * Returns { plugins, droppedItems: [{item, reason}], warnings }.
     */
    function satisfySoftRequirements(plugins, ns, buildLabel) {
        var selected = plugins.slice();
        var droppedItems = [];
        var warnings = [];
        var guard = 0;

        function closure(classes) {
            var seen = [];
            var soft = [];
            function visit(cls, origin) {
                if (seen.indexOf(cls) !== -1) { return; }
                seen.push(cls);
                var reqs = Array.isArray(cls.requires) ? cls.requires : [];
                reqs.forEach(function (req) {
                    if (typeof req === 'function') { visit(req, origin); }
                    else if (typeof req === 'string') { soft.push({ name: req, requiredBy: cls.pluginName || '?', origin: origin }); }
                });
            }
            classes.forEach(function (cls) { visit(cls, cls); });
            return { classes: seen, soft: soft };
        }

        while (guard++ < 25) {
            var info = closure(selected);
            var names = info.classes.map(function (cls) { return cls.pluginName; });
            var unmet = null;
            for (var i = 0; i < info.soft.length; i++) {
                if (names.indexOf(info.soft[i].name) === -1) { unmet = info.soft[i]; break; }
            }
            if (!unmet) { break; }

            var candidate = ns[unmet.name];
            if (typeof candidate === 'function' && candidate.pluginName === unmet.name) {
                selected.push(candidate);
                continue;
            }

            // Cannot be satisfied: remove the top-level plugin that pulled it in.
            selected = selected.filter(function (cls) { return cls !== unmet.origin; });
            var originName = unmet.origin.pluginName || '?';
            var reason = 'its plugin ' + unmet.requiredBy + ' requires ' + unmet.name + ', which the loaded CKEditor build (' + buildLabel + ') does not provide';
            var groupItems = [];
            CK_PLUGIN_BUTTON_MAP.forEach(function (group) {
                if (group.plugins.indexOf(originName) !== -1) { groupItems = groupItems.concat(group.buttons); }
            });
            if (groupItems.length) {
                groupItems.forEach(function (item) { droppedItems.push({ item: item, reason: reason }); });
            } else {
                warnings.push('plugin ' + originName + ' was removed because ' + reason);
            }
        }

        return { plugins: selected, droppedItems: droppedItems, warnings: warnings };
    }

    function lookupFieldConfig(fieldUid, handle) {
        var registry = root.InlineEditorCKConfigs || {};
        if (fieldUid && registry[fieldUid]) { return registry[fieldUid]; }
        var keys = Object.keys(registry);
        for (var i = 0; i < keys.length; i++) {
            if (registry[keys[i]] && registry[keys[i]].handle === handle) { return registry[keys[i]]; }
        }
        return null;
    }

    var reportedCK = {};

    function reportCKDiagnostics(editor, fieldConfig, built, build, buildInfo) {
        var key = editor.fieldUid || editor.field;
        if (reportedCK[key]) { return; }
        reportedCK[key] = true;

        var label = 'Inline Editor: CKEditor field "' + editor.field + '"';
        var buildLabel = (buildInfo.source || 'unknown build') + (build.version ? ', CKEditor ' + build.version : '');

        if (!fieldConfig) {
            warn(label + ': no field configuration was registered on this page; using the default toolbar. Check the Craft logs for "Inline Editor" warnings.');
        }
        built.warnings.forEach(function (message) { warn(label + ': ' + message + '.'); });
        if (built.dropped.length) {
            warn(label + ' (' + buildLabel + '): ' + built.dropped.length + ' toolbar item(s) are not available inline — '
                + built.dropped.map(function (d) { return '"' + d.item + '" (' + d.reason + ')'; }).join('; ') + '.');
        }
    }

    function createTrigger() {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'inline-editor__trigger';
        btn.setAttribute('aria-label', 'Edit');
        btn.title = 'Edit';
        btn.innerHTML = EDIT_ICON;
        return btn;
    }

    // ── Constructor ────────────────────────────────────────────────────────────

    function Editor(el) {
        this.el = el;
        this.elementId = el.getAttribute('data-element-id');
        this.siteId = el.getAttribute('data-site-id');
        this.field = el.getAttribute('data-field');
        this.fieldUid = el.getAttribute('data-field-uid') || null;
        this.type = el.getAttribute('data-type');
        this.groupId = el.getAttribute('data-group-id') || null;
        this.inputType = el.getAttribute('data-input') || 'input';
        try { this.assetIds = JSON.parse(el.getAttribute('data-asset-ids') || '[]'); } catch (_) { this.assetIds = []; }
        this.placeholder = el.getAttribute('data-placeholder') || '';

        this.originalHtml = null;
        this.originalTagsData = null;
        this.selectedTags = [];

        this.ckeditorInstance = null;
        this._mountToken = 0;
        this.tagsWrap = null;
        this.tagsTextInput = null;
        this.tagsDropdown = null;
        this._searchTimeout = null;
        this._outsideClickHandler = null;
        this._assetErrorEl = null;

        this.editing = false;

        if (this.type === 'assets') {
            this.trigger = null;
            this._initAssetOverlay();
        } else {
            this.trigger = createTrigger();
            this.trigger.addEventListener('click', this.start.bind(this));
            this.el.appendChild(this.trigger);
        }
    }

    // ── Edit start ─────────────────────────────────────────────────────────────

    Editor.prototype.start = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        if (this.editing) { return; }
        this.editing = true;

        this.originalHtml = this.el.innerHTML;
        this.el.classList.add('is-editing');

        var initialValue;
        if (this.type === 'tags') {
            this.originalTagsData = this.el.getAttribute('data-tags') || '[]';
            try { initialValue = JSON.parse(this.originalTagsData); } catch (_) { initialValue = []; }
        } else {
            initialValue = this.currentValue();
        }

        this.el.innerHTML = '';

        var form = document.createElement('div');
        form.className = 'inline-editor__form';

        var input = this.buildInput(initialValue);
        form.appendChild(input);

        if (this.type === 'ckeditor') {
            var loading = document.createElement('div');
            loading.className = 'inline-editor__ckeditor-loading';
            loading.textContent = 'Loading editor…';
            form.appendChild(loading);
            this.loadingEl = loading;
        }

        var actions = document.createElement('div');
        actions.className = 'inline-editor__actions';

        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'inline-editor__btn inline-editor__btn--save';
        saveBtn.textContent = 'Save';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'inline-editor__btn inline-editor__btn--cancel';
        cancelBtn.textContent = 'Discard';

        actions.appendChild(saveBtn);
        actions.appendChild(cancelBtn);
        form.appendChild(actions);

        var errorEl = document.createElement('div');
        errorEl.className = 'inline-editor__error';
        errorEl.hidden = true;
        form.appendChild(errorEl);

        this.el.appendChild(form);

        this.formInput = input;
        this.saveBtn = saveBtn;
        this.cancelBtn = cancelBtn;
        this.errorEl = errorEl;

        saveBtn.addEventListener('click', this.save.bind(this));
        cancelBtn.addEventListener('click', this.cancel.bind(this));

        if (this.type === 'ckeditor') {
            this.mountCKEditor();
        } else if (this.type === 'tags') {
            // keydown is handled inside buildTagsInput; focus the text input
            var self = this;
            setTimeout(function () { if (self.tagsTextInput) { self.tagsTextInput.focus(); } }, 0);
        } else {
            input.addEventListener('keydown', this.onKeydown.bind(this));
            input.focus();
            if (typeof input.select === 'function') { input.select(); }
        }

        this._startOutsideClickHandler();
    };

    // ── Input builders ─────────────────────────────────────────────────────────

    Editor.prototype.currentValue = function () {
        if (this.type === 'ckeditor') {
            // data-value always holds the raw stored HTML, even when innerHtml
            // was used to show a transformed display version.
            var raw = this.el.getAttribute('data-value');
            return raw !== null ? raw : this.el.innerHTML;
        }
        var clone = this.el.cloneNode(true);
        var trig = clone.querySelector('.inline-editor__trigger');
        if (trig) { trig.remove(); }
        var ph = clone.querySelector('.inline-editor__placeholder');
        if (ph) { ph.remove(); }
        return clone.textContent.replace(/^\s+|\s+$/g, '');
    };

    Editor.prototype.buildInput = function (value) {
        if (this.type === 'tags') { return this.buildTagsInput(value); }
        if (this.type === 'ckeditor') {
            // A textarea keeps the raw HTML out of the live DOM until CKEditor has
            // parsed it; CKEditor reads its value as the initial data.
            var source = document.createElement('textarea');
            source.className = 'inline-editor__ckeditor';
            source.value = value;
            source.hidden = true;
            return source;
        }
        if (this.type === 'url') {
            var url = document.createElement('input');
            url.type = 'url';
            url.className = 'inline-editor__input';
            url.value = value;
            url.placeholder = this.placeholder || 'https://';
            return url;
        }
        if (this.inputType === 'textarea') {
            var ta = document.createElement('textarea');
            ta.className = 'inline-editor__textarea';
            ta.value = value;
            ta.placeholder = this.placeholder;
            return ta;
        }
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'inline-editor__input';
        inp.value = value;
        inp.placeholder = this.placeholder;
        return inp;
    };

    // ── Tags editor ────────────────────────────────────────────────────────────

    Editor.prototype.buildTagsInput = function (tags) {
        var self = this;
        this.selectedTags = tags.map(function (t) { return { id: t.id, title: t.title, isNew: false }; });

        var wrap = document.createElement('div');
        wrap.className = 'inline-editor__tags-input-wrap';
        wrap.style.position = 'relative';

        var textInput = document.createElement('input');
        textInput.type = 'text';
        textInput.className = 'inline-editor__tags-text';
        textInput.placeholder = 'Add tag…';
        textInput.setAttribute('autocomplete', 'off');

        var dropdown = document.createElement('ul');
        dropdown.className = 'inline-editor__tags-dropdown';
        dropdown.hidden = true;

        this.tagsWrap = wrap;
        this.tagsTextInput = textInput;
        this.tagsDropdown = dropdown;

        wrap.appendChild(textInput);
        wrap.appendChild(dropdown);
        this.renderTagChips();

        wrap.addEventListener('click', function (e) {
            if (e.target === wrap) { textInput.focus(); }
        });

        textInput.addEventListener('input', function () {
            clearTimeout(self._searchTimeout);
            self._searchTimeout = setTimeout(function () {
                self.searchTags(textInput.value.trim());
            }, 250);
        });

        textInput.addEventListener('focus', function () {
            if (textInput.value === '') {
                self.searchTags('');
            }
        });

        textInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (!dropdown.hidden) {
                    e.stopPropagation();
                    self.hideDropdown();
                }
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                e.stopPropagation();
                var active = dropdown.querySelector('li.is-active');
                if (active && !dropdown.hidden) {
                    active.click();
                    return;
                }
                var text = textInput.value.trim();
                if (text) {
                    self.addTag({ id: null, title: text, isNew: true });
                    textInput.value = '';
                    self.hideDropdown();
                }
                return;
            }
            if (e.key === 'Backspace' && textInput.value === '') {
                e.preventDefault();
                self.removeLastTag();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                self.moveDropdownActive(1);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                self.moveDropdownActive(-1);
                return;
            }
        });

        // Hide dropdown when focus leaves the wrap entirely.
        wrap.addEventListener('focusout', function (e) {
            if (!wrap.contains(e.relatedTarget)) {
                setTimeout(function () { self.hideDropdown(); }, 150);
            }
        });

        return wrap;
    };

    Editor.prototype.addTag = function (tag) {
        var lc = tag.title.toLowerCase();
        var exists = this.selectedTags.some(function (t) {
            return (tag.id !== null && t.id === tag.id) || t.title.toLowerCase() === lc;
        });
        if (exists) { return; }
        this.selectedTags.push(tag);
        this.renderTagChips();
    };

    Editor.prototype.removeTag = function (index) {
        this.selectedTags.splice(index, 1);
        this.renderTagChips();
    };

    Editor.prototype.removeLastTag = function () {
        if (this.selectedTags.length > 0) {
            this.selectedTags.pop();
            this.renderTagChips();
        }
    };

    Editor.prototype.renderTagChips = function () {
        var self = this;
        var wrap = this.tagsWrap;
        // Remove existing chips, keep text input and dropdown.
        wrap.querySelectorAll('.inline-editor__tag--removable').forEach(function (c) { c.remove(); });

        this.selectedTags.forEach(function (tag, index) {
            var chip = document.createElement('span');
            chip.className = 'inline-editor__tag inline-editor__tag--removable';
            chip.appendChild(document.createTextNode(tag.title + ' '));

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'inline-editor__tag-remove';
            removeBtn.setAttribute('aria-label', 'Remove ' + tag.title);
            removeBtn.textContent = '×';
            removeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                self.removeTag(index);
                if (self.tagsTextInput) { self.tagsTextInput.focus(); }
            });

            chip.appendChild(removeBtn);
            wrap.insertBefore(chip, self.tagsTextInput);
        });
    };

    Editor.prototype.searchTags = function (query) {
        var self = this;
        var cfg = config();
        if (!this.groupId || !cfg.searchTagsUrl) { return; }

        var url = new URL(cfg.searchTagsUrl, root.location.href);
        url.searchParams.set('groupId', this.groupId);
        url.searchParams.set('siteId', this.siteId);
        url.searchParams.set('search', query);

        fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (data && Array.isArray(data.tags)) {
                self.showDropdown(data.tags, query);
            }
        }).catch(function () { /* silently ignore search errors */ });
    };

    Editor.prototype.showDropdown = function (results, query) {
        var self = this;
        var dropdown = this.tagsDropdown;
        dropdown.innerHTML = '';

        var selectedIds = this.selectedTags.filter(function (t) { return t.id !== null; }).map(function (t) { return t.id; });
        var selectedTitles = this.selectedTags.map(function (t) { return t.title.toLowerCase(); });

        var filtered = results.filter(function (t) {
            return selectedIds.indexOf(t.id) === -1 && selectedTitles.indexOf(t.title.toLowerCase()) === -1;
        });

        filtered.forEach(function (tag) {
            var li = document.createElement('li');
            li.textContent = tag.title;
            li.setAttribute('data-id', tag.id);
            li.addEventListener('mousedown', function (e) { e.preventDefault(); }); // prevent blur before click
            li.addEventListener('click', function () {
                self.addTag({ id: tag.id, title: tag.title, isNew: false });
                self.tagsTextInput.value = '';
                self.hideDropdown();
                self.tagsTextInput.focus();
            });
            dropdown.appendChild(li);
        });

        if (query) {
            var lq = query.toLowerCase();
            var exactInResults = results.some(function (t) { return t.title.toLowerCase() === lq; });
            var exactInSelected = selectedTitles.indexOf(lq) !== -1;

            if (!exactInResults && !exactInSelected) {
                var createLi = document.createElement('li');
                createLi.className = 'is-create';
                createLi.textContent = 'Create "' + query + '"';
                createLi.addEventListener('mousedown', function (e) { e.preventDefault(); });
                createLi.addEventListener('click', function () {
                    self.addTag({ id: null, title: query, isNew: true });
                    self.tagsTextInput.value = '';
                    self.hideDropdown();
                    self.tagsTextInput.focus();
                });
                dropdown.appendChild(createLi);
            }
        }

        if (dropdown.children.length === 0) {
            if (query) {
                var emptyLi = document.createElement('li');
                emptyLi.className = 'is-empty';
                emptyLi.textContent = 'No tags found';
                dropdown.appendChild(emptyLi);
            } else {
                dropdown.hidden = true;
                return;
            }
        }

        dropdown.hidden = false;
    };

    Editor.prototype.hideDropdown = function () {
        if (this.tagsDropdown) {
            this.tagsDropdown.hidden = true;
            this.tagsDropdown.innerHTML = '';
        }
    };

    Editor.prototype.moveDropdownActive = function (dir) {
        var dropdown = this.tagsDropdown;
        if (dropdown.hidden) {
            this.searchTags(this.tagsTextInput ? this.tagsTextInput.value.trim() : '');
            return;
        }
        var items = Array.from(dropdown.querySelectorAll('li:not(.is-empty)'));
        var current = dropdown.querySelector('li.is-active');
        var idx = current ? items.indexOf(current) : -1;
        if (current) { current.classList.remove('is-active'); }
        var next = idx + dir;
        if (next < 0) { next = items.length - 1; }
        if (next >= items.length) { next = 0; }
        if (items[next]) {
            items[next].classList.add('is-active');
            items[next].scrollIntoView({ block: 'nearest' });
        }
    };

    // ── Assets overlay ────────────────────────────────────────────────────────

    Editor.prototype._initAssetOverlay = function () {
        var self = this;

        // Wrapper shown in the top-right corner on hover.
        var actions = document.createElement('div');
        actions.className = 'inline-editor__asset-actions';

        var replaceBtn = document.createElement('button');
        replaceBtn.type = 'button';
        replaceBtn.className = 'inline-editor__asset-btn';
        replaceBtn.title = 'Replace';
        replaceBtn.innerHTML = UPLOAD_ICON;

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'inline-editor__asset-btn inline-editor__asset-btn--danger';
        clearBtn.title = 'Remove';
        clearBtn.innerHTML = TRASH_ICON;

        actions.appendChild(replaceBtn);
        actions.appendChild(clearBtn);

        // Hidden file input — triggered by the replace button.
        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = '*/*';
        fileInput.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;';

        // Spinner overlay shown while uploading / clearing.
        var overlay = document.createElement('div');
        overlay.className = 'inline-editor__asset-overlay';
        var spinner = document.createElement('div');
        spinner.className = 'inline-editor__asset-spinner';
        overlay.appendChild(spinner);

        // Error message shown below the image on failure.
        var errorEl = document.createElement('div');
        errorEl.className = 'inline-editor__asset-error';
        errorEl.hidden = true;

        this.el.appendChild(actions);
        this.el.appendChild(fileInput);
        this.el.appendChild(overlay);
        this.el.appendChild(errorEl);
        this._assetErrorEl = errorEl;

        replaceBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            fileInput.click();
        });

        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files[0]) {
                self._uploadAsset(fileInput.files[0]);
                fileInput.value = ''; // reset so same file can be chosen again
            }
        });

        clearBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            self._clearAsset();
        });
    };

    Editor.prototype._assetBusy = function (busy) {
        if (busy) {
            this.el.classList.add('is-saving');
        } else {
            this.el.classList.remove('is-saving');
        }
    };

    Editor.prototype._assetError = function (message) {
        if (!this._assetErrorEl) { return; }
        if (message) {
            this._assetErrorEl.textContent = message;
            this._assetErrorEl.hidden = false;
            var el = this._assetErrorEl;
            setTimeout(function () { el.hidden = true; }, 5000);
        } else {
            this._assetErrorEl.hidden = true;
        }
    };

    Editor.prototype._uploadAsset = function (file) {
        var self = this;
        var cfg = config();
        this._assetBusy(true);
        this._assetError(null);

        var body = new FormData();
        body.append('elementId', this.elementId);
        body.append('siteId', this.siteId);
        body.append('field', this.field);
        body.append('file', file);
        // Tell the server which specific asset this wrapper manages so it can
        // swap just that slot instead of replacing the whole field value.
        if (this.assetIds.length > 0) { body.append('removeAssetId', this.assetIds[0]); }
        if (cfg.csrfTokenName && cfg.csrfToken) {
            body.append(cfg.csrfTokenName, cfg.csrfToken);
        }

        fetch(cfg.replaceAssetUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': cfg.csrfToken || '',
            },
            body: body
        }).then(function (res) {
            return res.json().then(function (json) { return { status: res.status, body: json }; });
        }).then(function (result) {
            self._assetBusy(false);
            if (!result.body || !result.body.success) {
                self._assetError((result.body && result.body.error) || 'Upload failed.');
                return;
            }
            // Update the tracked asset ID.
            self.assetIds = result.body.id ? [result.body.id] : [];
            self.el.setAttribute('data-asset-ids', JSON.stringify(self.assetIds));
            // Swap the image src to the raw upload URL immediately so the new
            // asset is visible without a page reload.
            var img = self.el.querySelector('img');
            if (img && result.body.url) { img.src = result.body.url; }
            self._flashSaved();
            self.dispatch('save', { url: result.body.url, id: result.body.id });
        }).catch(function (err) {
            self._assetBusy(false);
            self._assetError(err && err.message ? err.message : 'Upload failed.');
        });
    };

    Editor.prototype._clearAsset = function () {
        var self = this;
        var cfg = config();

        // Guard: refuse if we have no tracked asset ID — sending without one
        // would be rejected by the server anyway, and it prevents any chance of
        // an accidental wipe if the data-asset-ids attribute is malformed.
        if (!this.assetIds.length || !this.assetIds[0]) {
            this._assetError('No asset ID tracked for this slot.');
            return;
        }

        if (!root.confirm('Remove this asset? This cannot be undone.')) { return; }

        this._assetBusy(true);
        this._assetError(null);

        var body = new URLSearchParams();
        body.append('elementId', this.elementId);
        body.append('siteId', this.siteId);
        body.append('field', this.field);
        body.append('clear', '1');
        // Tell the server which specific asset to remove, so it can use
        // array_diff instead of wiping the whole field value.
        if (this.assetIds.length > 0) { body.append('removeAssetId', this.assetIds[0]); }
        if (cfg.csrfTokenName && cfg.csrfToken) {
            body.append(cfg.csrfTokenName, cfg.csrfToken);
        }

        fetch(cfg.replaceAssetUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': cfg.csrfToken || '',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: body.toString()
        }).then(function (res) {
            return res.json().then(function (json) { return { status: res.status, body: json }; });
        }).then(function (result) {
            self._assetBusy(false);
            if (!result.body || !result.body.success) {
                self._assetError((result.body && result.body.error) || 'Could not remove asset.');
                return;
            }
            self.assetIds = [];
            self.el.setAttribute('data-asset-ids', '[]');
            var img = self.el.querySelector('img');
            if (img) { img.remove(); }
            self._flashSaved();
            self.dispatch('save', { cleared: true });
        }).catch(function (err) {
            self._assetBusy(false);
            self._assetError(err && err.message ? err.message : 'Could not remove asset.');
        });
    };

    // ── Click-outside to cancel ────────────────────────────────────────────────

    Editor.prototype._startOutsideClickHandler = function () {
        var self = this;
        this._outsideClickHandler = function (e) {
            // If the target was removed from the DOM before the click bubbled
            // here (e.g. a dropdown item that clears itself on click), ignore it.
            if (!e.target.isConnected) { return; }
            // Ignore clicks inside the editor element itself.
            if (self.el.contains(e.target)) { return; }
            // CKEditor renders balloons and dropdowns outside the element in
            // a .ck-body-wrapper div appended to <body>. Ignore those.
            if (e.target.closest && e.target.closest('.ck-body-wrapper')) { return; }
            self.cancel();
        };
        // Defer by one tick so the click that opened the editor doesn't
        // immediately trigger the outside handler.
        var handler = this._outsideClickHandler;
        setTimeout(function () { document.addEventListener('click', handler); }, 0);
    };

    Editor.prototype._stopOutsideClickHandler = function () {
        if (this._outsideClickHandler) {
            document.removeEventListener('click', this._outsideClickHandler);
            this._outsideClickHandler = null;
        }
    };

    // ── CKEditor ───────────────────────────────────────────────────────────────

    Editor.prototype.mountCKEditor = function () {
        var self = this;
        var token = ++this._mountToken;
        this.setSaveEnabled(false);

        loadCKEditor().then(function (build) {
            if (token !== self._mountToken) { return null; }

            var buildInfo = config().ckeditor || {};
            var fieldConfig = lookupFieldConfig(self.fieldUid, self.field);
            var built = buildCKEditorConfig(fieldConfig, build, buildInfo);
            reportCKDiagnostics(self, fieldConfig, built, build, buildInfo);

            return build.Editor.create(self.formInput, built.config).then(function (instance) {
                if (token !== self._mountToken) {
                    // The user discarded before the editor finished loading.
                    instance.destroy().catch(function () {});
                    return null;
                }
                self.ckeditorInstance = instance;
                self._hideLoading();
                self.setSaveEnabled(true);
                instance.editing.view.focus();
                return instance;
            });
        }).catch(function (err) {
            if (token !== self._mountToken) { return; }
            self._hideLoading();
            self.showError('Could not load the editor: ' + (err && err.message ? err.message : err));
            if (root.console && typeof root.console.error === 'function') {
                root.console.error('Inline Editor: CKEditor could not be started.', err);
            }
        });
    };

    Editor.prototype._hideLoading = function () {
        if (this.loadingEl) {
            this.loadingEl.remove();
            this.loadingEl = null;
        }
    };

    Editor.prototype.teardownCKEditor = function () {
        // Invalidates any editor still loading for this element.
        this._mountToken++;
        this.loadingEl = null;
        if (this.ckeditorInstance) {
            try { this.ckeditorInstance.destroy().catch(function () {}); } catch (_) {}
            this.ckeditorInstance = null;
        }
    };

    // ── Keyboard (non-tags fields) ─────────────────────────────────────────────

    Editor.prototype.onKeydown = function (e) {
        if (e.key === 'Escape') { e.preventDefault(); this.cancel(); return; }
        if (e.key === 'Enter') {
            var isTextarea = e.target && e.target.tagName === 'TEXTAREA';
            if (!isTextarea || e.metaKey || e.ctrlKey) { e.preventDefault(); this.save(); }
        }
    };

    // ── Read value ─────────────────────────────────────────────────────────────

    Editor.prototype.readValue = function () {
        if (this.type === 'ckeditor') {
            return this.ckeditorInstance ? this.ckeditorInstance.getData() : null;
        }
        return this.formInput && 'value' in this.formInput ? this.formInput.value : '';
    };

    // ── Save ───────────────────────────────────────────────────────────────────

    Editor.prototype.save = function () {
        var self = this;
        var cfg = config();

        if (this.type === 'ckeditor' && !this.ckeditorInstance) {
            // Never submit before the editor is ready: the value would be empty.
            this.showError('The editor is still loading — please try again in a moment.');
            return;
        }

        this.setBusy(true);
        this.showError(null);

        var body = new URLSearchParams();
        body.append('elementId', this.elementId);
        body.append('siteId', this.siteId);
        body.append('field', this.field);

        if (this.type === 'tags') {
            this.selectedTags.forEach(function (tag) {
                if (!tag.isNew && tag.id !== null) {
                    body.append('tagIds[]', tag.id);
                } else {
                    body.append('newTags[]', tag.title);
                }
            });
        } else {
            body.append('value', this.readValue());
        }

        if (cfg.csrfTokenName && cfg.csrfToken) {
            body.append(cfg.csrfTokenName, cfg.csrfToken);
        }

        fetch(cfg.saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': cfg.csrfToken || '',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (res) {
            return res.json().then(function (json) { return { status: res.status, body: json }; });
        }).then(function (result) {
            if (!result.body || !result.body.success) {
                var msg = (result.body && (result.body.error || formatErrors(result.body.errors))) || ('HTTP ' + result.status);
                self.showError(msg);
                self.setBusy(false);
                return;
            }
            if (self.type === 'tags') {
                self.finishTags(result.body.tags || []);
            } else {
                self.finish(result.body.value, result.body.rawValue);
            }
        }).catch(function (err) {
            self.showError(err && err.message ? err.message : 'Save failed');
            self.setBusy(false);
        });
    };

    // ── Finish (plain fields) ──────────────────────────────────────────────────

    Editor.prototype.finish = function (savedValue, rawValue) {
        this._stopOutsideClickHandler();
        this.teardownCKEditor();
        this.el.classList.remove('is-editing', 'is-saving');
        this.el.innerHTML = '';

        if (this.type === 'ckeditor') {
            // Keep the stored (unrendered) content for the next edit.
            this.el.setAttribute('data-value', rawValue !== undefined && rawValue !== null ? rawValue : (savedValue || ''));
        }

        if (savedValue === '' || savedValue == null) {
            if (this.placeholder) {
                var ph = document.createElement('span');
                ph.className = 'inline-editor__placeholder';
                ph.textContent = this.placeholder;
                this.el.appendChild(ph);
            }
        } else if (this.type === 'ckeditor') {
            this.el.innerHTML = savedValue;
        } else {
            this.el.appendChild(document.createTextNode(savedValue));
        }

        this.el.appendChild(this.trigger);
        this._flashSaved();

        this.editing = false;
        this.originalHtml = null;
        this.dispatch('save', { value: savedValue, rawValue: rawValue });
    };

    // ── Finish (tags) ──────────────────────────────────────────────────────────

    Editor.prototype.finishTags = function (tags) {
        var self = this;
        this._stopOutsideClickHandler();
        this.el.classList.remove('is-editing', 'is-saving');
        this.el.innerHTML = '';

        // Update data-tags so the next edit starts from the correct state.
        this.el.setAttribute('data-tags', JSON.stringify(tags));

        tags.forEach(function (tag) {
            var chip = document.createElement('span');
            chip.className = 'inline-editor__tag';
            chip.textContent = tag.title;
            self.el.appendChild(chip);
        });

        this.el.appendChild(this.trigger);
        this._flashSaved();

        this.editing = false;
        this.originalHtml = null;
        this.originalTagsData = null;
        this.selectedTags = [];
        this.tagsWrap = null;
        this.tagsTextInput = null;
        this.tagsDropdown = null;
        this.dispatch('save', { tags: tags });
    };

    // ── Cancel ─────────────────────────────────────────────────────────────────

    Editor.prototype.cancel = function (e) {
        if (e) { e.stopPropagation(); }
        this._stopOutsideClickHandler();
        this.teardownCKEditor();
        clearTimeout(this._searchTimeout);

        this.el.classList.remove('is-editing');
        this.el.innerHTML = this.originalHtml || '';

        // Restore data-tags if this was a tags edit.
        if (this.type === 'tags' && this.originalTagsData !== null) {
            this.el.setAttribute('data-tags', this.originalTagsData);
        }

        // Re-attach the live trigger element (the serialised HTML has a dead copy).
        var deadTrigger = this.el.querySelector('.inline-editor__trigger');
        if (deadTrigger) { deadTrigger.replaceWith(this.trigger); }
        else { this.el.appendChild(this.trigger); }

        this.editing = false;
        this.originalHtml = null;
        this.originalTagsData = null;
        this.selectedTags = [];
        this.tagsWrap = null;
        this.tagsTextInput = null;
        this.tagsDropdown = null;
        this.dispatch('cancel');
    };

    // ── Helpers ────────────────────────────────────────────────────────────────

    Editor.prototype._flashSaved = function () {
        var self = this;
        this.el.classList.add('is-saved-flash');
        setTimeout(function () { self.el.classList.remove('is-saved-flash'); }, 700);
    };

    Editor.prototype.setSaveEnabled = function (enabled) {
        if (this.saveBtn) { this.saveBtn.disabled = !enabled; }
    };

    Editor.prototype.setBusy = function (busy) {
        if (busy) {
            this.el.classList.add('is-saving');
            if (this.saveBtn) { this.saveBtn.disabled = true; this.saveBtn.textContent = 'Saving…'; }
            if (this.cancelBtn) { this.cancelBtn.disabled = true; }
        } else {
            this.el.classList.remove('is-saving');
            if (this.saveBtn) { this.saveBtn.disabled = false; this.saveBtn.textContent = 'Save'; }
            if (this.cancelBtn) { this.cancelBtn.disabled = false; }
        }
    };

    Editor.prototype.showError = function (message) {
        if (!this.errorEl) { return; }
        this.errorEl.hidden = !message;
        this.errorEl.textContent = message || '';
    };

    Editor.prototype.dispatch = function (name, detail) {
        this.el.dispatchEvent(new CustomEvent('inline-editor:' + name, {
            bubbles: true,
            cancelable: false,
            detail: Object.assign({ editor: this }, detail || {})
        }));
    };

    function formatErrors(errors) {
        if (!errors || typeof errors !== 'object') { return null; }
        var lines = [];
        Object.keys(errors).forEach(function (key) {
            var list = errors[key];
            if (Array.isArray(list)) { lines = lines.concat(list); }
        });
        return lines.length ? lines.join(' ') : null;
    }

    // ── Init ───────────────────────────────────────────────────────────────────

    function initAll(scope) {
        var nodes = (scope || document).querySelectorAll('[data-inline-editor]:not([data-inline-editor-ready])');
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            el.setAttribute('data-inline-editor-ready', '1');
            // eslint-disable-next-line no-new
            new Editor(el);
        }
    }

    root.InlineEditor = {
        init: initAll,
        // Unstable: exposed for the plugin's own tests and for debugging.
        _internal: {
            detectBuild: detectBuild,
            resolveToolbar: resolveToolbar,
            buildHeadingConfig: buildHeadingConfig,
            mergeHeadingOptions: mergeHeadingOptions,
            buildCKEditorConfig: buildCKEditorConfig,
            lookupFieldConfig: lookupFieldConfig,
            satisfySoftRequirements: satisfySoftRequirements,
            cleanSeparators: cleanSeparators,
            buildHtmlSupportAllow: buildHtmlSupportAllow,
            CK_PLUGIN_BUTTON_MAP: CK_PLUGIN_BUTTON_MAP,
            CK_ALWAYS_PLUGINS: CK_ALWAYS_PLUGINS,
            CK_DEFAULT_TOOLBAR: CK_DEFAULT_TOOLBAR
        }
    };

    if (typeof document !== 'undefined') {
        ready(function () { initAll(document); });
    }
})(typeof window !== 'undefined' ? window : globalThis);
