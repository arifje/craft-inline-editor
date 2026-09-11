<?php
/**
 * Creates test content in a Craft install for the inline editor integration tests.
 * Usage: php fixtures.php /path/to/install
 *
 * Idempotent-ish: skips objects that already exist by handle.
 */
declare(strict_types=1);

$root = rtrim($argv[1] ?? '', '/');
if ($root === '' || !is_file("$root/bootstrap.php")) {
    fwrite(STDERR, "usage: php fixtures.php /path/to/craft-install\n");
    exit(1);
}
chdir($root);
require "$root/bootstrap.php";
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets as AssetsField;
use craft\fields\PlainText;
use craft\fields\Tags as TagsField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\TagGroup;
use craft\models\Volume;

$isCraft5 = version_compare(Craft::$app->getVersion(), '5.0', '>=');
$ckeVersion = Craft::$app->getPlugins()->getPluginInfo('ckeditor')['version'] ?? '0';
$ckeMajor = (int)explode('.', $ckeVersion)[0];
$fieldsService = Craft::$app->getFields();
$out = fn(string $m) => fwrite(STDOUT, "$m\n");
$out("Craft " . Craft::$app->getVersion() . ", ckeditor $ckeVersion (major $ckeMajor)");

// ── Filesystem + volume ──────────────────────────────────────────────────────
$fsService = Craft::$app->getFs();
$fs = $fsService->getFilesystemByHandle('uploads');
if (!$fs) {
    $fs = new craft\fs\Local([
        'name' => 'Uploads', 'handle' => 'uploads',
        'path' => '@webroot/uploads', 'url' => '@web/uploads', 'hasUrls' => true,
    ]);
    if (!$fsService->saveFilesystem($fs)) { throw new RuntimeException('fs: ' . json_encode($fs->getErrors())); }
    $out('created filesystem uploads');
}
@mkdir("$root/web/uploads", 0777, true);
$volumesService = Craft::$app->getVolumes();
$volume = $volumesService->getVolumeByHandle('uploads');
if (!$volume) {
    $volume = new Volume(['name' => 'Uploads', 'handle' => 'uploads', 'fsHandle' => 'uploads']);
    if (!$volumesService->saveVolume($volume)) { throw new RuntimeException('volume: ' . json_encode($volume->getErrors())); }
    $out('created volume uploads');
}

// ── Tag group ────────────────────────────────────────────────────────────────
$tagsService = Craft::$app->getTags();
$tagGroup = $tagsService->getTagGroupByHandle('topics');
if (!$tagGroup) {
    $tagGroup = new TagGroup(['name' => 'Topics', 'handle' => 'topics']);
    if (!$tagsService->saveTagGroup($tagGroup)) { throw new RuntimeException('taggroup: ' . json_encode($tagGroup->getErrors())); }
    $out('created tag group topics');
}

// ── Shared CKEditor configs (3.x/4.x) ────────────────────────────────────────
$sharedConfigs = [];
if ($ckeMajor < 5) {
    $ckeConfigs = craft\ckeditor\Plugin::getInstance()->getCkeConfigs();
    foreach ($ckeConfigs->getAll() as $existing) { $sharedConfigs[$existing->name] = $existing; }
    $wanted = [
        'Inline Full' => ['toolbar' => ['heading', '|', 'bold', 'italic', 'underline', 'link', 'bulletedList', 'numberedList', 'insertTable', 'sourceEditing', 'insertImage'], 'headingLevels' => [2, 3], 'js' => "return {\n  placeholder: 'Full config placeholder',\n  link: { addTargetToExternalLinks: true }\n};"],
        'Inline Minimal' => ['toolbar' => ['bold', 'italic'], 'headingLevels' => false, 'options' => ['placeholder' => 'Minimal JSON placeholder']],
    ];
    foreach ($wanted as $name => $props) {
        if (isset($sharedConfigs[$name])) { continue; }
        $cfg = new craft\ckeditor\CkeConfig($props + ['name' => $name, 'uid' => craft\helpers\StringHelper::UUID()]);
        if (!$ckeConfigs->save($cfg)) { throw new RuntimeException("ckeconfig $name: " . json_encode($cfg->getErrors())); }
        $sharedConfigs[$name] = $cfg;
        $out("created shared CKEditor config $name ({$cfg->uid})");
    }
}

