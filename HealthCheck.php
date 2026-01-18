<?php

/**
 * @fileoverview HealthCheck - API health check endpoint
 * @author roBrowser Legacy Team
 * @version 1.0.0
 * 
 * Provides system health information including:
 * - Server status
 * - GRF files status
 * - Cache statistics
 * - File index statistics
 * - PHP configuration
 */

final class HealthCheck
{
    /**
     * Get complete health status
     *
     * @return array Health status information
     */
    static public function getStatus()
    {
        $startTime = microtime(true);
        
        $status = [
            'status' => 'ok',
            'timestamp' => date('c'),
            'uptime' => self::getUptime(),
            'version' => self::getVersion(),
            'php' => self::getPhpInfo(),
            'grfs' => self::getGrfStatus(),
            'cache' => self::getCacheStatus(),
            'index' => self::getIndexStatus(),
            'compression' => self::getCompressionStatus(),
            'summary' => [],
            'warnings' => [],
        ];

        // Build summary and check for warnings
        $status = self::buildSummary($status);
        
        // Calculate response time
        $status['responseTimeMs'] = round((microtime(true) - $startTime) * 1000, 2);

        return $status;
    }


    /**
     * Get simple health status (for quick checks)
     *
     * @return array Simple status
     */
    static public function getSimpleStatus()
    {
        $grfStatus = self::getGrfStatus();
        $hasValidGrfs = $grfStatus['valid'] > 0;
        
        return [
            'status' => $hasValidGrfs ? 'ok' : 'error',
            'timestamp' => date('c'),
            'grfsLoaded' => $grfStatus['valid'],
            'message' => $hasValidGrfs 
                ? "System operational with {$grfStatus['valid']} GRF(s)" 
                : 'No valid GRF files loaded'
        ];
    }


    /**
     * Get server uptime (approximate based on request start)
     *
     * @return string Uptime information
     */
    static private function getUptime()
    {
        // PHP doesn't track actual server uptime, but we can show request start time
        return [
            'requestStart' => date('c', (int)$_SERVER['REQUEST_TIME']),
            'note' => 'PHP processes are ephemeral; this shows request start time'
        ];
    }


    /**
     * Get version information
     *
     * @return array Version info
     */
    static private function getVersion()
    {
        return [
            'remoteclient' => '2.1.0',
            'features' => [
                'lruCache' => true,
                'httpCache' => true,
                'compression' => true,
                'fileIndex' => true,
                'grf0x300' => true,
                'healthCheck' => true,
            ]
        ];
    }


    /**
     * Get PHP information
     *
     * @return array PHP info
     */
    static private function getPhpInfo()
    {
        return [
            'version' => PHP_VERSION,
            'memoryLimit' => ini_get('memory_limit'),
            'memoryUsage' => self::formatBytes(memory_get_usage(true)),
            'memoryPeak' => self::formatBytes(memory_get_peak_usage(true)),
            'extensions' => [
                'zlib' => extension_loaded('zlib'),
                'mbstring' => extension_loaded('mbstring'),
                'gd' => extension_loaded('gd'),
            ],
            'sapi' => php_sapi_name(),
        ];
    }


    /**
     * Get GRF files status
     *
     * @return array GRF status
     */
    static private function getGrfStatus()
    {
        $indexStats = Client::getIndexStats();
        
        $result = [
            'total' => $indexStats['grfCount'],
            'valid' => 0,
            'invalid' => 0,
            'files' => [],
        ];

        foreach ($indexStats['grfs'] as $index => $grf) {
            $grfInfo = [
                'index' => $index,
                'filename' => $grf['filename'],
                'loaded' => $grf['loaded'],
                'fileCount' => $grf['fileCount'],
            ];

            if ($grf['loaded']) {
                $result['valid']++;
                $grfInfo['status'] = 'ok';
            } else {
                $result['invalid']++;
                $grfInfo['status'] = 'error';
            }

            $result['files'][] = $grfInfo;
        }

        return $result;
    }


