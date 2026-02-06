#!/usr/bin/env php
<?php

/**
 * Convert Encoding Tool - Generate path-mapping.json
 * 
 * Scans GRF files for non-UTF-8 filenames (Korean CP949/EUC-KR) and generates
 * a path-mapping.json file that maps Korean UTF-8 paths to their GRF equivalents.
 * 
 * Usage:
 *   php tools/convert-encoding.php                    # Generate path-mapping.json
 *   php tools/convert-encoding.php --output=custom.json  # Custom output file
 *   php tools/convert-encoding.php --dry-run         # Preview without writing
 *   php tools/convert-encoding.php --verbose         # Show detailed progress
 *   php tools/convert-encoding.php --help            # Show help
 * 
 * @author roBrowser Legacy Team (Mike)
 * @version 1.0.0
 */

// Change to project root directory
chdir(dirname(__DIR__));

// Parse command line arguments
$args = array_slice($argv, 1);
$showHelp = in_array('--help', $args) || in_array('-h', $args);
$dryRun = in_array('--dry-run', $args) || in_array('-n', $args);
$verbose = in_array('--verbose', $args) || in_array('-v', $args);

// Parse --output= argument
$outputFile = 'path-mapping.json';
foreach ($args as $arg) {
    if (strpos($arg, '--output=') === 0) {
        $outputFile = substr($arg, 9);
    }
}

// Show help
if ($showHelp) {
    echo <<<HELP

╔════════════════════════════════════════════════════════════════════════════╗
║         🔤 roBrowser Remote Client - Convert Encoding Tool                 ║
╚════════════════════════════════════════════════════════════════════════════╝

Usage: php tools/convert-encoding.php [options]

Options:
  --output=FILE    Output file path (default: path-mapping.json)
  --dry-run, -n    Preview changes without writing file
  --verbose, -v    Show detailed progress
  --help, -h       Show this help message

Examples:
  php tools/convert-encoding.php                    # Generate path-mapping.json
  php tools/convert-encoding.php --output=map.json  # Custom output file
  php tools/convert-encoding.php --dry-run          # Preview only
  php tools/convert-encoding.php --verbose          # Detailed output

What this tool does:
  1. Reads DATA.INI to find GRF files
  2. Scans each GRF for non-UTF-8 filenames (Korean CP949/EUC-KR)
  3. Converts filenames from legacy encoding to Korean UTF-8
  4. Generates path-mapping.json with mappings:
     - Korean UTF-8 path → GRF path (mojibake)

The Problem:
  Client requests: /data/texture/유저인터페이스/file.tga
  GRF contains:    /data/texture/À¯ÀúÀÎÅÍÆäÀÌ½º/file.tga

The Solution:
  path-mapping.json maps the Korean path to the GRF path automatically.


HELP;
    exit(0);
}

// Header
echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║         🔤 roBrowser Remote Client - Convert Encoding Tool                 ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Check for iconv extension
$iconvAvailable = extension_loaded('iconv');
$mbstringAvailable = extension_loaded('mbstring');

echo "📋 Prerequisites:\n";
echo "   iconv extension:    " . ($iconvAvailable ? "✅ Available" : "❌ Not available") . "\n";
echo "   mbstring extension: " . ($mbstringAvailable ? "✅ Available" : "❌ Not available") . "\n";
echo "   Output file:        {$outputFile}\n";
echo "   Dry run:            " . ($dryRun ? "Yes (no file will be written)" : "No") . "\n";
echo "\n";

if (!$iconvAvailable && !$mbstringAvailable) {
    echo "❌ ERROR: Either iconv or mbstring extension is required!\n";
    echo "   Install with: apt-get install php-iconv php-mbstring\n\n";
    exit(1);
}

// Load configs
$CONFIGS = require('configs.php');
$dataIniPath = $CONFIGS['CLIENT_RESPATH'] . $CONFIGS['CLIENT_DATAINI'];

if (!file_exists($dataIniPath)) {
    echo "❌ ERROR: DATA.INI not found at: {$dataIniPath}\n\n";
    exit(1);
}

