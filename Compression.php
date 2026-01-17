<?php

/**
 * @fileoverview Compression - Handle Gzip/Deflate compression for responses
 * @author GitHub Copilot
 * @version 1.0.0
 */

class Compression
{
    /**
     * @var bool Whether compression is enabled
     */
    private static $enabled = true;

    /**
     * @var int Minimum size in bytes to apply compression (default: 1KB)
     */
    private static $minSize = 1024;

    /**
     * @var int Compression level (1-9, higher = better compression but slower)
     */
    private static $level = 6;

    /**
     * @var array File extensions that should be compressed
     */
    private static $compressibleExtensions = [
        // Text-based game files
        'txt', 'xml', 'lua', 'lub',
        // Ragnarok map/model files (binary but compressible)
        'rsw', 'rsm', 'rsm2', 'gnd', 'gat',
        // Sprite/animation files
        'spr', 'act', 'pal', 'imf',
        // Other compressible formats
        'json', 'ini', 'cfg',
    ];

    /**
     * @var array MIME types that benefit from compression
     */
    private static $compressibleMimeTypes = [
        'text/plain',
        'text/xml',
        'application/xml',
        'application/json',
        'application/lua',
        'application/octet-stream', // Many RO files use this
    ];


    /**
     * Configure compression settings
     *
     * @param bool $enabled Whether compression is enabled
     * @param int $minSize Minimum size in bytes to compress
     * @param int $level Compression level (1-9)
     */
    public static function configure($enabled = true, $minSize = 1024, $level = 6)
    {
        self::$enabled = $enabled;
        self::$minSize = max(0, (int)$minSize);
        self::$level = max(1, min(9, (int)$level));
    }


    /**
     * Check if client accepts gzip encoding
     *
     * @return string|false Returns 'gzip', 'deflate', or false
     */
    public static function getAcceptedEncoding()
    {
        if (!isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            return false;
        }

        $acceptEncoding = strtolower($_SERVER['HTTP_ACCEPT_ENCODING']);

        if (strpos($acceptEncoding, 'gzip') !== false) {
            return 'gzip';
        }

        if (strpos($acceptEncoding, 'deflate') !== false) {
            return 'deflate';
        }

        return false;
    }


    /**
     * Check if a file extension should be compressed
     *
     * @param string $extension File extension (without dot)
     * @return bool
     */
    public static function isCompressibleExtension($extension)
    {
        return in_array(strtolower($extension), self::$compressibleExtensions);
    }


    /**
     * Add an extension to the compressible list
     *
     * @param string|array $extension Extension(s) to add
     */
    public static function addCompressibleExtension($extension)
    {
        if (is_array($extension)) {
            self::$compressibleExtensions = array_unique(
                array_merge(self::$compressibleExtensions, array_map('strtolower', $extension))
            );
        } else {
            self::$compressibleExtensions[] = strtolower($extension);
            self::$compressibleExtensions = array_unique(self::$compressibleExtensions);
        }
    }


    /**
     * Compress content if appropriate
     *
     * @param string $content The content to compress
     * @param string $extension File extension
     * @return string The (possibly compressed) content
     */
    public static function compress($content, $extension)
    {
        // Check if compression is enabled
        if (!self::$enabled) {
            return $content;
        }

        // Check if content is large enough to benefit from compression
        $contentLength = strlen($content);
        if ($contentLength < self::$minSize) {
            return $content;
        }

        // Check if this extension should be compressed
        if (!self::isCompressibleExtension($extension)) {
            return $content;
        }

        // Check if client accepts compression
        $encoding = self::getAcceptedEncoding();
        if ($encoding === false) {
            return $content;
        }

        // Compress the content
        if ($encoding === 'gzip') {
            $compressed = gzencode($content, self::$level);
        } else {
            $compressed = gzdeflate($content, self::$level);
        }

        // Only use compression if it actually reduces size
        if ($compressed === false || strlen($compressed) >= $contentLength) {
            return $content;
        }

        // Set compression headers
        header('Content-Encoding: ' . $encoding);
        header('Vary: Accept-Encoding');

        // Log compression stats in debug mode
        if (class_exists('Debug') && Debug::isEnable()) {
            $ratio = round((1 - strlen($compressed) / $contentLength) * 100, 1);
            Debug::write("Compressed with {$encoding}: {$contentLength} → " . strlen($compressed) . " bytes ({$ratio}% reduction)", 'info');
        }

        return $compressed;
    }


    /**
     * Start output buffering with compression (alternative method using ob_gzhandler)
     * Use this for streaming output instead of compress()
     *
     * @param string $extension File extension to check
     * @return bool Whether compression was started
     */
    public static function startOutputBuffer($extension)
    {
        if (!self::$enabled) {
            return false;
        }

        if (!self::isCompressibleExtension($extension)) {
            return false;
        }

        if (self::getAcceptedEncoding() === false) {
            return false;
        }

        // Use PHP's built-in handler
        return ob_start('ob_gzhandler');
    }


    /**
     * Get compression statistics
     *
     * @return array
     */
    public static function getStats()
    {
        return [
            'enabled' => self::$enabled,
            'minSize' => self::$minSize,
            'level' => self::$level,
            'compressibleExtensions' => self::$compressibleExtensions,
            'clientAccepts' => self::getAcceptedEncoding() ?: 'none',
        ];
    }
}
