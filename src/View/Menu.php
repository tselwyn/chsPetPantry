<?php
declare(strict_types=1);

namespace Pfpms\View;

use Pfpms\Http\Context;
use Pfpms\Http\Request;

final class Menu
{
    /** @return array<string, list<array{file:string,label:string}>> group => entries the user may open */
    public static function for(Context $ctx): array
    {
        $groups = [];
        foreach (require __DIR__ . '/nav.php' as $item) {
            if ($ctx->can($item['capability']) && is_file(Request::publicDir() . '/' . $item['file'])) {
                $groups[$item['group']][] = ['file' => $item['file'], 'label' => $item['label']];
            }
        }
        return $groups;
    }
}
