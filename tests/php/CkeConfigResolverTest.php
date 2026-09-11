<?php

namespace arifje\inlineeditor\tests;

use arifje\inlineeditor\helpers\CkeConfigResolver;
use arifje\inlineeditor\models\ResolvedCkeConfig;
use PHPUnit\Framework\TestCase;

/**
 * Field-level (craftcms/ckeditor 5.x) and shared-config (3.x/4.x) resolution,
 * using plain objects shaped like the real field / CkeConfig classes.
 */
final class CkeConfigResolverTest extends TestCase
{
    /** Shape of a craftcms/ckeditor 5.x field. */
    private function fieldLevelField(array $props = []): object
    {
        return (object)array_merge([
            'name' => 'Body',
            'handle' => 'body',
            'toolbar' => ['heading', '|', 'bold', 'italic', 'link', 'sourceEditing'],
            'headingLevels' => [2, 3],
            'options' => null,
            'js' => null,
            'jsFile' => null,
            'css' => null,
            'cssFile' => null,
            'sourceEditingGroups' => ['__ADMINS__'],
        ], $props);
    }

    /** Shape of a craftcms/ckeditor 3.x/4.x field. */
    private function sharedConfigField(array $props = []): object
    {
        return (object)array_merge([
            'name' => 'Body',
            'handle' => 'body',
            'ckeConfig' => 'uid-simple',
            'sourceEditingGroups' => ['__ADMINS__'],
        ], $props);
    }

    /** Shape of craft\ckeditor\CkeConfig. */
    private function sharedConfigs(): array
    {
        return [
            'uid-simple' => (object)[
                'uid' => 'uid-simple',
                'name' => 'Simple',
                'toolbar' => ['bold', 'italic'],
                'headingLevels' => false,
                'options' => null,
                'js' => null,
                'css' => null,
            ],
            'uid-full' => (object)[
                'uid' => 'uid-full',
                'name' => 'Full',
                'toolbar' => ['heading', 'anchor', 'bold', 'sourceEditing'],
                'headingLevels' => [1, 2, 3, 4, 5, 6],
                'options' => null,
                'js' => "return { link: { addTargetToExternalLinks: true } };",
                'css' => '.ck-content h2 { color: red; }',
            ],
        ];
    }

    private function resolverWithSharedConfigs(array $files = []): CkeConfigResolver
    {
        $configs = $this->sharedConfigs();
        return new CkeConfigResolver(
            static fn(string $uid): ?object => $configs[$uid] ?? null,
            static fn(string $file): ?string => $files[$file] ?? null,
        );
    }

    private function resolverWithoutSharedConfigs(array $files = []): CkeConfigResolver
    {
        return new CkeConfigResolver(null, static fn(string $file): ?string => $files[$file] ?? null);
    }

    // ── Detection ─────────────────────────────────────────────────────────────

    public function testDetectsWhereTheConfigurationLives(): void
    {
        self::assertTrue(CkeConfigResolver::hasFieldLevelConfig($this->fieldLevelField()));
        self::assertFalse(CkeConfigResolver::hasSharedConfigReference($this->fieldLevelField()));
        self::assertFalse(CkeConfigResolver::hasFieldLevelConfig($this->sharedConfigField()));
        self::assertTrue(CkeConfigResolver::hasSharedConfigReference($this->sharedConfigField()));
    }

    // ── craftcms/ckeditor 5.x: configuration on the field ─────────────────────

    public function testFieldLevelConfigIsTakenFromTheFieldItself(): void
    {
        $config = $this->resolverWithoutSharedConfigs()->resolve($this->fieldLevelField());

        self::assertSame(ResolvedCkeConfig::SOURCE_FIELD, $config->source);
        self::assertSame(['heading', '|', 'bold', 'italic', 'link', 'sourceEditing'], $config->toolbar);
        self::assertSame([2, 3], $config->headingLevels);
        self::assertNull($config->options);
        self::assertNull($config->js);
        self::assertSame('Body', $config->name);
        self::assertSame([], $config->warnings);
    }

    public function testTwoFieldsGetTheirOwnConfiguration(): void
    {
        $resolver = $this->resolverWithoutSharedConfigs();
        $body = $resolver->resolve($this->fieldLevelField());
        $intro = $resolver->resolve($this->fieldLevelField([
            'name' => 'Intro',
            'handle' => 'intro',
            'toolbar' => ['bold'],
            'headingLevels' => false,
        ]));

        self::assertSame(['heading', '|', 'bold', 'italic', 'link', 'sourceEditing'], $body->toolbar);
        self::assertSame(['bold'], $intro->toolbar);
        self::assertFalse($intro->headingLevels);
    }

    public function testInlineJavaScriptConfigIsPreserved(): void
    {
        $config = $this->resolverWithoutSharedConfigs()->resolve($this->fieldLevelField([
            'js' => "return { placeholder: 'Type here' };",
        ]));

        self::assertSame("return { placeholder: 'Type here' };", $config->js);
        self::assertNull($config->options);
    }