// ── config/ckeditor files (5.x jsFile) ───────────────────────────────────────
@mkdir("$root/config/ckeditor", 0777, true);
file_put_contents("$root/config/ckeditor/inline-test.js", "return {\n  placeholder: 'Placeholder from inline-test.js'\n};\n");
file_put_contents("$root/config/ckeditor/inline-test.json", "{\n  \"placeholder\": \"Placeholder from inline-test.json\"\n}\n");
@mkdir("$root/config/htmlpurifier", 0777, true);
file_put_contents("$root/config/htmlpurifier/inline.json", json_encode(['HTML.Allowed' => 'p,strong,em,h2,h3,a[href|target|rel|title],ul,ol,li,u,br,table,thead,tbody,tr,th,td,figure,img[src|alt]'], JSON_PRETTY_PRINT));

// ── Fields ───────────────────────────────────────────────────────────────────
function ckeField(string $name, string $handle, array $fieldLevel, ?string $sharedUid): craft\ckeditor\Field
{
    $field = new craft\ckeditor\Field(['name' => $name, 'handle' => $handle]);
    if ($sharedUid !== null) {
        $field->ckeConfig = $sharedUid;
    } elseif (property_exists($field, 'toolbar')) {
        foreach ($fieldLevel as $k => $v) { $field->$k = $v; }
    }
    return $field;
}

$fieldDefs = [
    'inlineBody' => fn() => ckeField('Inline Body', 'inlineBody',
        ['toolbar' => ['heading', '|', 'bold', 'italic', 'underline', 'link', 'bulletedList', 'numberedList', 'insertTable', 'sourceEditing', 'insertImage'], 'headingLevels' => [2, 3], 'js' => "return {\n  placeholder: 'Full config placeholder',\n  link: { addTargetToExternalLinks: true }\n};"],
        $ckeMajor < 5 ? $sharedConfigs['Inline Full']->uid : null),
    'inlineIntro' => fn() => ckeField('Inline Intro', 'inlineIntro',
        ['toolbar' => ['bold', 'italic'], 'headingLevels' => false, 'options' => ['placeholder' => 'Minimal JSON placeholder']],
        $ckeMajor < 5 ? $sharedConfigs['Inline Minimal']->uid : null),
    // 5.x only: config from a JS file; on 3.x/4.x this falls back to defaults (no ckeConfig selected).
    'inlineFromFile' => fn() => ckeField('Inline From File', 'inlineFromFile',
        ['toolbar' => ['heading', 'bold', 'strikethrough'], 'headingLevels' => [4], 'jsFile' => 'inline-test.js'],
        null),
    'inlineMissingFile' => fn() => ckeField('Inline Missing File', 'inlineMissingFile',
        ['toolbar' => ['bold'], 'headingLevels' => false, 'jsFile' => 'does-not-exist.js'],
        null),
    'inlineSummary' => fn() => new PlainText(['name' => 'Inline Summary', 'handle' => 'inlineSummary', 'multiline' => true]),
    'inlineWebsite' => function() use ($isCraft5) {
        if ($isCraft5 && class_exists(craft\fields\Link::class)) {
            return new craft\fields\Link(['name' => 'Inline Website', 'handle' => 'inlineWebsite', 'types' => ['url', 'email', 'entry']]);
        }
        return new craft\fields\Url(['name' => 'Inline Website', 'handle' => 'inlineWebsite']);
    },
    'inlineTopics' => fn() => new TagsField(['name' => 'Inline Topics', 'handle' => 'inlineTopics', 'source' => 'taggroup:' . $tagGroup->uid]),
    'inlineImage' => fn() => new AssetsField(['name' => 'Inline Image', 'handle' => 'inlineImage', 'restrictLocation' => true, 'restrictedLocationSource' => 'volume:' . $volume->uid, 'restrictedLocationSubpath' => 'inline/{slug}', 'allowSubfolders' => false, 'maxRelations' => 1, 'viewMode' => 'large']),
];

$fields = [];
$fieldGroupId = null;
if (!$isCraft5) {
    $fieldGroups = $fieldsService->getAllGroups();
    $fieldGroupId = $fieldGroups ? $fieldGroups[0]->id : null;
}
foreach ($fieldDefs as $handle => $factory) {
    $field = $fieldsService->getFieldByHandle($handle);
    if (!$field) {
        $field = $factory();
        if ($fieldGroupId !== null) { $field->groupId = $fieldGroupId; }
        if (!$fieldsService->saveField($field)) { throw new RuntimeException("field $handle: " . json_encode($field->getErrors())); }
        $out("created field $handle (" . get_class($field) . ")");
    }
    $fields[$handle] = $field;
}

