<?php

    namespace ZubZet\Framework\Rendering\Resolver;

    /**
     * @internal
     */
    trait ViewPath {

        /**
         * @internal
         * Returns the path of a view. If the view does not exist, this function will fall back to the framework defaults.
         *
         * File extensions are not needed, e.g. "index" or "index.blade.php" or "index.php" are all valid.
         * Dot notation is also supported, e.g. "core.index" or "core.index.blade.php" or "core.index.php"
         *
         * @param string $document Filename of the view
         * @param bool $throwOnError Wether a ViewNotFoundException should be thrown when there is no fitting view or an error view is returned
         * @return string Relative path to the view file
         * @throws ViewNotFoundException when $throwOnError is set to true
         */
        public static function resolvePath(string $document, bool $throwOnError = false): string {
            $document = rtrim($document);

            // Strip any file extensions from the document name call to
            // normalize the name to a relative path.
            $name = $document;
            foreach ([".blade.php", ".php"] as $suffix) {
                if(str_ends_with($document, $suffix)) {
                    $name = substr($document, 0, -strlen($suffix));
                    break;
                }
            }

            // Try finding the view as a literal path first
            $located = self::locateExistingView("$name.blade.php");
            if(!is_null($located)) return $located;

            // Try finding the view if there are no folder separators but there is a dot in the name
            if(!str_contains($name, "/") && str_contains($name, ".")) {
                $nameAsPath = str_replace(".", DIRECTORY_SEPARATOR, $name);
                $located = self::locateExistingView("$nameAsPath.blade.php");
                if(!is_null($located)) return $located;
            }

            // Detect un-upgraded views and ensure both view and layout are Blade templates
            $oldViewPath = self::locateExistingView("$name.php");
            if(!is_null($oldViewPath)) {
                throw new \UnexpectedValueException(
                    "Please upgrade. Only Blade templates are supported since version 1.3.0. Found: $oldViewPath",
                );
            }

            // Handle when no view was found
            if(!$throwOnError) {
                return zubzet()->z_framework_root."IncludedComponents/views/500.blade.php";
            }
            throw new ViewNotFoundException("View file for '$document' not found. Is the path correct?");
        }

        /**
         * @internal
         * Probe user space then framework space for a relative (or already-rooted)
         * @param string $document A ".blade.php" document (bare relative path or absolute)
         * @return string|null Absolute path to the view, or null if neither space has it
         */
        private static function locateExistingView(string $document): ?string {
            // Look for the view in the user space, don't readd the location if it is already present
            $userSpaceLocationDocument = $document;
            if(!str_starts_with($document, zubzet()->z_views)) {
                $userSpaceLocationDocument = zubzet()->z_views . $document;
            }

            if(file_exists($userSpaceLocationDocument)) {
                return $userSpaceLocationDocument;
            }

            // Look for the view in the framework space, also don't readd the location if it is already present
            $frameworkLocationDocument = $document;
            if(!str_starts_with($document, zubzet()->z_framework_root."IncludedComponents/views/")) {
                $frameworkLocationDocument = zubzet()->z_framework_root."IncludedComponents/views/$document";
            }

            if(file_exists($frameworkLocationDocument)) {
                return $frameworkLocationDocument;
            }

            return null;
        }
    }

?>