    public function testJsonOptionsWinOverInlineJavaScript(): void
    {
        $config = $this->resolverWithoutSharedConfigs()->resolve($this->fieldLevelField([
            'options' => ['placeholder' => 'From JSON'],
            'js' => "return { placeholder: 'From JS' };",
        ]));

        self::assertSame(['placeholder' => 'From JSON'], $config->options);
        self::assertNull($config->js, 'Craft applies either JSON options or JS, never both');
    }

    public function testJsFileWithJavaScriptIsReadFromConfigDirectory(): void
    {
        $resolver = $this->resolverWithoutSharedConfigs(['custom.js' => "return { removePlugins: ['Bold'] };"]);
        $config = $resolver->resolve($this->fieldLevelField(['jsFile' => 'custom.js']));

        self::assertSame("return { removePlugins: ['Bold'] };", $config->js);
        self::assertNull($config->options);
        self::assertSame([], $config->warnings);
    }

    public function testJsFileWithJsonBecomesOptions(): void
    {
        $resolver = $this->resolverWithoutSharedConfigs(['custom.json' => '{"placeholder": "From file"}']);
        $config = $resolver->resolve($this->fieldLevelField(['jsFile' => 'custom.json', 'js' => 'return {}']));

        self::assertSame(['placeholder' => 'From file'], $config->options);
        self::assertNull($config->js);
    }

    public function testMissingJsFileIsReportedNotSwallowed(): void
    {
        $config = $this->resolverWithoutSharedConfigs()->resolve($this->fieldLevelField(['jsFile' => 'gone.js']));

        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('gone.js', $config->warnings[0]);
        self::assertStringContainsString('config/ckeditor/', $config->warnings[0]);
        self::assertSame(ResolvedCkeConfig::SOURCE_FIELD, $config->source, 'the rest of the field config still applies');
        self::assertSame(['heading', '|', 'bold', 'italic', 'link', 'sourceEditing'], $config->toolbar);
    }

    public function testInvalidJsonFileIsReported(): void
    {
        $resolver = $this->resolverWithoutSharedConfigs(['broken.json' => '{not json']);
        $config = $resolver->resolve($this->fieldLevelField(['jsFile' => 'broken.json']));

        self::assertNull($config->options);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('not valid JSON', $config->warnings[0]);
    }

    public function testCssFileIsReadAndMissingCssFileIsReported(): void
    {
        $resolver = $this->resolverWithoutSharedConfigs(['styles.css' => 'h2 { color: red; }']);

        $ok = $resolver->resolve($this->fieldLevelField(['cssFile' => 'styles.css']));
        self::assertSame('h2 { color: red; }', $ok->css);

        $missing = $resolver->resolve($this->fieldLevelField(['cssFile' => 'nope.css', 'css' => 'inline { }']));
        self::assertSame('inline { }', $missing->css, 'inline CSS is kept when the file cannot be read');
        self::assertStringContainsString('nope.css', $missing->warnings[0]);
    }

    public function testLegacyOverrideSettingIsIgnoredWithWarningOnFieldLevelPlugins(): void
    {
        $config = $this->resolverWithoutSharedConfigs()->resolve($this->fieldLevelField(), 'uid-full');

        self::assertSame(ResolvedCkeConfig::SOURCE_FIELD, $config->source);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('ignored', $config->warnings[0]);
        self::assertStringContainsString('Settings → Plugins → Inline Editor', $config->warnings[0]);
    }

    // ── craftcms/ckeditor 3.x/4.x: shared configs ────────────────────────────

    public function testSharedConfigSelectedOnTheFieldIsUsed(): void
    {
        $config = $this->resolverWithSharedConfigs()->resolve($this->sharedConfigField());

        self::assertSame(ResolvedCkeConfig::SOURCE_SHARED_CONFIG, $config->source);
        self::assertSame(['bold', 'italic'], $config->toolbar);
        self::assertFalse($config->headingLevels);
        self::assertSame('Simple', $config->name);
        self::assertSame([], $config->warnings);
    }

    public function testSharedConfigJsAndCssArePreservedAndAnchorIsRenamed(): void
    {
        $config = $this->resolverWithSharedConfigs()->resolve($this->sharedConfigField(['ckeConfig' => 'uid-full']));

        self::assertSame(['heading', 'bookmark', 'bold', 'sourceEditing'], $config->toolbar);
        self::assertSame([1, 2, 3, 4, 5, 6], $config->headingLevels);
        self::assertSame("return { link: { addTargetToExternalLinks: true } };", $config->js);
        self::assertSame('.ck-content h2 { color: red; }', $config->css);
    }

    public function testOverrideSettingWinsOverTheFieldSelection(): void
    {
        $config = $this->resolverWithSharedConfigs()->resolve($this->sharedConfigField(), 'uid-full');

        self::assertSame(ResolvedCkeConfig::SOURCE_OVERRIDE, $config->source);
        self::assertSame('Full', $config->name);
        self::assertSame([], $config->warnings);
    }

