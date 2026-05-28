/* Inline Editor — frontend runtime.
   Renders an edit button on every [data-inline-editor] element, handles
   swap-to-form, save (AJAX), and discard. Pure vanilla JS; no jQuery. */

(function () {
    'use strict';

    var EDIT_ICON = '<svg viewBox="0 0 16 16" aria-hidden="true">'
        + '<path d="M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.21.21-.47.364-.756.445l-3.251.93a.75.75 0 0 1-.927-.928l.929-3.25c.081-.286.235-.547.445-.758l8.61-8.609Zm1.414 1.06a.25.25 0 0 0-.354 0L10.811 3.75 12.25 5.189l1.263-1.263a.25.25 0 0 0 0-.354l-1.086-1.085ZM11.189 6.25 9.75 4.811l-6.286 6.287a.25.25 0 0 0-.064.108l-.558 1.953 1.953-.558a.25.25 0 0 0 .108-.064l6.286-6.287Z"/>'
        + '</svg>';

    var UPLOAD_ICON = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2.75 14A1.75 1.75 0 0 1 1 12.25v-2.5a.75.75 0 0 1 1.5 0v2.5c0 .138.112.25.25.25h10.5a.25.25 0 0 0 .25-.25v-2.5a.75.75 0 0 1 1.5 0v2.5A1.75 1.75 0 0 1 13.25 14Z"/><path d="M11.78 4.72a.749.749 0 1 1-1.06 1.06L8.75 3.811V9.5a.75.75 0 0 1-1.5 0V3.811L5.28 5.78a.749.749 0 1 1-1.06-1.06l3.25-3.25a.749.749 0 0 1 1.06 0l3.25 3.25Z"/></svg>';
    var TRASH_ICON  = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M11 1.75V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.748 1.748 0 0 1 10.595 15h-5.19a1.75 1.75 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.15ZM6.5 1.75V3h3V1.75a.25.25 0 0 0-.25-.25h-2.5a.25.25 0 0 0-.25.25Z"/></svg>';

    var config = window.InlineEditorConfig || {};
    var ckeditorPromise = null;

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function loadCKEditor() {
        if (window.ClassicEditor) { return Promise.resolve(window.ClassicEditor); }
        if (ckeditorPromise) { return ckeditorPromise; }
        ckeditorPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = config.ckeditorCdn;
            script.async = true;
            script.onload = function () {
                if (window.ClassicEditor) { resolve(window.ClassicEditor); }
                else { reject(new Error('CKEditor loaded but ClassicEditor not found')); }
            };
            script.onerror = function () { reject(new Error('Failed to load CKEditor from ' + config.ckeditorCdn)); };
            document.head.appendChild(script);
        });
        return ckeditorPromise;
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
        this.type = el.getAttribute('data-type');
        this.groupId = el.getAttribute('data-group-id') || null;
        this.inputType = el.getAttribute('data-input') || 'input';
        try { this.assetIds = JSON.parse(el.getAttribute('data-asset-ids') || '[]'); } catch (_) { this.assetIds = []; }
        this.placeholder = el.getAttribute('data-placeholder') || '';

        this.originalHtml = null;
        this.originalTagsData = null;
        this.selectedTags = [];

        this.ckeditorInstance = null;
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
            this.mountCKEditor(initialValue);
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
            var holder = document.createElement('div');
            holder.className = 'inline-editor__ckeditor';
            holder.innerHTML = value;
            return holder;
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
        if (!this.groupId || !config.searchTagsUrl) { return; }

        var url = new URL(config.searchTagsUrl, window.location.href);
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
        if (config.csrfTokenName && config.csrfToken) {
            body.append(config.csrfTokenName, config.csrfToken);
        }

        fetch(config.replaceAssetUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': config.csrfToken || '',
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

        if (!window.confirm('Remove this asset? This cannot be undone.')) { return; }

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
        if (config.csrfTokenName && config.csrfToken) {
            body.append(config.csrfTokenName, config.csrfToken);
        }

        fetch(config.replaceAssetUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': config.csrfToken || '',
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

    // Base CKEditor config used when no Craft CKEditor config is selected.
    var CK_BASE_CONFIG = {
        toolbar: ['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', '|', 'undo', 'redo']
    };

    /**
     * Build a CKEditor 5 heading plugin config from an array of level numbers.
     *
     * headingLevels: int[] like [2, 4]  →  heading: { options: [...] }
     * headingLevels: false              →  no heading config (plugin not enabled)
     */
    function buildHeadingConfig(headingLevels) {
        if (!Array.isArray(headingLevels) || headingLevels.length === 0) {
            return null;
        }
        var options = [{ model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' }];
        headingLevels.forEach(function (level) {
            var n = parseInt(level, 10);
            if (!isNaN(n) && n >= 1 && n <= 6) {
                options.push({
                    model: 'heading' + n,
                    view: 'h' + n,
                    title: 'Heading ' + n,
                    class: 'ck-heading_heading' + n
                });
            }
        });
        return { options: options };
    }

    /**
     * Build the final CKEditor config by merging:
     *  - CK_BASE_CONFIG (fallback defaults)
     *  - window.InlineEditorCKData  { toolbar, headingLevels }  — JSON-safe, from PHP
     *  - window.InlineEditorCKJsFn()  — evaluates the CK config's custom JS block,
     *    which may return extraPlugins (with real class references), link decorators, etc.
     *
     * Merge rules:
     *  - toolbar:       CKData wins if present, otherwise base
     *  - heading:       built from CKData.headingLevels (skipped if false)
     *  - extraPlugins:  base + jsFn result concatenated
     *  - link:          shallow-merge (jsFn result wins per key)
     *  - everything else from jsFn result: wins over base
     */
    function buildCKEditorConfig() {
        var data  = window.InlineEditorCKData  || null;   // { toolbar, headingLevels }
        var jsFn  = window.InlineEditorCKJsFn  || null;   // function() { return {...} }

        // Evaluate the custom JS block (may throw if syntax error in user config).
        var extra = {};
        if (typeof jsFn === 'function') {
            try { extra = jsFn() || {}; } catch (_) {}
        }

        var result = {};

        // toolbar
        if (data && Array.isArray(data.toolbar) && data.toolbar.length) {
            result.toolbar = data.toolbar;
        } else {
            result.toolbar = CK_BASE_CONFIG.toolbar;
        }

        // heading — only when headingLevels is a non-empty array
        if (data && data.headingLevels !== false) {
            var headingCfg = buildHeadingConfig(data.headingLevels);
            if (headingCfg) {
                result.heading = headingCfg;
            }
        }

        // extraPlugins — concatenate base + custom
        var basePlugins  = CK_BASE_CONFIG.extraPlugins || [];
        var extraPlugins = Array.isArray(extra.extraPlugins) ? extra.extraPlugins : [];
        if (basePlugins.length || extraPlugins.length) {
            result.extraPlugins = basePlugins.concat(extraPlugins);
        }

        // link — shallow-merge so decorators from jsFn are preserved
        if (extra.link || CK_BASE_CONFIG.link) {
            result.link = Object.assign({}, CK_BASE_CONFIG.link || {}, extra.link || {});
        }

        // all other top-level keys from the jsFn result
        var handled = ['toolbar', 'extraPlugins', 'link'];
        Object.keys(extra).forEach(function (key) {
            if (handled.indexOf(key) === -1) {
                result[key] = extra[key];
            }
        });

        return result;
    }

    Editor.prototype.mountCKEditor = function (value) {
        var self = this;
        loadCKEditor().then(function (ClassicEditor) {
            return ClassicEditor.create(self.formInput, buildCKEditorConfig());
        }).then(function (instance) {
            self.ckeditorInstance = instance;
            instance.setData(value);
            instance.editing.view.focus();
        }).catch(function (err) {
            self.showError('Could not load editor: ' + err.message);
        });
    };

    Editor.prototype.teardownCKEditor = function () {
        if (this.ckeditorInstance) {
            try { this.ckeditorInstance.destroy(); } catch (_) {}
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
        if (this.type === 'ckeditor' && this.ckeditorInstance) {
            return this.ckeditorInstance.getData();
        }
        return this.formInput && 'value' in this.formInput ? this.formInput.value : '';
    };

    // ── Save ───────────────────────────────────────────────────────────────────

    Editor.prototype.save = function () {
        var self = this;
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

        if (config.csrfTokenName && config.csrfToken) {
            body.append(config.csrfTokenName, config.csrfToken);
        }

        fetch(config.saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': config.csrfToken || '',
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
                self.finish(result.body.value);
            }
        }).catch(function (err) {
            self.showError(err && err.message ? err.message : 'Save failed');
            self.setBusy(false);
        });
    };

    // ── Finish (plain fields) ──────────────────────────────────────────────────

    Editor.prototype.finish = function (savedValue) {
        this._stopOutsideClickHandler();
        this.teardownCKEditor();
        this.el.classList.remove('is-editing', 'is-saving');
        this.el.innerHTML = '';

        if (savedValue === '' || savedValue == null) {
            if (this.placeholder) {
                var ph = document.createElement('span');
                ph.className = 'inline-editor__placeholder';
                ph.textContent = this.placeholder;
                this.el.appendChild(ph);
            }
        } else if (this.type === 'ckeditor') {
            this.el.setAttribute('data-value', savedValue);
            this.el.innerHTML = savedValue;
        } else {
            this.el.appendChild(document.createTextNode(savedValue));
        }

        this.el.appendChild(this.trigger);
        this._flashSaved();

        this.editing = false;
        this.originalHtml = null;
        this.dispatch('save', { value: savedValue });
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

    function initAll(root) {
        var nodes = (root || document).querySelectorAll('[data-inline-editor]:not([data-inline-editor-ready])');
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            el.setAttribute('data-inline-editor-ready', '1');
            // eslint-disable-next-line no-new
            new Editor(el);
        }
    }

    window.InlineEditor = { init: initAll };

    ready(function () { initAll(document); });
})();
