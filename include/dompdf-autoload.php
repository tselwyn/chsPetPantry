<?php
    /*
     * Loads dompdf, used for PDF report exports (FR-064).
     * dompdf and its dependencies are committed under vendor/ but are not in
     * composer.json, so vendor/autoload.php does not know about them; this
     * registers their namespaces the same way Composer would.
     */

    if (!class_exists('Dompdf\\Dompdf', false)) {
        $dompdfVendor = dirname(__FILE__) . '/../vendor/';
        $dompdfNamespaces = array(
            'Dompdf\\' => 'dompdf/dompdf/src/',
            'FontLib\\' => 'dompdf/php-font-lib/src/FontLib/',
            'Svg\\' => 'dompdf/php-svg-lib/src/Svg/',
            'Masterminds\\' => 'masterminds/html5/src/',
            'Sabberworm\\CSS\\' => 'sabberworm/php-css-parser/src/'
        );

        spl_autoload_register(function ($class) use ($dompdfVendor, $dompdfNamespaces) {
            // The PDF engine itself is outside src/ (a Composer classmap entry)
            if ($class === 'Dompdf\\Cpdf') {
                require_once $dompdfVendor . 'dompdf/dompdf/lib/Cpdf.php';
                return;
            }
            foreach ($dompdfNamespaces as $prefix => $dir) {
                if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                    $file = $dompdfVendor . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                    return;
                }
            }
        });

        // php-css-parser's Composer "files" entries: aliases for renamed classes
        require_once $dompdfVendor . 'sabberworm/php-css-parser/src/Rule/Rule.php';
        require_once $dompdfVendor . 'sabberworm/php-css-parser/src/RuleSet/RuleContainer.php';
    }
?>
