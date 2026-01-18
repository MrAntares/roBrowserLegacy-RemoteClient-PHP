<?php

/**
 * @fileoverview PathMapping - Korean filename encoding support
 * @author roBrowser Legacy Team ( Mike )
 * @version 1.0.0
 * 
 * Handles Korean filename encoding conversion (CP949/EUC-KR to UTF-8).
 * 
 * Many Ragnarok GRF files contain Korean filenames encoded in CP949/EUC-KR.
 * When these are read on non-Korean systems, they appear as mojibake (garbled characters).
 * 
 * Problem:
 *   Client requests: /data/texture/유저인터페이스/t_배경3-3.tga
 *   GRF contains:    /data/texture/À¯ÀúÀÎÅÍÆäÀÌ½º/t_¹è°æ3-3.tga
 * 
 * Solution:
 *   This class provides path mappings to resolve Korean paths to their GRF equivalents.
 */

final class PathMapping
{
    /**
     * @var array Path mappings (Korean UTF-8 -> GRF mojibake path)
     */
    static private $mappings = [];

    /**
     * @var bool Whether mappings have been loaded
     */
    static private $loaded = false;

    /**
     * @var string Path to the mapping file
     */
    static private $mappingFile = 'path-mapping.json';

    /**
     * @var bool Whether path mapping is enabled
     */
    static private $enabled = true;

    /**
     * @var array Statistics
     */
    static private $stats = [
        'lookups' => 0,
        'hits' => 0,
        'misses' => 0,
    ];


    /**
     * Configure path mapping
     *
     * @param array $config Configuration options
     */
    static public function configure($config = [])
    {
        if (isset($config['enabled'])) {
            self::$enabled = (bool)$config['enabled'];
        }
        if (isset($config['mappingFile'])) {
            self::$mappingFile = $config['mappingFile'];
        }

        // Load mappings if enabled
        if (self::$enabled) {
            self::loadMappings();
        }
    }


    /**
     * Load path mappings from JSON file
     *
     * @return bool Success
     */
    static public function loadMappings()
    {
        if (self::$loaded) {
            return true;
        }

        if (!file_exists(self::$mappingFile)) {
            Debug::write('Path mapping file not found: ' . self::$mappingFile, 'info');
            self::$loaded = true;
            return false;
        }

        $content = @file_get_contents(self::$mappingFile);
        if ($content === false) {
            Debug::write('Cannot read path mapping file: ' . self::$mappingFile, 'error');
            self::$loaded = true;
            return false;
        }

        $data = json_decode($content, true);
        if ($data === null) {
            Debug::write('Invalid JSON in path mapping file: ' . self::$mappingFile, 'error');
            self::$loaded = true;
            return false;
        }

        // Support both flat format and nested format with 'paths' key
        if (isset($data['paths'])) {
            self::$mappings = $data['paths'];
        } else {
            self::$mappings = $data;
        }

        self::$loaded = true;
        Debug::write('Path mapping loaded: ' . count(self::$mappings) . ' entries', 'success');
        
        return true;
    }


    /**
     * Resolve a path using the mapping
     * Returns the GRF-compatible path if a mapping exists
     *
     * @param string $path The requested path (possibly Korean UTF-8)
     * @return string|null The mapped GRF path, or null if no mapping exists
     */
    static public function resolve($path)
    {
        if (!self::$enabled || empty(self::$mappings)) {
            return null;
        }

        self::$stats['lookups']++;

        // Normalize path for lookup
        $normalizedPath = self::normalizePath($path);

        // Direct lookup
        if (isset(self::$mappings[$normalizedPath])) {
            self::$stats['hits']++;
            Debug::write("Path mapping hit: {$path} -> " . self::$mappings[$normalizedPath], 'info');
            return self::$mappings[$normalizedPath];
        }

        // Try lowercase lookup
        $lowerPath = strtolower($normalizedPath);
        if (isset(self::$mappings[$lowerPath])) {
            self::$stats['hits']++;
            Debug::write("Path mapping hit (lowercase): {$path} -> " . self::$mappings[$lowerPath], 'info');
            return self::$mappings[$lowerPath];
        }

        // Try with different path separators
        $backslashPath = str_replace('/', '\\', $normalizedPath);
        if (isset(self::$mappings[$backslashPath])) {
            self::$stats['hits']++;
            return self::$mappings[$backslashPath];
        }

        $forwardSlashPath = str_replace('\\', '/', $normalizedPath);
        if (isset(self::$mappings[$forwardSlashPath])) {
            self::$stats['hits']++;
            return self::$mappings[$forwardSlashPath];
        }

        self::$stats['misses']++;
        return null;
    }


    /**
     * Normalize a path for consistent lookups
     *
     * @param string $path
     * @return string
     */
    static private function normalizePath($path)
    {
        // Convert to forward slashes
        $path = str_replace('\\', '/', $path);
        
        // Remove leading slash
        $path = ltrim($path, '/');
        
        return $path;
    }


    /**
     * Check if a path contains Korean characters
     *
     * @param string $path
     * @return bool
     */
    static public function containsKorean($path)
    {
        // Korean Unicode ranges:
        // Hangul Syllables: U+AC00-U+D7AF
        // Hangul Jamo: U+1100-U+11FF
        // Hangul Compatibility Jamo: U+3130-U+318F
        // Hangul Jamo Extended-A: U+A960-U+A97F
        // Hangul Jamo Extended-B: U+D7B0-U+D7FF
        return preg_match('/[\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}\x{3130}-\x{318F}]/u', $path) === 1;
    }