if ($ckeMajor < 5) {
    foreach (['inlineBody' => 'Inline Full', 'inlineIntro' => 'Inline Minimal'] as $handle => $configName) {
        $f = $fields[$handle];
        $uid = $sharedConfigs[$configName]->uid;
        if (($f->ckeConfig ?? null) !== $uid) {
            $f->ckeConfig = $uid;
            if (!$fieldsService->saveField($f)) { throw new RuntimeException("field $handle re-point: " . json_encode($f->getErrors())); }
            $out("re-pointed $handle to shared config $configName ($uid)");
        }
    }
}

// ── Section + entry type ─────────────────────────────────────────────────────
$sectionsService = $isCraft5 ? Craft::$app->getEntries() : Craft::$app->getSections();
$section = $sectionsService->getSectionByHandle('inlineTest');
if (!$section) {
    $layout = new FieldLayout(['type' => Entry::class]);
    $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
    $tab->setElements(array_merge([new craft\fieldlayoutelements\entries\EntryTitleField()], array_map(fn($f) => new CustomField($f), array_values($fields))));
    $layout->setTabs([$tab]);

    if ($isCraft5) {
        $entryType = $sectionsService->getEntryTypeByHandle('inlineTest');
        if (!$entryType) {
            $entryType = new craft\models\EntryType(['name' => 'Inline Test', 'handle' => 'inlineTest', 'hasTitleField' => true]);
            $entryType->setFieldLayout($layout);
            if (!$sectionsService->saveEntryType($entryType)) { throw new RuntimeException('entrytype: ' . json_encode($entryType->getErrors())); }
        }
        $section = new Section([
            'name' => 'Inline Test', 'handle' => 'inlineTest', 'type' => Section::TYPE_CHANNEL,
            'siteSettings' => [new Section_SiteSettings(['siteId' => Craft::$app->getSites()->getPrimarySite()->id, 'enabledByDefault' => true, 'hasUrls' => true, 'uriFormat' => 'inline-test/{slug}', 'template' => 'inline-test'])],
        ]);
        $section->setEntryTypes([$entryType]);
    } else {
        $entryType = new craft\models\EntryType(['name' => 'Inline Test', 'handle' => 'inlineTest', 'hasTitleField' => true]);
        $entryType->setFieldLayout($layout);
        $section = new Section([
            'name' => 'Inline Test', 'handle' => 'inlineTest', 'type' => Section::TYPE_CHANNEL,
            'siteSettings' => [new Section_SiteSettings(['siteId' => Craft::$app->getSites()->getPrimarySite()->id, 'enabledByDefault' => true, 'hasUrls' => true, 'uriFormat' => 'inline-test/{slug}', 'template' => 'inline-test'])],
        ]);
        $section->setEntryTypes([$entryType]);
    }
    if (!$sectionsService->saveSection($section)) { throw new RuntimeException('section: ' . json_encode($section->getErrors())); }
    $out('created section inlineTest');
}
$section = $sectionsService->getSectionByHandle('inlineTest');
$entryType = $section->getEntryTypes()[0];
$hasTitleElement = false;
foreach ($entryType->getFieldLayout()->getTabs() as $t) { foreach ($t->getElements() as $el) { if ($el instanceof craft\fieldlayoutelements\entries\EntryTitleField) { $hasTitleElement = true; } } }
if (empty($entryType->getFieldLayout()->getCustomFields()) || !$hasTitleElement) {
    // Craft 4: saveSection() creates its own default entry type; give it our field layout now.
    $layout = new FieldLayout(['type' => Entry::class]);
    $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
    $tab->setElements(array_merge([new craft\fieldlayoutelements\entries\EntryTitleField()], array_map(fn($f) => new CustomField($f), array_values($fields))));
    $layout->setTabs([$tab]);
    $entryType->hasTitleField = true;
    $entryType->setFieldLayout($layout);
    if (!$sectionsService->saveEntryType($entryType)) { throw new RuntimeException('entrytype layout: ' . json_encode($entryType->getErrors())); }
    $out('assigned field layout to entry type ' . $entryType->handle);
    $section = $sectionsService->getSectionByHandle('inlineTest');
    $entryType = $section->getEntryTypes()[0];
}

