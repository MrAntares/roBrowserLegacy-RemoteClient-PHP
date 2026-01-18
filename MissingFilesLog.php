<?php

/**
 * @fileoverview MissingFilesLog - Log missing file requests
 * @author roBrowser Legacy Team ( Mike )
 * @version 1.0.0
 * 
 * Provides persistent logging for files that were requested but not found.
 * Useful for debugging, monitoring, and identifying missing game assets.
 */

final class MissingFilesLog
{
    /**
     * @var string Log file path
     */
    static private $logFile = 'logs/missing-files.log';

    /**
     * @var array In-memory cache of missing files (for current request)
     */
    static private $missingFiles = [];

    /**
     * @var array Set of already logged paths (to avoid duplicates in same session)
     */
    static private $loggedPaths = [];

    /**
     * @var bool Whether logging is enabled
     */
    static private $enabled = true;

    /**
     * @var int Maximum entries to keep in memory
     */
    static private $maxMemoryEntries = 1000;

    /**
     * @var int Counter for notification threshold
     */
    static private $notificationThreshold = 10;

    /**
     * @var int Current session missing files count
     */
    static private $sessionCount = 0;


    /**
     * Configure the missing files log
     *
     * @param array $config Configuration options
     */
    static public function configure($config = [])
    {
        if (isset($config['enabled'])) {
            self::$enabled = (bool)$config['enabled'];
        }
        if (isset($config['logFile'])) {
            self::$logFile = $config['logFile'];
        }
        if (isset($config['maxMemoryEntries'])) {
            self::$maxMemoryEntries = (int)$config['maxMemoryEntries'];
        }
        if (isset($config['notificationThreshold'])) {
            self::$notificationThreshold = (int)$config['notificationThreshold'];
        }

        // Ensure log directory exists
        self::ensureLogDirectory();
    }


    /**
     * Log a missing file
     *
     * @param string $requestedPath The path that was requested
     * @param string|null $grfPath The GRF path format attempted
     * @param string|null $mappedPath Any mapped path that was tried
     * @param array $additionalInfo Extra info to log
     */
    static public function log($requestedPath, $grfPath = null, $mappedPath = null, $additionalInfo = [])
    {
        if (!self::$enabled) {
            return;
        }

        // Normalize path for deduplication
        $normalizedPath = strtolower(str_replace('\\', '/', $requestedPath));

        // Skip if already logged in this session
        if (isset(self::$loggedPaths[$normalizedPath])) {
            return;
        }

        self::$loggedPaths[$normalizedPath] = true;
        self::$sessionCount++;

        $entry = [
            'timestamp' => date('c'),
            'requestedPath' => $requestedPath,
            'grfPath' => $grfPath,
            'mappedPath' => $mappedPath,
            'clientIp' => self::getClientIp(),
            'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 100) : null,
        ];

        if (!empty($additionalInfo)) {
            $entry['info'] = $additionalInfo;
        }

        // Add to memory cache
        self::$missingFiles[] = $entry;

        // Trim memory cache if needed
        if (count(self::$missingFiles) > self::$maxMemoryEntries) {
            array_shift(self::$missingFiles);
        }

        // Write to log file
        self::writeToLog($entry);

        // Debug output
        Debug::write("Missing file logged: {$requestedPath}", 'error');
    }


    /**
     * Write entry to log file
     *
     * @param array $entry Log entry
     */
    static private function writeToLog($entry)
    {
        if (!self::ensureLogDirectory()) {
            return;
        }

        $logLine = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
        
        // Append to log file (non-blocking)
        @file_put_contents(self::$logFile, $logLine, FILE_APPEND | LOCK_EX);
    }


    /**
     * Ensure log directory exists
     *
     * @return bool Success
     */
    static private function ensureLogDirectory()
    {
        $dir = dirname(self::$logFile);
        
        if (!file_exists($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                Debug::write("Cannot create log directory: {$dir}", 'error');
                return false;
            }
        }

        return is_writable($dir) || is_writable(self::$logFile);
    }


    /**
     * Get client IP address
     *
     * @return string|null
     */
    static private function getClientIp()
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }

        return null;
    }


    /**
     * Get missing files from current session
     *
     * @return array
     */
    static public function getSessionMissingFiles()
    {
        return self::$missingFiles;
    }


    /**
     * Get session statistics
     *
     * @return array
     */
    static public function getSessionStats()
    {
        return [
            'count' => self::$sessionCount,
            'uniquePaths' => count(self::$loggedPaths),
            'threshold' => self::$notificationThreshold,
            'exceedsThreshold' => self::$sessionCount >= self::$notificationThreshold,
        ];
    }


    /**
     * Check if notification threshold is exceeded
     *
     * @return bool
     */
    static public function shouldNotify()
    {
        return self::$sessionCount >= self::$notificationThreshold;
    }


    /**
     * Get recent missing files from log
     *
     * @param int $limit Maximum entries to return
     * @return array
     */
    static public function getRecentFromLog($limit = 100)
    {
        if (!file_exists(self::$logFile)) {
            return [];
        }

        $lines = [];
        $fp = @fopen(self::$logFile, 'r');
        
        if (!$fp) {
            return [];
        }

        // Read last N lines efficiently
        $buffer = '';
        $lineCount = 0;
        
        // Seek to end
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);

        while ($pos > 0 && $lineCount < $limit) {
            $pos--;
            fseek($fp, $pos);
            $char = fgetc($fp);

            if ($char === "\n" && $buffer !== '') {
                $entry = json_decode(strrev($buffer), true);
                if ($entry) {
                    array_unshift($lines, $entry);
                    $lineCount++;
                }
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }

        // Don't forget the first line
        if ($buffer !== '' && $lineCount < $limit) {
            $entry = json_decode(strrev($buffer), true);
            if ($entry) {
                array_unshift($lines, $entry);
            }
        }

        fclose($fp);
        
        return array_slice($lines, -$limit);
    }


    /**
     * Get summary statistics from log file
     *
     * @return array
     */
    static public function getLogSummary()
    {
        $summary = [
            'enabled' => self::$enabled,
            'logFile' => self::$logFile,
            'logExists' => file_exists(self::$logFile),
            'logSize' => 0,
            'logSizeFormatted' => '0 B',
            'sessionCount' => self::$sessionCount,
            'recentEntries' => [],
        ];

        if (file_exists(self::$logFile)) {
            $size = filesize(self::$logFile);
            $summary['logSize'] = $size;
            $summary['logSizeFormatted'] = self::formatBytes($size);
            $summary['recentEntries'] = self::getRecentFromLog(10);
        }

        return $summary;
    }


    /**
     * Clear the log file
     *
     * @return bool Success
     */
    static public function clearLog()
    {
        if (file_exists(self::$logFile)) {
            return @file_put_contents(self::$logFile, '') !== false;
        }
        return true;
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
     * Output missing files summary as JSON (for API endpoint)
     */
    static public function outputJson()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Access-Control-Allow-Origin: *');
        
        $response = self::getLogSummary();
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