    /**
     * Check if a path might be mojibake (garbled Korean)
     * Common mojibake patterns from CP949 -> Latin1 misinterpretation
     *
     * @param string $path
     * @return bool
     */
    static public function isMojibake($path)
    {
        // Common mojibake patterns from Korean CP949 misread as Latin-1
        // Characters like À, Á, Â, Ã, Ä, Å, Æ, Ç, È, É, Ê, Ë, etc.
        $mojibakePattern = '/[À-ÿ]{2,}/';
        
        return preg_match($mojibakePattern, $path) === 1;
    }


    /**
     * Try to convert mojibake back to Korean
     * This attempts to reverse the CP949 -> Latin1 -> UTF-8 corruption
     *
     * @param string $mojibake
     * @return string|null Decoded Korean string, or null if conversion fails
     */
    static public function decodeMojibake($mojibake)
    {
        if (!function_exists('mb_convert_encoding')) {
            return null;
        }

        try {
            // Try to convert: UTF-8 (mojibake) -> Latin1 (raw bytes) -> CP949 -> UTF-8
            $latin1 = mb_convert_encoding($mojibake, 'ISO-8859-1', 'UTF-8');
            $korean = @mb_convert_encoding($latin1, 'UTF-8', 'CP949');
            
            // Check if result contains valid Korean
            if ($korean && self::containsKorean($korean)) {
                return $korean;
            }
        } catch (Exception $e) {
            // Conversion failed
        }

        return null;
    }


    /**
     * Try to convert Korean UTF-8 to GRF-compatible mojibake
     * This creates the CP949 -> Latin1 representation that GRFs often use
     *
     * @param string $korean Korean UTF-8 string
     * @return string|null Mojibake representation, or null if conversion fails
     */
    static public function encodeToMojibake($korean)
    {
        if (!function_exists('mb_convert_encoding')) {
            return null;
        }

        try {
            // Convert: UTF-8 (Korean) -> CP949 -> Latin1 -> UTF-8
            $cp949 = @mb_convert_encoding($korean, 'CP949', 'UTF-8');
            if ($cp949 === false) {
                return null;
            }
            
            $mojibake = mb_convert_encoding($cp949, 'UTF-8', 'ISO-8859-1');
            return $mojibake;
        } catch (Exception $e) {
            // Conversion failed
        }

        return null;
    }


    /**
     * Add a mapping programmatically
     *
     * @param string $koreanPath Korean UTF-8 path
     * @param string $grfPath GRF path (possibly mojibake)
     */
    static public function addMapping($koreanPath, $grfPath)
    {
        $normalizedKorean = self::normalizePath($koreanPath);
        self::$mappings[$normalizedKorean] = $grfPath;
    }


    /**
     * Get all mappings
     *
     * @return array
     */
    static public function getMappings()
    {
        return self::$mappings;
    }


    /**
     * Get statistics
     *
     * @return array
     */
    static public function getStats()
    {
        $total = self::$stats['hits'] + self::$stats['misses'];
        $hitRate = $total > 0 ? round(($stats['hits'] / $total) * 100, 2) : 0;

        return [
            'enabled' => self::$enabled,
            'mappingFile' => self::$mappingFile,
            'mappingCount' => count(self::$mappings),
            'loaded' => self::$loaded,
            'lookups' => self::$stats['lookups'],
            'hits' => self::$stats['hits'],
            'misses' => self::$stats['misses'],
            'hitRate' => $hitRate . '%',
        ];
    }


    /**
     * Generate mappings from a GRF file
     * Scans the GRF and creates Korean -> mojibake mappings
     *
     * @param Grf $grf GRF instance
     * @return array Generated mappings
     */
    static public function generateFromGrf($grf)
    {
        if (!$grf->loaded) {
            return [];
        }

        $generated = [];
        $files = $grf->getFileList();

        foreach ($files as $filePath) {
            // Check if path looks like mojibake
            if (self::isMojibake($filePath)) {
                // Try to decode to Korean
                $korean = self::decodeMojibake($filePath);
                
                if ($korean && $korean !== $filePath) {
                    $normalizedKorean = self::normalizePath($korean);
                    $generated[$normalizedKorean] = $filePath;
                }
            }
        }

        return $generated;
    }


    /**
     * Save mappings to JSON file
     *
     * @param string|null $file Optional file path (uses configured path if null)
     * @return bool Success
     */
    static public function saveMappings($file = null)
    {
        $targetFile = $file ?: self::$mappingFile;
        
        $data = [
            'generatedAt' => date('c'),
            'count' => count(self::$mappings),
            'paths' => self::$mappings,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if (@file_put_contents($targetFile, $json) === false) {
            Debug::write('Cannot save path mapping file: ' . $targetFile, 'error');
            return false;
        }

        Debug::write('Path mapping saved: ' . count(self::$mappings) . ' entries to ' . $targetFile, 'success');
        return true;
    }


    /**
     * Output mapping statistics as JSON (for API endpoint)
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
