<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Device\RegistrationSheet;
use Pfpms\Station\StationAssets;
use Pfpms\Station\StationShell;

/** public/station/manifest.json and the icons it names (50-design §7.1, D-09): what the tablet installs from. */
final class StationManifestTest extends TestCase
{
    private const MANIFEST = APP_ROOT . '/public/station/manifest.json';

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        return json_decode((string) file_get_contents(self::MANIFEST), true, 8, JSON_THROW_ON_ERROR);
    }

    public function testNameIsTheRegistrationSheetAppName(): void
    {
        $this->assertSame(RegistrationSheet::APP_NAME, self::manifest()['name'], 'the sheet tells people to open the app by this name');
        $this->assertSame('Pet Pantry Station', RegistrationSheet::APP_NAME);
    }

    public function testShortNameIsTheAppNameToo(): void
    {
        $this->assertSame(RegistrationSheet::APP_NAME, self::manifest()['short_name'], 'the home-screen label is the name on the sheet');
    }

    public function testStartUrlAndScopeAreRelative(): void
    {
        $manifest = self::manifest();
        $this->assertSame('./', $manifest['start_url'], 'resolves against the manifest URL: works under / and in a subdirectory');
        $this->assertSame('./', $manifest['scope']);
        $this->assertArrayNotHasKey('id', $manifest, 'the id defaults to start_url');
    }

    public function testDisplayIsStandalone(): void
    {
        $this->assertSame('standalone', self::manifest()['display']);
    }

    public function testIconsExistWithTheirSizes(): void
    {
        $icons = self::manifest()['icons'];
        $this->assertCount(3, $icons);
        foreach ($icons as $icon) {
            $this->assertSame(['src', 'sizes', 'type', 'purpose'], array_keys($icon));
            $file = StationAssets::root() . '/' . $icon['src'];
            $this->assertFileExists($file);
            $info = getimagesize($file);
            $this->assertIsArray($info, $icon['src'] . ' is an image');
            $this->assertSame($icon['sizes'], $info[0] . 'x' . $info[1], $icon['src'] . ' has the size the manifest gives');
            $this->assertSame('image/png', $info['mime']);
            $this->assertSame('image/png', $icon['type']);
            $this->assertContains($icon['src'], StationAssets::FILES, $icon['src'] . ' is precached');
        }
        $this->assertSame(['192x192', '512x512', '512x512'], array_column($icons, 'sizes'));
    }

    public function testThereIsOneMaskableIconAndTheOthersAreAny(): void
    {
        $purposes = array_column(self::manifest()['icons'], 'purpose', 'src');
        $this->assertSame(['icons/icon-192.png' => 'any', 'icons/icon-512.png' => 'any', 'icons/icon-maskable-512.png' => 'maskable'], $purposes);
    }

    public function testColoursAreTheAppTokens(): void
    {
        $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/app.css');
        $token = function (string $name) use ($css): string {
            $this->assertSame(1, preg_match('/--' . preg_quote($name, '/') . ':\s*(#[0-9a-f]{6})\s*;/i', $css, $m), "--$name is in app.css");
            return strtolower($m[1]);
        };
        $manifest = self::manifest();
        $this->assertSame($token('brand'), $manifest['theme_color']);
        $this->assertSame($token('bg'), $manifest['background_color']);
        $this->assertStringContainsString('<meta name="theme-color" content="' . $manifest['theme_color'] . '">', StationShell::html('x'),
            'the shell uses the same theme colour');
    }

    public function testTheManifestHasOnlyTheKnownMembers(): void
    {
        $this->assertSame(['name', 'short_name', 'description', 'lang', 'dir', 'start_url', 'scope', 'display', 'background_color', 'theme_color', 'icons'],
            array_keys(self::manifest()));
        $this->assertSame('en', self::manifest()['lang']);
        $this->assertSame('ltr', self::manifest()['dir']);
        $bytes = (string) file_get_contents(self::MANIFEST);
        $this->assertStringNotContainsString("\r", $bytes, 'LF line endings (the bytes are hashed into the build)');
        $this->assertStringEndsWith("}\n", $bytes, 'one final newline');
        $this->assertStringStartsWith("{\n  \"name\": ", $bytes, 'two-space indent');
    }

    public function testTheAppleTouchIconIs180AndLinkedByTheShell(): void
    {
        $file = StationAssets::root() . '/icons/apple-touch-icon.png';
        $this->assertFileExists($file);
        $info = getimagesize($file);
        $this->assertIsArray($info);
        $this->assertSame([180, 180, 'image/png'], [$info[0], $info[1], $info['mime']]);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">', StationShell::html('x'), 'iOS reads the link, not the manifest');
        $this->assertNotContains('icons/apple-touch-icon.png', array_column(self::manifest()['icons'], 'src'));
        $this->assertContains('icons/apple-touch-icon.png', StationAssets::FILES);
    }
}