    /**
     * Get cache status
     *
     * @return array Cache status
     */
    static private function getCacheStatus()
    {
        $cacheStats = Client::getCacheStats();
        
        if ($cacheStats === null) {
            return [
                'enabled' => false,
                'message' => 'Cache not initialized'
            ];
        }

        return [
            'enabled' => $cacheStats['enabled'],
            'items' => $cacheStats['items'],
            'maxItems' => $cacheStats['maxItems'],
            'memoryUsed' => $cacheStats['memoryUsed'],
            'maxMemory' => $cacheStats['maxMemory'],
            'memoryUsagePercent' => $cacheStats['memoryUsagePercent'],
            'hits' => $cacheStats['hits'],
            'misses' => $cacheStats['misses'],
            'hitRate' => $cacheStats['hitRate'],
            'evictions' => $cacheStats['evictions'],
        ];
    }


    /**
     * Get file index status
     *
     * @return array Index status
     */
    static private function getIndexStatus()
    {
        $indexStats = Client::getIndexStats();
        
        return [
            'built' => $indexStats['indexBuilt'],
            'totalFiles' => $indexStats['uniqueFiles'],
            'grfCount' => $indexStats['grfCount'],
        ];
    }


    /**
     * Get compression status
     *
     * @return array Compression status
     */
    static private function getCompressionStatus()
    {
        if (!class_exists('Compression')) {
            return [
                'enabled' => false,
                'message' => 'Compression class not loaded'
            ];
        }

        return [
            'enabled' => true,
            'zlibAvailable' => extension_loaded('zlib'),
            'supportedEncodings' => ['gzip', 'deflate'],
        ];
    }


    /**
     * Build summary and detect warnings
     *
     * @param array $status Current status
     * @return array Updated status with summary and warnings
     */
    static private function buildSummary($status)
    {
        $warnings = [];

        // Check GRFs
        if ($status['grfs']['valid'] === 0) {
            $status['status'] = 'error';
            $warnings[] = 'No valid GRF files loaded';
        } elseif ($status['grfs']['invalid'] > 0) {
            $warnings[] = "{$status['grfs']['invalid']} GRF file(s) failed to load";
        }

        // Check cache
        if (!$status['cache']['enabled']) {
            $warnings[] = 'LRU cache is disabled - consider enabling for better performance';
        }

        // Check memory
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = self::parseBytes($memoryLimit);
        $memoryUsed = memory_get_usage(true);
        
        if ($memoryLimitBytes > 0 && ($memoryUsed / $memoryLimitBytes) > 0.8) {
            $warnings[] = 'Memory usage is above 80% of limit';
        }

        // Check PHP extensions
        if (!$status['php']['extensions']['zlib']) {
            $warnings[] = 'zlib extension not loaded - compression unavailable';
        }

        // Build summary
        $status['summary'] = [
            'grfsLoaded' => $status['grfs']['valid'],
            'totalFilesIndexed' => $status['index']['totalFiles'],
            'cacheEnabled' => $status['cache']['enabled'],
            'cacheHitRate' => $status['cache']['enabled'] ? $status['cache']['hitRate'] : 'N/A',
        ];

        $status['warnings'] = $warnings;
        $status['hasWarnings'] = count($warnings) > 0;

        return $status;
    }


    /**
     * Format bytes to human readable string
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
     * Parse bytes string to integer
     *
     * @param string $str Memory string like "256M"
     * @return int Bytes
     */
    static private function parseBytes($str)
    {
        $str = trim($str);
        $last = strtolower($str[strlen($str) - 1]);
        $value = (int)$str;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }


    /**
     * Output health check as JSON response
     *
     * @param bool $simple Whether to output simple status
     */
    static public function outputJson($simple = false)
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        
        // Allow CORS for health checks
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        
        $status = $simple ? self::getSimpleStatus() : self::getStatus();
        
        // Set appropriate HTTP status code
        $httpCode = ($status['status'] === 'ok') ? 200 : 503;
        http_response_code($httpCode);
        
        echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
