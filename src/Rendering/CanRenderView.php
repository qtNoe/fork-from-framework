<?php

    namespace ZubZet\Framework\Rendering;

    use ZubZet\Framework\Logger\Logger;
    use ZubZet\Framework\Logger\LogEventType;
    use ZubZet\Framework\ErrorHandling\DebugBar\DebugBarBridge;
    use ZubZet\Framework\Rendering\Katana\Engine;
    use ZubZet\Framework\Rendering\Resolver\ViewPath;

    trait CanRenderView {

        use ViewPath;

        /**
         * Shows a document to the user
         * @param string $document Path to the view
         * @param array $opt Associative array with values to replace in the view
         * @param string|array $options Rendering options, e.g., or a string for layout
         */
        public function render($document, $opt = [], $options = []) {
            // Legacy as $options used to be $layout
            if(!is_array($options)) {
                $options = ["layout" => $options];
            }

            $layout = $options["layout"] ?? $this->resolveDefaultLayout();
            $viewPath = self::resolvePath($document);
            $layoutPath = self::resolvePath($layout);

            // Optional log view
            try {
                $location = implode("/", request()->getUrlParts());
                logger(Logger::ZUBZET)->info(LogEventType::RENDER, [
                    "location" => $location,
                    "view" => $document,
                    "viewPath" => $viewPath,
                    "layout" => $layout,
                    "layoutPath" => $layoutPath,
                ]);
            } catch (\Exception $e) {
                // Do not log this render to avoid having to require a database
            }

            DebugBarBridge::collectTemplate($document, $opt, "blade", $layout);

            // Expand legacy $opt with framework variables, functions, and objects
            // likely subject to deprecation in the future as the expansions overwrite
            // the provided opt parameters.
            $expansion = self::legacyOptExpansion($opt);

            // Render through Katana. The engine resolves by view name against its finder chain,
            // so hand it the root-relative names. resolvePath already picked which file wins; the
            // engine re-resolves the same name with the same userspace->framework precedence.
            // Temporary: once resolution moves into the engine, resolvePath collapses to name
            // normalization and viewName() below goes away.
            echo Engine::render(
                self::viewName($viewPath),
                self::viewName($layoutPath),
                array_merge($opt, $expansion),
            );
        }

        /**
         * Map a resolved absolute view path back to the root-relative Katana view name (no
         * ".blade.php"), so the engine's finder chain re-resolves it. Temporary bridge while
         * resolvePath still returns a path.
         */
        private static function viewName(string $absolutePath): string {
            $roots = [
                rtrim(zubzet()->z_views, "/"),
                rtrim(zubzet()->z_framework_root . "IncludedComponents/views", "/"),
            ];
            foreach($roots as $root) {
                if(str_starts_with($absolutePath, "$root/")) {
                    return substr($absolutePath, strlen($root) + 1, -strlen(".blade.php"));
                }
            }
            return substr(basename($absolutePath), 0, -strlen(".blade.php"));
        }

        private static function legacyOptExpansion(array $opt): array {
            // Classes
            $expansion["response"] = response();
            $expansion["request"] = request();
            $expansion["user"] = user();

            // Variables
            $expansion["root"] = zubzet()->rootFolder;
            $expansion["host"] = zubzet()->host;
            $expansion["absRoot"] = zubzet()->host . zubzet()->rootFolder;
            $expansion["title"] = $opt["title"] ?? config("pageName", default: "ZubZet");

            // Functions
            $expansion["generateResourceLink"] = function($url, $root = true) {
                $v = config("assetVersion");
                echo (($root ? zubzet()->rootFolder : "") . $url . "?v=" . (($v == "dev") ? time() : $v));
            };

            $expansion["echo"] = function($val) {
                echo nl2br(htmlspecialchars($val));
            };

            // All variables used to be found in $opt
            return ["opt" => array_merge($opt, $expansion)];
        }

    }

?>