// Parse DATA.INI
$dataIni = @parse_ini_file($dataIniPath, true);
if ($dataIni === false) {
    echo "❌ ERROR: Failed to parse DATA.INI\n\n";
    exit(1);
}

// Find GRF files
$grfFiles = [];
foreach ($dataIni as $section => $values) {
    if (strtolower($section) === 'data') {
        ksort($values);
        $grfFiles = array_values($values);
        break;
    }
}

if (empty($grfFiles)) {
    echo "❌ ERROR: No GRF files found in DATA.INI\n\n";
    exit(1);
}

echo "📦 Found " . count($grfFiles) . " GRF file(s)\n\n";

// Initialize mapping
$mapping = [
    'generatedAt' => date('c'),
    'tool' => 'convert-encoding.php',
    'version' => '1.0.0',
    'grfs' => [],
    'paths' => [],
    'summary' => [
        'totalFiles' => 0,
        'totalMapped' => 0,
        'encodingsDetected' => [],
    ],
];

// Supported encodings to try (in order of priority for Korean)
$encodingsToTry = ['CP949', 'EUC-KR', 'ISO-8859-1', 'CP1252'];

// Process each GRF
foreach ($grfFiles as $index => $grfFile) {
    $grfPath = $CONFIGS['CLIENT_RESPATH'] . $grfFile;
    
    echo "📂 [{$index}] Processing: {$grfFile}\n";
    
    if (!file_exists($grfPath)) {
        echo "   ⚠️  File not found, skipping\n\n";
        continue;
    }
    
    $grfResult = processGrf($grfPath, $grfFile, $encodingsToTry, $verbose);
    
    if ($grfResult === null) {
        echo "   ❌ Failed to process GRF\n\n";
        continue;
    }
    
    // Add to mapping
    $mapping['grfs'][] = [
        'file' => $grfFile,
        'totalFiles' => $grfResult['totalFiles'],
        'mappedFiles' => $grfResult['mappedCount'],
        'encoding' => $grfResult['detectedEncoding'],
    ];
    
    $mapping['summary']['totalFiles'] += $grfResult['totalFiles'];
    $mapping['summary']['totalMapped'] += $grfResult['mappedCount'];
    
    if (!empty($grfResult['detectedEncoding'])) {
        if (!isset($mapping['summary']['encodingsDetected'][$grfResult['detectedEncoding']])) {
            $mapping['summary']['encodingsDetected'][$grfResult['detectedEncoding']] = 0;
        }
        $mapping['summary']['encodingsDetected'][$grfResult['detectedEncoding']]++;
    }
    
    // Merge paths
    foreach ($grfResult['paths'] as $koreanPath => $grfPath) {
        // Don't overwrite existing mappings (first GRF has priority)
        if (!isset($mapping['paths'][$koreanPath])) {
            $mapping['paths'][$koreanPath] = $grfPath;
        }
    }
    
    echo "   ✅ Files: " . number_format($grfResult['totalFiles']);
    echo " | Mapped: " . number_format($grfResult['mappedCount']);
    echo " | Encoding: " . ($grfResult['detectedEncoding'] ?: 'UTF-8');
    echo "\n\n";
}

// Summary
echo str_repeat('=', 80) . "\n";
echo "📊 SUMMARY\n";
echo str_repeat('=', 80) . "\n\n";

echo "   Total files scanned:  " . number_format($mapping['summary']['totalFiles']) . "\n";
echo "   Total paths mapped:   " . number_format($mapping['summary']['totalMapped']) . "\n";
echo "   Unique mappings:      " . number_format(count($mapping['paths'])) . "\n";

if (!empty($mapping['summary']['encodingsDetected'])) {
    echo "   Encodings detected:   ";
    $encStrings = [];
    foreach ($mapping['summary']['encodingsDetected'] as $enc => $count) {
        $encStrings[] = "{$enc} ({$count})";
    }
    echo implode(', ', $encStrings) . "\n";
}
echo "\n";

