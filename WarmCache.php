<?php

/**
 * @fileoverview WarmCache - Pre-load frequently accessed files into cache
 * @author roBrowser Legacy Team ( Mike )
 * @version 1.0.0
 * 
 * Provides functionality to pre-load frequently accessed game files into the LRU cache
 * at startup, improving response times for common requests.
 * 
 * Common patterns include:
 * - clientinfo.xml (always loaded first)
 * - Sprite files (.spr, .act)
 * - Texture files (.bmp, .tga)
 * - Map files (.gat, .gnd, .rsw)
 * - UI textures
 */

final class WarmCache
{
    /**
     * @var array Default file patterns to warm cache with
     */
    static private $defaultPatterns = [
        // Essential files
        'data\\clientinfo.xml',
        'data\\clientinfo.txt',
        
        // Common UI textures
        'data\\texture\\effect\\*.tga',
        'data\\texture\\effect\\*.bmp',
        
        // Loading screens
        'data\\texture\\유저인터페이스\\loading*.jpg',
        'data\\texture\\유저인터페이스\\loading*.bmp',
    ];

    /**
     * @var array Statistics
     */
    static private $stats = [
        'filesLoaded' => 0,
        'bytesLoaded' => 0,
        'errors' => 0,
        'timeMs' => 0,
    ];

    /**
     * @var bool Whether warm cache is enabled
     */
    static private $enabled = true;

    /**
     * @var array Custom patterns to warm
     */
    static private $patterns = [];

    /**
     * @var int Maximum files to warm
     */
    static private $maxFiles = 50;

    /**
     * @var int Maximum memory to use for warming (in bytes)
     */
    static private $maxMemory = 52428800; // 50MB default


    /**
     * Configure warm cache settings
     *
     * @param array $config Configuration options
     */
    static public function configure($config = [])
    {
        if (isset($config['enabled'])) {
            self::$enabled = (bool)$config['enabled'];
        }
        if (isset($config['patterns'])) {
            self::$patterns = $config['patterns'];
        }
        if (isset($config['maxFiles'])) {
            self::$maxFiles = (int)$config['maxFiles'];
        }
        if (isset($config['maxMemoryMB'])) {
            self::$maxMemory = (int)$config['maxMemoryMB'] * 1024 * 1024;
        }
    }


