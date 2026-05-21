/* Inline Editor — frontend runtime.
   Renders an edit button on every [data-inline-editor] element, handles
   swap-to-form, save (AJAX), and discard. Pure vanilla JS; no jQuery. */

(function () {
    'use strict';

    var EDIT_ICON = '<svg viewBox="0 0 16 16" aria-hidden="true">'
        + '<path d="M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.21.21-.47.364-.756.445l-3.251.93a.75.75 0 0 1-.927-.928l.929-3.25c.081-.286.235-.547.445-.758l8.61-8.609Zm1.414 1.06a.25.25 0 0 0-.354 0L10.811 3.75 12.25 5.189l1.263-1.263a.25.25 0 0 0 0-.354l-1.086-1.085ZM11.189 6.25 9.75 4.811l-6.286 6.287a.25.25 0 0 0-.064.108l-.558 1.953 1.953-.558a.25.25 0 0 0 .108-.064l6.286-6.287Z"/>'
        + '</svg>';

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
        if (window.ClassicEditor) {
            return Promise.resolve(window.ClassicEditor);
        }
        if (ckeditorPromise) {
            return ckeditorPromise;
        }
        ckeditorPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = config.ckeditorCdn;
            script.async = true;
            script.onload = function () {
                if (window.ClassicEditor) {
                    resolve(window.ClassicEditor);
                } else {
                    reject(new Error('CKEditor loaded but ClassicEditor not found'));
                }
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

    function Editor(el) {
        this.el = el;
        this.elementId = el.getAttribute('data-element-id');
        this.siteId = el.getAttribute('data-site-id');
        this.field = el.getAttribute('data-field');
        this.type = el.getAttribute('data-type');
        this.inputType = el.getAttribute('data-input') || 'input';
        this.placeholder = el.getAttribute('data-placeholder') || '';
        this.originalHtml = null;
        this.ckeditorInstance = null;
        this.editing = false;

        this.trigger = createTrigger();
        this.trigger.addEventListener('click', this.start.bind(this));
        this.el.appendChild(this.trigger);
    }

    Editor.prototype.currentValue = function () {
        if (this.type === 'ckeditor') {
            return this.el.innerHTML;
        }
        // Strip the trigger button to read the raw text.
        var clone = this.el.cloneNode(true);
        var trig = clone.querySelector('.inline-editor__trigger');
        if (trig) { trig.remove(); }
        var placeholder = clone.querySelector('.inline-editor__placeholder');
        if (placeholder) { placeholder.remove(); }
        return clone.textContent.replace(/^\s+|\s+$/g, '');
    };

    Editor.prototype.start = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        if (this.editing) { return; }
        this.editing = true;

        this.originalHtml = this.el.innerHTML;
        this.el.classList.add('is-editing');

        var initialValue = this.currentValue();
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

        // Keyboard shortcuts: Esc cancels, Cmd/Ctrl+Enter saves on textareas,
        // Enter saves on single-line inputs.
        input.addEventListener('keydown', this.onKeydown.bind(this));

        if (this.type === 'ckeditor') {
            this.mountCKEditor(initialValue);
        } else {
            input.focus();
            if (typeof input.select === 'function') { input.select(); }
        }
    };

    Editor.prototype.buildInput = function (value) {
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

    Editor.prototype.mountCKEditor = function (value) {
        var self = this;
        loadCKEditor().then(function (ClassicEditor) {
            return ClassicEditor.create(self.formInput, {
                // Minimal but practical toolbar that mirrors common Craft CKEditor configs.
                toolbar: ['bold', 'italic', 'link', 'bulletedList', 'numberedList', '|', 'undo', 'redo']
            });
        }).then(function (instance) {
            self.ckeditorInstance = instance;
            instance.setData(value);
            instance.editing.view.focus();
        }).catch(function (err) {
            self.showError('Could not load editor: ' + err.message);
        });
    };

    Editor.prototype.onKeydown = function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            this.cancel();
            return;
        }
        if (e.key === 'Enter') {
            var isTextarea = e.target && e.target.tagName === 'TEXTAREA';
            if (!isTextarea || e.metaKey || e.ctrlKey) {
                e.preventDefault();
                this.save();
            }
        }
    };

    Editor.prototype.readValue = function () {
        if (this.type === 'ckeditor' && this.ckeditorInstance) {
            return this.ckeditorInstance.getData();
        }
        return this.formInput && 'value' in this.formInput ? this.formInput.value : '';
    };

    Editor.prototype.save = function () {
        var self = this;
        var value = this.readValue();
        this.setBusy(true);
        this.showError(null);

        var body = new URLSearchParams();
        body.append('elementId', this.elementId);
        body.append('siteId', this.siteId);
        body.append('field', this.field);
        body.append('value', value);
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
            self.finish(result.body.value);
        }).catch(function (err) {
            self.showError(err && err.message ? err.message : 'Save failed');
            self.setBusy(false);
        });
    };

    Editor.prototype.finish = function (savedValue) {
        this.teardownCKEditor();
        this.el.classList.remove('is-editing');
        this.el.classList.remove('is-saving');
        this.el.innerHTML = '';

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
        this.el.classList.add('is-saved-flash');
        var self = this;
        setTimeout(function () { self.el.classList.remove('is-saved-flash'); }, 700);

        this.editing = false;
        this.originalHtml = null;
        this.dispatch('save', { value: savedValue });
    };

    Editor.prototype.cancel = function () {
        this.teardownCKEditor();
        this.el.classList.remove('is-editing');
        this.el.innerHTML = this.originalHtml || '';
        // Re-attach trigger (it was part of originalHtml but its listener was lost).
        var existingTrigger = this.el.querySelector('.inline-editor__trigger');
        if (existingTrigger) {
            existingTrigger.replaceWith(this.trigger);
        } else {
            this.el.appendChild(this.trigger);
        }
        this.editing = false;
        this.originalHtml = null;
        this.dispatch('cancel');
    };

    Editor.prototype.teardownCKEditor = function () {
        if (this.ckeditorInstance) {
            try { this.ckeditorInstance.destroy(); } catch (e) { /* ignore */ }
            this.ckeditorInstance = null;
        }
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
        if (!message) {
            this.errorEl.hidden = true;
            this.errorEl.textContent = '';
            return;
        }
        this.errorEl.hidden = false;
        this.errorEl.textContent = message;
    };

    Editor.prototype.dispatch = function (name, detail) {
        var event = new CustomEvent('inline-editor:' + name, {
            bubbles: true,
            cancelable: false,
            detail: Object.assign({ editor: this }, detail || {})
        });
        this.el.dispatchEvent(event);
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

    function initAll(root) {
        var nodes = (root || document).querySelectorAll('[data-inline-editor]:not([data-inline-editor-ready])');
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            el.setAttribute('data-inline-editor-ready', '1');
            // eslint-disable-next-line no-new
            new Editor(el);
        }
    }

    // Expose a tiny API so host pages can re-scan after dynamic content insertion.
    window.InlineEditor = {
        init: initAll
    };

    ready(function () { initAll(document); });
})();