// Write output
if ($dryRun) {
    echo "🔍 DRY RUN - No file written\n\n";
    
    if ($verbose && !empty($mapping['paths'])) {
        echo "Sample mappings (first 10):\n";
        $count = 0;
        foreach ($mapping['paths'] as $korean => $grf) {
            echo "   {$korean}\n";
            echo "   → {$grf}\n\n";
            $count++;
            if ($count >= 10) break;
        }
    }
} else {
    // Write JSON file
    $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $jsonContent = json_encode($mapping, $jsonOptions);
    
    if ($jsonContent === false) {
        echo "❌ ERROR: Failed to encode JSON\n\n";
        exit(1);
    }
    
    $written = file_put_contents($outputFile, $jsonContent);
    
    if ($written === false) {
        echo "❌ ERROR: Failed to write to {$outputFile}\n\n";
        exit(1);
    }
    
    echo "✅ Written to: {$outputFile}\n";
    echo "   File size: " . formatBytes($written) . "\n\n";
}

echo "🎉 Done!\n\n";

if (count($mapping['paths']) > 0) {
    echo "💡 The server will now automatically resolve Korean paths.\n";
    echo "   Make sure PATH_MAPPING_ENABLED=true in your configuration.\n\n";
} else {
    echo "ℹ️  No encoding issues found - your GRFs use UTF-8 filenames.\n\n";
}

exit(0);


// ============================================================================
// FUNCTIONS
// ============================================================================

/**
 * Process a single GRF file and extract path mappings
 */
function processGrf($grfPath, $grfFile, $encodingsToTry, $verbose) {
    $fp = @fopen($grfPath, 'rb');
    if (!$fp) {
        return null;
    }
    
    $result = [
        'totalFiles' => 0,
        'mappedCount' => 0,
        'detectedEncoding' => null,
        'paths' => [],
    ];
    
    try {
        // Read header (46 bytes)
        $header = fread($fp, 46);
        if (strlen($header) < 46) {
            fclose($fp);
            return null;
        }
        
        // Check signature
        $signature = rtrim(substr($header, 0, 16), "\0");
        if ($signature !== 'Master of Magic') {
            fclose($fp);
            return null;
        }
        
        // Parse header
        $tableOffset = unpack('V', substr($header, 30, 4))[1];
        $seed = unpack('V', substr($header, 34, 4))[1];
        $nFiles = unpack('V', substr($header, 38, 4))[1];
        $version = unpack('V', substr($header, 42, 4))[1];
        
        $fileCount = max($nFiles - $seed - 7, 0);
        $result['totalFiles'] = $fileCount;
        
        // Read file table
        $fileTablePos = $tableOffset + 46;
        fseek($fp, $fileTablePos);
        
        $tableHeader = fread($fp, 8);
        if (strlen($tableHeader) < 8) {
            fclose($fp);
            return null;
        }
        
        $compressedSize = unpack('V', substr($tableHeader, 0, 4))[1];
        $compressedData = fread($fp, $compressedSize);
        
        fclose($fp);
        
        // Decompress
        $tableData = @gzuncompress($compressedData);
        if ($tableData === false) {
            $tableData = @gzinflate($compressedData);
        }
        if ($tableData === false) {
            return null;
        }
        
        // Parse file entries
        $offsetSize = ($version === 0x300) ? 8 : 4;
        $metaLen = 4 + 4 + 4 + 1 + $offsetSize;
        $position = 0;
        
        // First pass: detect encoding by sampling
        $nonUtf8Samples = [];
        $sampleLimit = min($fileCount, 500);
        $tempPosition = 0;
        
        for ($i = 0; $i < $sampleLimit && $tempPosition < strlen($tableData); $i++) {
            $end = $tempPosition;
            while ($end < strlen($tableData) && ord($tableData[$end]) !== 0) {
                $end++;
            }
            
            if ($end >= strlen($tableData)) break;
            
            $filenameBytes = substr($tableData, $tempPosition, $end - $tempPosition);
            
            if (strlen($filenameBytes) > 0 && !isValidUtf8($filenameBytes)) {
                $nonUtf8Samples[] = $filenameBytes;
                if (count($nonUtf8Samples) >= 20) break;
            }
            
            $tempPosition = $end + 1 + $metaLen;
        }
        
        // Detect best encoding
        $detectedEncoding = null;
        if (!empty($nonUtf8Samples)) {
            $detectedEncoding = detectBestEncoding($nonUtf8Samples, $encodingsToTry);
            $result['detectedEncoding'] = $detectedEncoding;
            
            if ($verbose) {
                echo "   Detected encoding: {$detectedEncoding}\n";
            }
        }
        
        // Second pass: process all files
        for ($i = 0; $i < $fileCount && $position < strlen($tableData); $i++) {
            $end = $position;
            while ($end < strlen($tableData) && ord($tableData[$end]) !== 0) {
                $end++;
            }
            
            if ($end >= strlen($tableData)) break;
            
            $filenameBytes = substr($tableData, $position, $end - $position);
            
            if (strlen($filenameBytes) > 0) {
                // Check if it needs conversion
                if (!isValidUtf8($filenameBytes) && $detectedEncoding) {
                    $grfPath = convertToLatin1Display($filenameBytes);
                    $koreanPath = convertToKorean($filenameBytes, $detectedEncoding);
                    
                    if ($koreanPath !== null && $koreanPath !== $grfPath) {
                        // Normalize paths
                        $grfPathNorm = strtolower(str_replace('\\', '/', $grfPath));
                        $koreanPathNorm = strtolower(str_replace('\\', '/', $koreanPath));
                        
                        if ($koreanPathNorm !== $grfPathNorm) {
                            $result['paths'][$koreanPathNorm] = $grfPathNorm;
                            $result['mappedCount']++;
                        }
                    }
                }
            }
            
            $position = $end + 1 + $metaLen;
        }
        
    } catch (Exception $e) {
        if (is_resource($fp)) {
            fclose($fp);
        }
        return null;
    }
    
    return $result;
}