    public function testUnknownOverrideFallsBackToTheFieldConfigWithWarning(): void
    {
        $config = $this->resolverWithSharedConfigs()->resolve($this->sharedConfigField(), 'uid-missing');

        self::assertSame(ResolvedCkeConfig::SOURCE_SHARED_CONFIG, $config->source);
        self::assertSame(['bold', 'italic'], $config->toolbar);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('uid-missing', $config->warnings[0]);
        self::assertStringContainsString('does not exist', $config->warnings[0]);
    }

    public function testUnknownFieldConfigUsesPluginDefaultsWithWarning(): void
    {
        $config = $this->resolverWithSharedConfigs()->resolve($this->sharedConfigField(['ckeConfig' => 'uid-deleted']));

        self::assertSame(ResolvedCkeConfig::SOURCE_DEFAULT, $config->source);
        self::assertSame(CkeConfigResolver::DEFAULT_TOOLBAR, $config->toolbar);
        self::assertSame(CkeConfigResolver::DEFAULT_HEADING_LEVELS, $config->headingLevels);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('uid-deleted', $config->warnings[0]);
        self::assertStringContainsString('no longer exists', $config->warnings[0]);
    }

    public function testFieldWithoutSelectedConfigUsesDefaultsSilentlyLikeTheControlPanel(): void
    {
        $config = $this->resolverWithSharedConfigs()->resolve($this->sharedConfigField(['ckeConfig' => null]));

        self::assertSame(ResolvedCkeConfig::SOURCE_DEFAULT, $config->source);
        self::assertSame(CkeConfigResolver::DEFAULT_TOOLBAR, $config->toolbar);
        self::assertSame([], $config->warnings);
    }

    public function testSharedConfigRequestedWithoutSharedConfigSupportIsReported(): void
    {
        $config = $this->resolverWithoutSharedConfigs()->resolve($this->sharedConfigField());

        self::assertSame(ResolvedCkeConfig::SOURCE_DEFAULT, $config->source);
        self::assertNotEmpty($config->warnings);
        self::assertStringContainsString('does not provide shared configs', $config->warnings[0]);
    }

    // ── Cross-cutting ─────────────────────────────────────────────────────────

    public function testUnrecognisableFieldGetsDefaultsAndAWarning(): void
    {
        $config = $this->resolverWithSharedConfigs()->resolve((object)['name' => 'Odd', 'handle' => 'odd']);

        self::assertSame(ResolvedCkeConfig::SOURCE_DEFAULT, $config->source);
        self::assertSame(CkeConfigResolver::DEFAULT_TOOLBAR, $config->toolbar);
        self::assertStringContainsString('"Odd" (handle: odd)', $config->warnings[0]);
    }

    public function testSourceEditingIsRemovedWhenNotAllowed(): void
    {
        $config = $this->resolverWithoutSharedConfigs()->resolve($this->fieldLevelField(), '', false);
        self::assertSame(['heading', '|', 'bold', 'italic', 'link'], $config->toolbar);

        $config = $this->resolverWithoutSharedConfigs()->resolve($this->fieldLevelField(), '', true);
        self::assertContains('sourceEditing', $config->toolbar);
    }

    public function testSourceEditingPermissionMirrorsTheCkeditorPlugin(): void
    {
        self::assertTrue(CkeConfigResolver::isSourceEditingAllowed('*', false, []));
        self::assertFalse(CkeConfigResolver::isSourceEditingAllowed(null, true, []));
        self::assertFalse(CkeConfigResolver::isSourceEditingAllowed([], true, []));
        self::assertTrue(CkeConfigResolver::isSourceEditingAllowed(['__ADMINS__'], true, []));
        self::assertFalse(CkeConfigResolver::isSourceEditingAllowed(['__ADMINS__'], false, ['group-a']));
        self::assertTrue(CkeConfigResolver::isSourceEditingAllowed(['group-a'], false, ['group-a', 'group-b']));
        self::assertFalse(CkeConfigResolver::isSourceEditingAllowed(['group-c'], false, ['group-a']));
    }

    public function testToolbarAndHeadingLevelNormalisation(): void
    {
        self::assertSame(['bold', 'bookmark'], CkeConfigResolver::normalizeToolbar(['bold', '', 42, 'anchor', null]));
        self::assertSame([1, 3], CkeConfigResolver::normalizeHeadingLevels(['3', 1, '3', 9, 'x']));
        self::assertFalse(CkeConfigResolver::normalizeHeadingLevels([]));
        self::assertFalse(CkeConfigResolver::normalizeHeadingLevels(false));
        self::assertFalse(CkeConfigResolver::normalizeHeadingLevels(''));
    }

    public function testJsDataIsJsonSafeAndExcludesTheJavaScriptBody(): void
    {
        $config = $this->resolverWithSharedConfigs()->resolve($this->sharedConfigField(['ckeConfig' => 'uid-full']));
        $data = $config->toJsData();

        self::assertSame(['heading', 'bookmark', 'bold', 'sourceEditing'], $data['toolbar']);
        self::assertSame([1, 2, 3, 4, 5, 6], $data['headingLevels']);
        self::assertTrue($data['hasJs']);
        self::assertArrayNotHasKey('js', $data);
        self::assertIsString(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
