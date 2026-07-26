<?php

    namespace ZubZet\Framework\Rendering\Katana;

    use Blade\View;
    use Blade\Blade;
    use Blade\Config;

    /**
     * Thin adapter around the Katana Blade engine.
     *
     * The framework's two view roots are registered as an ordered finder chain (userspace
     * overrides framework), so Katana resolves the entry view by name, its @extends($layout)
     * chain, @includes and <x-components> (including the framework's own components/ under the
     * framework root) with the same userspace-then-framework precedence the framework uses
     * everywhere. A fresh Blade per render keeps @section state from leaking across renders.
     *
     * Full rationale: docs/contributing/katana-integration-findings.md (Adapter internals).
     */
    class Engine {

        /**
         * @internal
         * One-shot extra view roots, prepended to the NEXT render at highest precedence
         * and then consumed. Escape hatch for forcing a specific source. Caution: This
         * WILL be removed in future versions in favor of a modular view resolver.
         */
        public static ?string $testOverwriteViewPath = null;

        public static function render(string $view, string $layout, array $data): View {
            $config = new Config(self::cachePath());

            // One-shot extra roots (highest precedence), e.g. forcing the framework copy over a
            // userspace override. Registered before the standard roots, then consumed.
            if(!is_null(self::$testOverwriteViewPath)) {
                $config->addViewPath(self::$testOverwriteViewPath);
            }

            // Ordered view roots: userspace first, framework second. First finder that has the
            // name wins, so userspace overrides framework. This one chain resolves the view, the
            // layout, their @extends/@includes and every <x-component> (a `components.<name>`
            // lookup against these roots), so no per-file pinning or separate component path.
            $config->addViewPath(zubzet()->z_views);
            $config->addViewPath(zubzet()->z_framework_root . "IncludedComponents/views");

            // Framework directives bound to the request (@auth / @guest, more later).
            Hooks::register($config);

            $blade = new Blade(config: $config);

            // The migrated view does @extends($layout); hand it the layout's view name.
            $data["layout"] = $layout;

            return $blade->render($view, $data);
        }

        /**
         * On-disk directory for compiled views, namespaced per project and per installed engine
         * reference. The engine segment forces a clean cache on upgrade (Katana keys a compiled
         * view on path + mtime, not engine version). Seam for a future generalized framework cache.
         */
        private static function cachePath(): string {
            try {
                $engine = (string) \Composer\InstalledVersions::getReference("katanaphp/blade");
            } catch(\Throwable $e) {
                $engine = "";
            }
            $projectKey = sha1(zubzet()->z_framework_root);
            $path = sys_get_temp_dir() . "/zubzet_cache/$projectKey/views/$engine";
            if(!is_dir($path)) @mkdir($path, 0777, true);
            return $path;
        }
    }

?>
