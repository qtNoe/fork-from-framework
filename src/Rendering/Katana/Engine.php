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
         * Escape hatch for prioritizing framework views. Caution: This WILL
         * be removed in future versions in favor of a modular view resolver.
         */
        public static bool $prioritizeFrameworkViews = false;

        public static function render(string $view, string $layout, array $data): View {
            $config = new Config(self::cachePath());

            // Ordered view roots: userspace first, framework second.
            $viewPaths = [
                zubzet()->z_views,
                zubzet()->z_framework_root . "IncludedComponents/views",
            ];

            // Prioritize framework views for testing
            if(self::$prioritizeFrameworkViews) {
                $viewPaths = array_reverse($viewPaths);
            }

            foreach($viewPaths as $viewPath) {
                $config->addViewPath($viewPath);
            }

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
            $path = sys_get_temp_dir() . "/zubzet_cache/project_$projectKey/views/engine_$engine";
            if(!is_dir($path)) @mkdir($path, 0777, true);
            return $path;
        }
    }

?>