/**
 * Check if string is valid UTF-8
 */
function isValidUtf8($str) {
    // Check for high bytes
    $hasHighBytes = false;
    for ($i = 0; $i < strlen($str); $i++) {
        if (ord($str[$i]) >= 0x80) {
            $hasHighBytes = true;
            break;
        }
    }
    
    if (!$hasHighBytes) {
        return true;
    }
    
    return mb_check_encoding($str, 'UTF-8');
}

/**
 * Detect best encoding from samples
 */
function detectBestEncoding($samples, $encodingsToTry) {
    $scores = [];
    
    foreach ($encodingsToTry as $encoding) {
        $score = 0;
        foreach ($samples as $sample) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $sample);
            if ($converted !== false && strlen($converted) > 0) {
                // Check if result looks like valid Korean or reasonable text
                if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $converted)) {
                    // Contains Korean Hangul characters
                    $score += 10;
                } elseif (mb_check_encoding($converted, 'UTF-8')) {
                    $score += 1;
                }
            }
        }
        $scores[$encoding] = $score;
    }
    
    // Return encoding with highest score
    arsort($scores);
    $best = key($scores);
    
    return ($scores[$best] > 0) ? $best : 'CP949';
}

/**
 * Convert bytes to Latin-1 display (how it appears in GRF)
 */
function convertToLatin1Display($bytes) {
    $result = '';
    for ($i = 0; $i < strlen($bytes); $i++) {
        $byte = ord($bytes[$i]);
        if ($byte < 0x80) {
            $result .= chr($byte);
        } else {
            // Convert to Latin-1 representation
            $result .= chr($byte);
        }
    }
    return mb_convert_encoding($result, 'UTF-8', 'ISO-8859-1');
}

/**
 * Convert bytes to Korean UTF-8
 */
function convertToKorean($bytes, $encoding) {
    // Try iconv first
    if (function_exists('iconv')) {
        $result = @iconv($encoding, 'UTF-8//IGNORE', $bytes);
        if ($result !== false && strlen($result) > 0) {
            return $result;
        }
    }
    
    // Fallback to mbstring
    if (function_exists('mb_convert_encoding')) {
        $result = @mb_convert_encoding($bytes, 'UTF-8', $encoding);
        if ($result !== false && strlen($result) > 0) {
            return $result;
        }
    }
    
    return null;
}

/**
 * Format bytes to human readable
 */
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}