// ── Entries ──────────────────────────────────────────────────────────────────
function ensureEntry(Section $section, $entryType, string $slug, string $title, array $fieldValues, callable $out): Entry
{
    $entry = Entry::find()->section($section->handle)->slug($slug)->status(null)->one();
    if ($entry) {
        if (trim((string)$entry->title) === '') {
            $entry->title = $title;
            if (!Craft::$app->getElements()->saveElement($entry)) { throw new RuntimeException("entry $slug title: " . json_encode($entry->getErrors())); }
            $out("restored title of $slug (#{$entry->id})");
        }
        if ($entry->getFieldValue('inlineSummary') === null && isset($fieldValues['inlineSummary'])) {
            $entry->setFieldValues($fieldValues);
            if (!Craft::$app->getElements()->saveElement($entry)) { throw new RuntimeException("entry $slug update: " . json_encode($entry->getErrors())); }
            $out("filled empty entry $slug (#{$entry->id})");
        }
        return $entry;
    }
    $entry = new Entry(['sectionId' => $section->id, 'typeId' => $entryType->id, 'title' => $title, 'slug' => $slug, 'enabled' => true]);
    $entry->setFieldValues($fieldValues);
    if (!Craft::$app->getElements()->saveElement($entry)) { throw new RuntimeException("entry $slug: " . json_encode($entry->getErrors())); }
    $out("created entry $slug (#{$entry->id})");
    return $entry;
}

$entryA = ensureEntry($section, $entryType, 'alpha', 'Alpha entry', [
    'inlineBody' => '<p>Initial body</p>',
    'inlineIntro' => '<p>Intro <em>italic</em> text.</p>',
    'inlineFromFile' => '<p>From file <s>strike</s>.</p>',
    'inlineMissingFile' => '<p>Missing file field.</p>',
    'inlineSummary' => "Line one\nLine two",
    'inlineWebsite' => 'https://example.com/site',
], $out);
// Second pass: give the body a reference-tag link to the entry itself now that it has an ID.
$rawBody = (string)($entryA->getFieldValue('inlineBody')?->getRawContent() ?? '');
if (!str_contains($rawBody, '{entry:')) {
    $entryA->setFieldValue('inlineBody', '<h2>Body heading</h2><p>Body with <strong>bold</strong>, <a href="https://example.com/ext" target="_blank" rel="noopener" title="Ext">an external link</a> and <a href="{entry:' . $entryA->id . '@1:url}">a ref link</a>.</p><ul><li>One</li><li>Two</li></ul>');
    if (!Craft::$app->getElements()->saveElement($entryA)) { throw new RuntimeException('alpha body: ' . json_encode($entryA->getErrors())); }
    $out("updated alpha body with a ref link → entry #{$entryA->id}");
}

$entryB = ensureEntry($section, $entryType, 'beta', 'Beta entry', [
    'inlineBody' => '<p>Before nested</p><p>After nested</p>',
    'inlineIntro' => '<p>Beta intro</p>',
    'inlineSummary' => 'Beta summary',
], $out);
$nested = '<p>Before nested</p><craft-entry data-entry-id="999999"></craft-entry><p>After nested</p>';
if (!str_contains((string)($entryB->getFieldValue('inlineBody')?->getRawContent() ?? ''), '<craft-entry')) {
    if ($isCraft5) {
        $layoutField = $entryB->getFieldLayout()->getFieldByHandle('inlineBody');
        $key = $layoutField->layoutElement->uid ?? $layoutField->uid;
        $row = (new craft\db\Query())->select(['id', 'content'])->from('{{%elements_sites}}')->where(['elementId' => $entryB->id, 'siteId' => $entryB->siteId])->one();
        $content = $row['content'];
        while (is_string($content)) { $content = json_decode($content, true); } // undo any double encoding from earlier runs
        $content = is_array($content) ? $content : [];
        $content[$key] = $nested;
        Craft::$app->getDb()->createCommand()->update('{{%elements_sites}}', ['content' => $content], ['id' => $row['id']])->execute();
    } else {
        $column = craft\helpers\ElementHelper::fieldColumnFromField($fields['inlineBody']);
        Craft::$app->getDb()->createCommand()->update('{{%content}}', [$column => $nested], ['elementId' => $entryB->id, 'siteId' => $entryB->siteId])->execute();
    }
    Craft::$app->getElements()->invalidateCachesForElement($entryB);
    $out("wrote nested-entry markup into beta body (#{$entryB->id}) directly");
}

