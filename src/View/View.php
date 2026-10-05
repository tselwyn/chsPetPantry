<?php
declare(strict_types=1);

namespace Pfpms\View;

use Pfpms\Http\Flash;
use RuntimeException;

/**
 * Plain-PHP templates under templates/. A page template is rendered first, then wrapped in
 * a layout that receives it as $content. Templates escape with e().
 */
final class View
{
    public static function render(string $template, array $vars = [], string $layout = 'layout/app'): void
    {
        $content = self::fetch($template, $vars);
        echo self::fetch($layout, $vars + ['content' => $content, 'flash' => $vars['flash'] ?? Flash::take()]);
    }

    public static function fetch(string $template, array $vars = []): string
    {
        if (!preg_match('~^[a-z0-9_/\-]+$~', $template)) {
            throw new RuntimeException("Invalid template name '$template'");
        }
        $file = APP_ROOT . '/templates/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Template not found: $template");
        }
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })($file, $vars);
    }
}
