#!/usr/bin/env php
<?php

/**
 * Doctor Command - Full system diagnosis
 * 
 * Usage:
 *   php doctor.php              # Basic validation
 *   php doctor.php --deep       # Deep validation including encoding check
 *   php doctor.php --help       # Show help
 * 
 * @author roBrowser Legacy Team (Mike)
 * @version 1.0.0
 */

// Change to script directory
chdir(dirname(__FILE__));

// Include required files
require_once('Debug.php');
require_once('StartupValidator.php');

// Parse command line arguments
$args = array_slice($argv, 1);
$deepEncoding = in_array('--deep', $args) || in_array('-d', $args);
$showHelp = in_array('--help', $args) || in_array('-h', $args);
$jsonOutput = in_array('--json', $args) || in_array('-j', $args);

// Show help
if ($showHelp) {
    echo <<<HELP

╔════════════════════════════════════════════════════════════════════════════╗
║            🏥 roBrowser Remote Client - Doctor (PHP)                       ║
╚════════════════════════════════════════════════════════════════════════════╝

Usage: php doctor.php [options]

Options:
  --deep, -d     Run deep encoding validation (slower but thorough)
  --json, -j     Output results as JSON
  --help, -h     Show this help message

Examples:
  php doctor.php                 # Basic validation
  php doctor.php --deep          # Deep validation with encoding analysis
  php doctor.php --json          # Output as JSON (for automation)
  php doctor.php --deep --json   # Deep validation with JSON output

What this tool validates:
  ✓ PHP version (minimum 7.4.0)
  ✓ Required extensions (zlib, mbstring)
  ✓ Optional extensions (gd, iconv)
  ✓ Required files and directories
  ✓ Configuration (DATA.INI, memory limit)
  ✓ GRF file format (0x200 / 0x300)
  ✓ GRF file table (zlib compressed)
  ✓ Path encoding (UTF-8 vs legacy)
  ✓ Mojibake detection (--deep mode)


HELP;
    exit(0);
}

// Header
if (!$jsonOutput) {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║            🏥 roBrowser Remote Client - Doctor (PHP)                       ║\n";
    echo "║                        System Diagnosis                                    ║\n";
    echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    if ($deepEncoding) {
        echo "🔬 Deep encoding validation enabled (this may take a while...)\n\n";
    }
}

// Run validation
$validator = new StartupValidator();
$results = $validator->validateAll(['deepEncoding' => $deepEncoding]);

// Output results
if ($jsonOutput) {
    echo json_encode($validator->getStatusJSON(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n";
    exit($results['success'] ? 0 : 1);
}

// Print detailed report
$validator->printReport($results);

// Print encoding report if deep validation was done
if ($deepEncoding && isset($results['details']['encoding'])) {
    printEncodingReport($results['details']['encoding']);
}

// Final recommendation
echo "\n";
if (!$results['success']) {
    echo "💡 Fix the errors above and run this command again.\n";
    echo "\n";
    exit(1);
} else {
    if ($results['summary']['warningCount'] > 0) {
        echo "💡 Consider addressing the warnings for optimal performance.\n";
    }
    echo "🎉 System is configured correctly! You can start the server.\n";
    
    // Suggest deep encoding if not done
    if (!$deepEncoding) {
        echo "\n💡 Tip: Run \"php doctor.php --deep\" for detailed encoding analysis\n";
    }
    echo "\n";
    exit(0);
}


/**
 * Print detailed encoding report
 * 
 * @param array $encoding Encoding validation results
 */
function printEncodingReport($encoding)
{
    echo "\n";
    echo str_repeat('═', 80) . "\n";
    echo "📊 ENCODING VALIDATION REPORT\n";
    echo str_repeat('═', 80) . "\n\n";

    // iconv availability
    $iconvAvailable = extension_loaded('iconv');
    echo "iconv extension available: " . ($iconvAvailable ? "✅ Yes" : "❌ No") . "\n\n";

    // Summary
    echo "📈 SUMMARY:\n";
    echo "   Total files:         " . number_format($encoding['totalFiles']) . "\n";
    echo "   Bad U+FFFD:          " . number_format($encoding['badUfffd']) . "\n";
    echo "   Bad C1 Control:      " . number_format($encoding['badC1Control']) . "\n";
    echo "   Mojibake detected:   " . number_format($encoding['mojibakeDetected']) . "\n";
    echo "   Needs conversion:    " . number_format($encoding['needsConversion']) . "\n";
    echo "   Health:              {$encoding['healthPercent']}%\n";
    echo "\n";

    // Per-GRF results
    if (!empty($encoding['grfs'])) {
        echo "📦 PER-GRF RESULTS:\n";
        foreach ($encoding['grfs'] as $grf) {
            $status = ($grf['mojibakeDetected'] > 0 || $grf['badC1Control'] > 0) ? "⚠️ " : "✅";
            echo "   {$status} {$grf['file']}\n";
            echo "      Files: " . number_format($grf['totalFiles']);
            if ($grf['mojibakeDetected'] > 0) {
                echo " | Mojibake: {$grf['mojibakeDetected']}";
            }
            if ($grf['badC1Control'] > 0) {
                echo " | C1: {$grf['badC1Control']}";
            }
            echo "\n";
        }
        echo "\n";
    }

    // Examples
    if (!empty($encoding['filesToConvert'])) {
        echo "📝 EXAMPLES OF FILES NEEDING CONVERSION:\n";
        $count = min(10, count($encoding['filesToConvert']));
        for ($i = 0; $i < $count; $i++) {
            $file = $encoding['filesToConvert'][$i];
            echo "   [{$file['grf']}] {$file['grfPath']}\n";
        }
        if (count($encoding['filesToConvert']) > 10) {
            $remaining = count($encoding['filesToConvert']) - 10;
            echo "   ... and {$remaining} more\n";
        }
        echo "\n";
    }

    // Recommendations
    if ($encoding['needsConversion'] > 0) {
        echo "💡 RECOMMENDATION:\n";
        echo "   Run 'php tools/convert-encoding.php' to generate path-mapping.json\n";
        echo "   This will allow the server to resolve Korean filenames automatically.\n";
        echo "\n";
    }

    echo str_repeat('═', 80) . "\n";
}