    /**
     * Warm the cache with frequently accessed files
     * Should be called after Client::init()
     *
     * @param array $customPatterns Optional custom patterns to warm
     * @return array Statistics about warming process
     */
    static public function warm($customPatterns = [])
    {
        if (!self::$enabled) {
            Debug::write('Warm cache is disabled', 'info');
            return self::$stats;
        }

        $cache = Client::getCache();
        if ($cache === null || !$cache->isEnabled()) {
            Debug::write('Cache is not available or disabled', 'info');
            return self::$stats;
        }

        $startTime = microtime(true);
        Debug::write('Starting cache warming...', 'info');

        // Combine default patterns with custom ones
        $patterns = !empty($customPatterns) ? $customPatterns : 
                    (!empty(self::$patterns) ? self::$patterns : self::$defaultPatterns);

        $filesLoaded = 0;
        $bytesLoaded = 0;
        $errors = 0;

        foreach ($patterns as $pattern) {
            if ($filesLoaded >= self::$maxFiles) {
                Debug::write('Warm cache: max files limit reached', 'info');
                break;
            }

            if ($bytesLoaded >= self::$maxMemory) {
                Debug::write('Warm cache: max memory limit reached', 'info');
                break;
            }

            // Check if pattern contains wildcards
            if (strpos($pattern, '*') !== false) {
                // Get matching files from index
                $matchingFiles = self::findMatchingFiles($pattern);
                
                foreach ($matchingFiles as $file) {
                    if ($filesLoaded >= self::$maxFiles || $bytesLoaded >= self::$maxMemory) {
                        break;
                    }

                    $result = self::loadFile($file);
                    if ($result !== false) {
                        $filesLoaded++;
                        $bytesLoaded += $result;
                    } else {
                        $errors++;
                    }
                }
            } else {
                // Direct file pattern
                $result = self::loadFile($pattern);
                if ($result !== false) {
                    $filesLoaded++;
                    $bytesLoaded += $result;
                } else {
                    $errors++;
                }
            }
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        self::$stats = [
            'filesLoaded' => $filesLoaded,
            'bytesLoaded' => $bytesLoaded,
            'bytesLoadedFormatted' => self::formatBytes($bytesLoaded),
            'errors' => $errors,
            'timeMs' => $elapsed,
        ];

        Debug::write("Warm cache completed: {$filesLoaded} files, " . self::formatBytes($bytesLoaded) . " in {$elapsed}ms", 'success');

        return self::$stats;
    }


    /**
     * Find files matching a wildcard pattern in the file index
     *
     * @param string $pattern Pattern with wildcards
     * @return array Matching file paths
     */
    static private function findMatchingFiles($pattern)
    {
        $matches = [];
        
        // Convert pattern to regex
        $regex = self::patternToRegex($pattern);
        
        // Get file list from Client
        $indexStats = Client::getIndexStats();
        
        // If we have access to the file index, use it
        // Otherwise, fall back to search function
        $files = Client::search($pattern);
        
        if (!empty($files)) {
            // Limit results to prevent warming too many files from one pattern
            return array_slice($files, 0, min(20, self::$maxFiles));
        }

        return $matches;
    }


    /**
     * Convert a wildcard pattern to regex
     *
     * @param string $pattern
     * @return string Regex pattern
     */
    static private function patternToRegex($pattern)
    {
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\\*', '.*', $pattern);
        $pattern = str_replace('\\?', '.', $pattern);
        return '/^' . $pattern . '$/i';
    }


    /**
     * Load a single file into cache
     *
     * @param string $path File path
     * @return int|false Bytes loaded or false on failure
     */
    static private function loadFile($path)
    {
        try {
            // Use Client::getFile which will automatically cache the result
            $content = Client::getFile($path);
            
            if ($content !== false) {
                return strlen($content);
            }
        } catch (Exception $e) {
            Debug::write("Warm cache error loading {$path}: " . $e->getMessage(), 'error');
        }

        return false;
    }


    /**
     * Get warm cache statistics
     *
     * @return array
     */
    static public function getStats()
    {
        return array_merge(self::$stats, [
            'enabled' => self::$enabled,
            'maxFiles' => self::$maxFiles,
            'maxMemoryMB' => round(self::$maxMemory / 1024 / 1024, 2),
            'patternsCount' => count(self::$patterns) > 0 ? count(self::$patterns) : count(self::$defaultPatterns),
        ]);
    }


    /**
     * Warm cache with specific file list
     *
     * @param array $files List of file paths to load
     * @return array Statistics
     */
    static public function warmFiles($files)
    {
        if (!self::$enabled) {
            return self::$stats;
        }

        $startTime = microtime(true);
        $filesLoaded = 0;
        $bytesLoaded = 0;
        $errors = 0;

        foreach ($files as $file) {
            if ($filesLoaded >= self::$maxFiles || $bytesLoaded >= self::$maxMemory) {
                break;
            }

            $result = self::loadFile($file);
            if ($result !== false) {
                $filesLoaded++;
                $bytesLoaded += $result;
            } else {
                $errors++;
            }
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        self::$stats = [
            'filesLoaded' => $filesLoaded,
            'bytesLoaded' => $bytesLoaded,
            'bytesLoadedFormatted' => self::formatBytes($bytesLoaded),
            'errors' => $errors,
            'timeMs' => $elapsed,
        ];

        return self::$stats;
    }


    /**
     * Warm cache based on previous missing files log
     * Useful for pre-loading files that were previously not cached
     *
     * @param int $limit Maximum files to warm from history
     * @return array Statistics
     */
    static public function warmFromHistory($limit = 20)
    {
        if (!class_exists('MissingFilesLog')) {
            return self::$stats;
        }

        // This would load recently accessed but missed files
        // Implementation would depend on tracking access patterns
        return self::$stats;
    }


    /**
     * Get list of commonly needed files for a basic client
     *
     * @return array File paths
     */
    static public function getEssentialFiles()
    {
        return [
            'data\\clientinfo.xml',
            'data\\lua files\\datainfo\\npcidentity.lub',
            'data\\lua files\\datainfo\\jobname.lub',
            'data\\lua files\\datainfo\\accessoryid.lub',
            'data\\lua files\\datainfo\\accname.lub',
            'data\\lua files\\skillinfoz\\skilldescript.lub',
            'data\\lua files\\skillinfoz\\skillinfolist.lub',
            'data\\lua files\\skillinfoz\\skillid.lub',
            'data\\lua files\\datainfo\\iteminfo.lub',
            'data\\lua files\\datainfo\\tb_cashshop_banner.lub',
        ];
    }


    /**
     * Format bytes to human readable
     *
     * @param int $bytes
     * @return string
     */
    static private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }


    /**
     * Output warm cache statistics as JSON (for API endpoint)
     */
    static public function outputJson()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Access-Control-Allow-Origin: *');
        
        echo json_encode(self::getStats(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