// ── Users & groups ───────────────────────────────────────────────────────────
Craft::$app->setEdition(Craft::Pro);
$groupsService = Craft::$app->getUserGroups();
$permissionsService = Craft::$app->getUserPermissions();
$groups = [];
foreach (['inlineWriters' => 'Inline Writers', 'inlineReaders' => 'Inline Readers'] as $handle => $name) {
    $group = $groupsService->getGroupByHandle($handle);
    if (!$group) {
        $group = new craft\models\UserGroup(['name' => $name, 'handle' => $handle]);
        $out("creating group $handle");
    }
    // (Re)save so the group's project config entry exists even if an earlier run was not flushed.
    if (!$groupsService->saveGroup($group)) { throw new RuntimeException("group $handle: " . json_encode($group->getErrors())); }
    $groups[$handle] = $group;
}
$permissionsService->saveGroupPermissions($groups['inlineWriters']->id, [
    "viewEntries:{$section->uid}", "saveEntries:{$section->uid}", "createEntries:{$section->uid}",
    "viewPeerEntries:{$section->uid}", "savePeerEntries:{$section->uid}",
    "viewAssets:{$volume->uid}", "saveAssets:{$volume->uid}", "deleteAssets:{$volume->uid}", "viewPeerAssets:{$volume->uid}", "savePeerAssets:{$volume->uid}", "deletePeerAssets:{$volume->uid}",
]);
$permissionsService->saveGroupPermissions($groups['inlineReaders']->id, []);
$out('saved group permissions');

foreach (['writer' => 'inlineWriters', 'reader' => 'inlineReaders', 'outsider' => null] as $username => $groupHandle) {
    $user = User::find()->username($username)->status(null)->one();
    if (!$user) {
        $user = new User(['username' => $username, 'email' => "$username@example.test", 'newPassword' => 'password123', 'active' => true]);
        if (!Craft::$app->getElements()->saveElement($user)) { throw new RuntimeException("user $username: " . json_encode($user->getErrors())); }
        if ($groupHandle) { Craft::$app->getUsers()->assignUserToGroups($user->id, [$groups[$groupHandle]->id]); }
        $out("created user $username" . ($groupHandle ? " in $groupHandle" : ''));
    }
}

// ── Plugin settings ──────────────────────────────────────────────────────────
$plugin = Craft::$app->getPlugins()->getPlugin('inline-editor');
$savedSettings = Craft::$app->getPlugins()->savePluginSettings($plugin, [
    'allowedGroupIds' => [$groups['inlineWriters']->id, $groups['inlineReaders']->id],
    'ckeditorPurifierConfig' => '',
    'ckeditorConfig' => '',
]);
$out('saved inline-editor settings: ' . var_export($savedSettings, true) . ' errors=' . json_encode($plugin->getSettings()->getErrors()) . ' pc=' . json_encode(Craft::$app->getProjectConfig()->get('plugins.inline-editor.settings')));

// ── Templates ────────────────────────────────────────────────────────────────
@mkdir("$root/templates", 0777, true);
file_put_contents("$root/templates/inline-test.twig", <<<'TWIG'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ entry.title }}</title></head>
<body>
<p id="user">{{ currentUser ? currentUser.username : 'guest' }} | canInlineEdit={{ canInlineEdit() ? 'yes' : 'no' }}</p>
<h1 id="title">{{ inlineEditable(entry, 'title', { tag: 'span' }) }}</h1>
<div id="body">{{ inlineEditable(entry, 'inlineBody') }}</div>
<div id="intro">{{ inlineEditable(entry, 'inlineIntro') }}</div>
<div id="fromfile">{{ inlineEditable(entry, 'inlineFromFile') }}</div>
<div id="missingfile">{{ inlineEditable(entry, 'inlineMissingFile') }}</div>
<p id="summary">{{ inlineEditable(entry, 'inlineSummary') }}</p>
<p id="website">{{ inlineEditable(entry, 'inlineWebsite') }}</p>
<div id="topics">{{ inlineEditable(entry, 'inlineTopics') }}</div>
<div id="image">{{ inlineEditable(entry, 'inlineImage', { innerHtml: entry.inlineImage.one() ? '<img src="' ~ entry.inlineImage.one().url ~ '" alt="">' : '<em>no image</em>' }) }}</div>
</body></html>
TWIG);
file_put_contents("$root/templates/login.twig", <<<'TWIG'
<!doctype html><html><body>
<form method="post" accept-charset="UTF-8">
{{ csrfInput() }}{{ actionInput('users/login') }}
<input type="text" name="loginName" value=""><input type="password" name="password" value=""><button>Login</button>
</form></body></html>
TWIG);
$out("templates written; entries: alpha #{$entryA->id}, beta #{$entryB->id}");

// Flush project config changes to config/project/ (a bare script never fires the after-request event that normally does this).
Craft::$app->getProjectConfig()->saveModifiedConfigData();
$out('project config flushed');
