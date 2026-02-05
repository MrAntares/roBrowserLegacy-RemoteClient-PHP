<?php

/**
 * @fileoverview StartupValidator - System validation on startup
 * @author roBrowser Legacy Team (Mike)
 * @version 1.0.0
 * 
 * Validates resources, configuration and dependencies before serving files.
 * Based on the Node.js version by Francisco Wallison.
 * 
 * Validates:
 * - PHP version and required extensions
 * - Required files and directories
 * - GRF file format (0x200 / 0x300)
 * - GRF file table (zlib compressed)
 * - Path encoding (UTF-8 vs legacy encodings)
 * - Environment configuration
 */

final class StartupValidator
{
    /**
     * @var array Validation errors (critical)
     */
    private $errors = [];

    /**
     * @var array Validation warnings (non-critical)
     */
    private $warnings = [];

    /**
     * @var array Validation info messages
     */
    private $info = [];

    /**
     * @var array Detailed validation results
     */
    private $validationResults = [
        'php' => null,
        'extensions' => null,
        'files' => null,
        'grfs' => null,
        'encoding' => null,
        'config' => null,
    ];

    /**
     * @var float Validation start time
     */
    private $startTime;

    /**
     * Minimum required PHP version
     */
    const MIN_PHP_VERSION = '7.4.0';

    /**
     * Required PHP extensions
     */
    const REQUIRED_EXTENSIONS = ['zlib', 'mbstring'];

    /**
     * Optional but recommended extensions
     */
    const OPTIONAL_EXTENSIONS = ['gd', 'iconv'];

    /**
     * GRF signature
     */
    const GRF_SIGNATURE = 'Master of Magic';


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->startTime = microtime(true);
    }


    /**
     * Add an error message
     * @param string $message
     */
    public function addError($message)
    {
        $this->errors[] = $message;
    }


    /**
     * Add a warning message
     * @param string $message
     */
    public function addWarning($message)
    {
        $this->warnings[] = $message;
    }


    /**
     * Add an info message
     * @param string $message
     */
    public function addInfo($message)
    {
        $this->info[] = $message;
    }


    /**
     * Run all validations
     * 
     * @param array $options Options (e.g., 'deepEncoding' => true)
     * @return array Validation results
     */
    public function validateAll($options = [])
    {
        $deepEncoding = isset($options['deepEncoding']) ? $options['deepEncoding'] : false;

        // 1. Validate PHP version
        $this->validatePhpVersion();

        // 2. Validate required extensions
        $this->validateExtensions();

        // 3. Validate required files and directories
        $this->validateRequiredFiles();

        // 4. Validate configuration
        $this->validateConfig();

        // 5. Validate GRF files
        $this->validateGrfs();

        // 6. Deep encoding validation (optional, slower)
        if ($deepEncoding) {
            $this->validateEncodingDeep();
        }

        return $this->getResults();
    }


    /**
     * Validate PHP version
     * @return bool
     */
    public function validatePhpVersion()
    {
        $currentVersion = PHP_VERSION;
        $isValid = version_compare($currentVersion, self::MIN_PHP_VERSION, '>=');

        $this->validationResults['php'] = [
            'version' => $currentVersion,
            'required' => self::MIN_PHP_VERSION,
            'valid' => $isValid,
        ];

        if ($isValid) {
            $this->addInfo("PHP version: {$currentVersion}");
        } else {
            $this->addError("PHP version {$currentVersion} is too old. Minimum required: " . self::MIN_PHP_VERSION);
        }

        return $isValid;
    }


    /**
     * Validate required PHP extensions
     * @return bool
     */
    public function validateExtensions()
    {
        $results = [
            'required' => [],
            'optional' => [],
            'valid' => true,
        ];

        // Check required extensions
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $results['required'][$ext] = $loaded;

            if ($loaded) {
                $this->addInfo("Extension '{$ext}' loaded");
            } else {
                $this->addError("Required extension '{$ext}' is not loaded!");
                $results['valid'] = false;
            }
        }

        // Check optional extensions
        foreach (self::OPTIONAL_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $results['optional'][$ext] = $loaded;

            if ($loaded) {
                $this->addInfo("Extension '{$ext}' loaded (optional)");
            } else {
                $this->addWarning("Optional extension '{$ext}' is not loaded - some features may not work");
            }
        }

        $this->validationResults['extensions'] = $results;
        return $results['valid'];
    }


    /**
     * Validate required files and directories
     * @return bool
     */
    public function validateRequiredFiles()
    {
        $checks = [
            ['path' => 'resources', 'type' => 'dir', 'required' => true, 'name' => 'resources/ folder'],
            ['path' => 'data', 'type' => 'dir', 'required' => false, 'name' => 'data/ folder'],
            ['path' => 'BGM', 'type' => 'dir', 'required' => false, 'name' => 'BGM/ folder'],
            ['path' => 'System', 'type' => 'dir', 'required' => false, 'name' => 'System/ folder'],
            ['path' => 'AI', 'type' => 'dir', 'required' => false, 'name' => 'AI/ folder'],
            ['path' => 'logs', 'type' => 'dir', 'required' => false, 'name' => 'logs/ folder'],
        ];

        $hasErrors = false;
        $results = [];

        foreach ($checks as $check) {
            $fullPath = getcwd() . '/' . $check['path'];
            $exists = file_exists($fullPath);

            if ($check['type'] === 'dir') {
                $isEmpty = $exists ? $this->isDirEmpty($fullPath) : true;
                $results[] = array_merge($check, ['exists' => $exists, 'isEmpty' => $isEmpty]);

                if ($check['required'] && !$exists) {
                    $this->addError("{$check['name']} not found!");
                    $hasErrors = true;
                } elseif ($check['required'] && $isEmpty) {
                    $this->addWarning("{$check['name']} is empty");
                } elseif (!$check['required'] && !$exists) {
                    $this->addWarning("{$check['name']} not found (optional)");
                } elseif (!$check['required'] && $isEmpty) {
                    $this->addWarning("{$check['name']} is empty");
                } else {
                    $this->addInfo("{$check['name']} OK");
                }
            } else {
                $results[] = array_merge($check, ['exists' => $exists]);

                if ($check['required'] && !$exists) {
                    $this->addError("{$check['name']} not found!");
                    $hasErrors = true;
                } elseif (!$check['required'] && !$exists) {
                    $this->addWarning("{$check['name']} not found (optional)");
                } else {
                    $this->addInfo("{$check['name']} OK");
                }
            }
        }

        $this->validationResults['files'] = [
            'valid' => !$hasErrors,
            'checks' => $results,
        ];

        return !$hasErrors;
    }


    /**
     * Check if directory is empty (ignoring placeholder files)
     * @param string $path
     * @return bool
     */
    private function isDirEmpty($path)
    {
        if (!is_dir($path)) {
            return true;
        }

        $files = scandir($path);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            // Ignore placeholder files
            if (strpos($file, 'add-') === 0 || strpos($file, 'add_') === 0) {
                continue;
            }
            return false;
        }
        return true;
    }


    /**
     * Validate configuration
     * @return bool
     */
    public function validateConfig()
    {
        $CONFIGS = require('configs.php');
        $hasErrors = false;
        $results = [];

        // Check DATA.INI path
        $dataIniPath = $CONFIGS['CLIENT_RESPATH'] . $CONFIGS['CLIENT_DATAINI'];
        $dataIniExists = file_exists($dataIniPath);

        $results['DATA_INI'] = [
            'path' => $dataIniPath,
            'exists' => $dataIniExists,
        ];

        if (!$dataIniExists) {
            $this->addError("DATA.INI not found at: {$dataIniPath}");
            $hasErrors = true;
        } else {
            $this->addInfo("DATA.INI found: {$dataIniPath}");

            // Parse DATA.INI to check GRF files
            $dataIni = @parse_ini_file($dataIniPath, true);
            if ($dataIni === false) {
                $this->addError("Failed to parse DATA.INI");
                $hasErrors = true;
            } else {
                // Find [Data] section (case-insensitive)
                $dataSection = null;
                foreach ($dataIni as $section => $values) {
                    if (strtolower($section) === 'data') {
                        $dataSection = $values;
                        break;
                    }
                }

                if ($dataSection === null) {
                    $this->addError("No [Data] section found in DATA.INI");
                    $hasErrors = true;
                } else {
                    $grfCount = count($dataSection);
                    $results['grfCount'] = $grfCount;
                    $this->addInfo("DATA.INI has {$grfCount} GRF(s) configured");
                }
            }
        }

        // Check memory limit
        $memoryLimit = ini_get('memory_limit');
        $results['memoryLimit'] = $memoryLimit;
        $this->addInfo("Memory limit: {$memoryLimit}");

        // Check important config values
        $results['config'] = [
            'DEBUG' => $CONFIGS['DEBUG'],
            'CACHE_ENABLED' => $CONFIGS['CACHE_ENABLED'],
            'COMPRESSION_ENABLED' => $CONFIGS['COMPRESSION_ENABLED'],
            'PATH_MAPPING_ENABLED' => $CONFIGS['PATH_MAPPING_ENABLED'],
        ];

        $this->validationResults['config'] = [
            'valid' => !$hasErrors,
            'details' => $results,
        ];

        return !$hasErrors;
    }


    /**
     * Validate GRF files
     * @return bool
     */
    public function validateGrfs()
    {
        $CONFIGS = require('configs.php');
        $dataIniPath = $CONFIGS['CLIENT_RESPATH'] . $CONFIGS['CLIENT_DATAINI'];

        if (!file_exists($dataIniPath)) {
            $this->validationResults['grfs'] = ['valid' => false, 'reason' => 'DATA.INI not found'];
            return false;
        }

        $dataIni = @parse_ini_file($dataIniPath, true);
        if ($dataIni === false) {
            $this->validationResults['grfs'] = ['valid' => false, 'reason' => 'Failed to parse DATA.INI'];
            return false;
        }

        // Find [Data] section
        $grfFiles = [];
        foreach ($dataIni as $section => $values) {
            if (strtolower($section) === 'data') {
                ksort($values);
                $grfFiles = array_values($values);
                break;
            }
        }

        if (empty($grfFiles)) {
            $this->validationResults['grfs'] = ['valid' => false, 'reason' => 'No GRF files in DATA.INI'];
            $this->addError("No GRF files configured in DATA.INI!");
            return false;
        }

        $grfResults = [];
        $hasInvalidGrf = false;

        foreach ($grfFiles as $grfFile) {
            $grfPath = $CONFIGS['CLIENT_RESPATH'] . $grfFile;

            if (!file_exists($grfPath)) {
                $this->addError("GRF not found: {$grfFile}");
                $grfResults[] = ['file' => $grfFile, 'exists' => false];
                $hasInvalidGrf = true;
                continue;
            }

            // Validate GRF format
            $validation = $this->validateGrfFormat($grfPath);
            $grfResults[] = array_merge(['file' => $grfFile, 'exists' => true], $validation);

            if (!$validation['valid']) {
                $errorMsg = "Incompatible GRF: {$grfFile}\n";
                
                if (isset($validation['version']) && $validation['version'] !== 'unknown') {
                    $errorMsg .= "  ❌ Version: {$validation['version']} (expected: 0x200 or 0x300)\n";
                }
                
                $errorMsg .= "  ❌ {$validation['reason']}\n";
                
                $errorMsg .= "\n  📦 FIX: Repack with GRF Builder:\n";
                $errorMsg .= "  1. Download GRF Builder: https://github.com/Tokeiburu/GRFEditor\n";
                $errorMsg .= "  2. Open GRF Builder\n";
                $errorMsg .= "  3. File → Options → Repack type → Decrypt\n";
                $errorMsg .= "  4. Click: Tools → Repack\n";
                $errorMsg .= "  5. Wait for completion and replace the original file";

                $this->addError($errorMsg);
                $hasInvalidGrf = true;
            } else {
                $this->addInfo("Valid GRF: {$grfFile} (version {$validation['version']})");

                // Path encoding diagnosis
                if (isset($validation['pathEncoding']['encoding']) && 
                    $validation['pathEncoding']['encoding'] === 'legacy') {
                    $samples = isset($validation['pathEncoding']['invalidUtf8Samples']) 
                        ? implode(' | ', array_slice($validation['pathEncoding']['invalidUtf8Samples'], 0, 3))
                        : '';
                    $this->addWarning(
                        "GRF path encoding: {$grfFile} has non-UTF-8 filenames. " .
                        "Consider generating path-mapping.json" .
                        ($samples ? " Examples: {$samples}" : '')
                    );
                }
            }
        }

        $this->validationResults['grfs'] = [
            'valid' => !$hasInvalidGrf,
            'files' => $grfResults,
            'count' => count($grfFiles),
        ];

        return !$hasInvalidGrf;
    }


    /**
     * Validate GRF file format
     * 
     * @param string $grfPath Path to GRF file
     * @return array Validation result
     */
    public function validateGrfFormat($grfPath)
    {
        $fp = @fopen($grfPath, 'rb');
        if (!$fp) {
            return [
                'valid' => false,
                'version' => 'unknown',
                'reason' => 'Cannot open file',
            ];
        }

        try {
            // Read header (46 bytes)
            $header = fread($fp, 46);
            if (strlen($header) < 46) {
                fclose($fp);
                return [
                    'valid' => false,
                    'version' => 'unknown',
                    'reason' => 'Header too small (<46 bytes)',
                ];
            }

            // Check signature (first 16 bytes, null-terminated)
            $signature = rtrim(substr($header, 0, 16), "\0");
            if ($signature !== self::GRF_SIGNATURE) {
                fclose($fp);
                return [
                    'valid' => false,
                    'version' => 'unknown',
                    'reason' => "Invalid signature: '{$signature}'",
                ];
            }

            // Parse header values
            $tableOffset = unpack('V', substr($header, 30, 4))[1];
            $seed = unpack('V', substr($header, 34, 4))[1];
            $nFiles = unpack('V', substr($header, 38, 4))[1];
            $version = unpack('V', substr($header, 42, 4))[1];

            $fileCount = max($nFiles - $seed - 7, 0);
            $versionHex = '0x' . strtoupper(dechex($version));

            // Check version
            if ($version !== 0x200 && $version !== 0x300) {
                fclose($fp);
                return [
                    'valid' => false,
                    'version' => $versionHex,
                    'reason' => "Version {$versionHex} is not supported (expected: 0x200 or 0x300)",
                ];
            }

            // Validate file table (zlib compressed)
            $fileTablePos = $tableOffset + 46;
            $fileTableValidation = $this->validateFileTable($fp, $fileTablePos, $fileCount);

            if (!$fileTableValidation['ok']) {
                fclose($fp);
                return [
                    'valid' => false,
                    'version' => $versionHex,
                    'reason' => "Failed to read file table: {$fileTableValidation['reason']}",
                    'fileTable' => $fileTableValidation,
                ];
            }

            // Analyze path encoding
            $pathEncoding = $this->analyzePathEncoding($fileTableValidation['data'], $fileCount, $version);

            fclose($fp);

            return [
                'valid' => true,
                'version' => $versionHex,
                'fileCount' => $fileCount,
                'tableOffset' => $tableOffset,
                'fileTable' => [
                    'ok' => true,
                    'compressedSize' => $fileTableValidation['compressedSize'],
                    'uncompressedSize' => $fileTableValidation['uncompressedSize'],
                ],
                'pathEncoding' => $pathEncoding,
            ];

        } catch (Exception $e) {
            fclose($fp);
            return [
                'valid' => false,
                'version' => 'error',
                'reason' => "Exception: " . $e->getMessage(),
            ];
        }
    }


    /**
     * Validate and decompress GRF file table
     * 
     * @param resource $fp File pointer
     * @param int $fileTablePos Position of file table
     * @param int $fileCount Expected file count
     * @return array Validation result
     */
    private function validateFileTable($fp, $fileTablePos, $fileCount)
    {
        // Seek to file table position
        if (fseek($fp, $fileTablePos) !== 0) {
            return ['ok' => false, 'reason' => 'Cannot seek to file table position'];
        }

        // Read file table header (8 bytes: compressed size + uncompressed size)
        $tableHeader = fread($fp, 8);
        if (strlen($tableHeader) < 8) {
            return ['ok' => false, 'reason' => 'File table header too small (<8 bytes)'];
        }

        $compressedSize = unpack('V', substr($tableHeader, 0, 4))[1];
        $uncompressedSize = unpack('V', substr($tableHeader, 4, 4))[1];

        if ($compressedSize === 0 || $uncompressedSize === 0) {
            return ['ok' => false, 'reason' => 'Invalid file table sizes (0)'];
        }

        // Sanity check: max 512MB uncompressed
        if ($uncompressedSize > 512 * 1024 * 1024) {
            return ['ok' => false, 'reason' => 'File table too large (>512MB)'];
        }

        // Read compressed data
        $compressedData = fread($fp, $compressedSize);
        if (strlen($compressedData) < $compressedSize) {
            return ['ok' => false, 'reason' => 'Could not read full compressed file table'];
        }

        // Decompress with zlib
        $uncompressedData = @gzuncompress($compressedData);
        if ($uncompressedData === false) {
            // Try with gzinflate (raw deflate)
            $uncompressedData = @gzinflate($compressedData);
        }

        if ($uncompressedData === false) {
            return ['ok' => false, 'reason' => 'Failed to decompress file table (zlib error)'];
        }

        if (strlen($uncompressedData) !== $uncompressedSize) {
            return [
                'ok' => false, 
                'reason' => "Decompressed size mismatch: got " . strlen($uncompressedData) . ", expected {$uncompressedSize}"
            ];
        }

        return [
            'ok' => true,
            'compressedSize' => $compressedSize,
            'uncompressedSize' => $uncompressedSize,
            'data' => $uncompressedData,
        ];
    }


    /**
     * Analyze path encoding in GRF file table
     * 
     * @param string $tableData Decompressed file table data
     * @param int $fileCount File count
     * @param int $version GRF version
     * @return array Encoding analysis
     */
    private function analyzePathEncoding($tableData, $fileCount, $version)
    {
        // Offset size: 4 bytes for 0x200, try both 4 and 8 for 0x300
        $offsetSize = ($version === 0x300) ? 8 : 4;
        $metaLen = 4 + 4 + 4 + 1 + $offsetSize; // compSize + origSize + alignedSize + flags + offset

        $scanLimit = min($fileCount, 1000); // Scan up to 1000 files for analysis
        $position = 0;
        $inspected = 0;
        $invalidUtf8Count = 0;
        $invalidUtf8Samples = [];
        $parseErrors = 0;

        for ($i = 0; $i < $scanLimit; $i++) {
            if ($position >= strlen($tableData)) {
                $parseErrors++;
                break;
            }

            // Find null terminator for filename
            $end = $position;
            while ($end < strlen($tableData) && ord($tableData[$end]) !== 0) {
                $end++;
            }

            if ($end >= strlen($tableData)) {
                $parseErrors++;
                break;
            }

            $filenameBytes = substr($tableData, $position, $end - $position);
            $filenameLen = strlen($filenameBytes);

            if ($filenameLen === 0) {
                $parseErrors++;
                $position = $end + 1 + $metaLen;
                continue;
            }

            // Check if valid UTF-8
            if (!$this->isValidUtf8($filenameBytes)) {
                $invalidUtf8Count++;
                if (count($invalidUtf8Samples) < 5) {
                    // Decode as latin1 for display
                    $invalidUtf8Samples[] = mb_convert_encoding($filenameBytes, 'UTF-8', 'ISO-8859-1');
                }
            }

            $inspected++;
            $position = $end + 1 + $metaLen;
        }

        return [
            'encoding' => $invalidUtf8Count > 0 ? 'legacy' : 'utf-8',
            'totalFilesInspected' => $inspected,
            'invalidUtf8Count' => $invalidUtf8Count,
            'invalidUtf8Samples' => $invalidUtf8Samples,
            'parseErrors' => $parseErrors,
            'note' => $invalidUtf8Count > 0 
                ? 'Detected non-UTF-8 filename bytes (legacy encoding like CP949/EUC-KR).'
                : 'All inspected filenames are valid UTF-8.',
        ];
    }


    /**
     * Check if string is valid UTF-8
     * 
     * @param string $str
     * @return bool
     */
    private function isValidUtf8($str)
    {
        // Check for high bytes first (quick path for ASCII-only)
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

        // Use mb_check_encoding for full UTF-8 validation
        return mb_check_encoding($str, 'UTF-8');
    }


    /**
     * Deep encoding validation (scans all files for encoding issues)
     * This is slower but more thorough
     * 
     * @return array
     */
    public function validateEncodingDeep()
    {
        $CONFIGS = require('configs.php');
        $dataIniPath = $CONFIGS['CLIENT_RESPATH'] . $CONFIGS['CLIENT_DATAINI'];

        $results = [
            'totalFiles' => 0,
            'badUfffd' => 0,
            'badC1Control' => 0,
            'mojibakeDetected' => 0,
            'needsConversion' => 0,
            'healthPercent' => 100,
            'grfs' => [],
            'filesToConvert' => [],
        ];

        if (!file_exists($dataIniPath)) {
            $this->validationResults['encoding'] = $results;
            return $results;
        }

        $dataIni = @parse_ini_file($dataIniPath, true);
        if ($dataIni === false) {
            $this->validationResults['encoding'] = $results;
            return $results;
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

        foreach ($grfFiles as $grfFile) {
            $grfPath = $CONFIGS['CLIENT_RESPATH'] . $grfFile;
            if (!file_exists($grfPath)) {
                continue;
            }

            $grfResult = $this->analyzeGrfEncodingDeep($grfPath, $grfFile);
            $results['grfs'][] = $grfResult;

            $results['totalFiles'] += $grfResult['totalFiles'];
            $results['badUfffd'] += $grfResult['badUfffd'];
            $results['badC1Control'] += $grfResult['badC1Control'];
            $results['mojibakeDetected'] += $grfResult['mojibakeDetected'];
            $results['needsConversion'] += $grfResult['needsConversion'];

            // Collect files to convert
            foreach ($grfResult['examples'] as $example) {
                if (count($results['filesToConvert']) < 50) {
                    $results['filesToConvert'][] = array_merge(['grf' => $grfFile], $example);
                }
            }
        }

        // Calculate health percentage
        if ($results['totalFiles'] > 0) {
            $badCount = $results['badUfffd'] + $results['badC1Control'];
            $results['healthPercent'] = round(
                (($results['totalFiles'] - $badCount) / $results['totalFiles']) * 100,
                2
            );
        }

        // Add warnings based on results
        if ($results['mojibakeDetected'] > 0) {
            $this->addWarning(
                "Mojibake detected: {$results['mojibakeDetected']} files need encoding conversion. " .
                "Run 'php tools/convert-encoding.php' to generate path-mapping.json"
            );
        }

        if ($results['badUfffd'] > 0) {
            $this->addWarning(
                "U+FFFD characters: {$results['badUfffd']} files have replacement characters"
            );
        }

        if ($results['badC1Control'] > 0) {
            $this->addWarning(
                "C1 Control chars: {$results['badC1Control']} files have C1 control characters"
            );
        }

        $this->addInfo("Encoding health: {$results['healthPercent']}% ({$results['totalFiles']} files)");

        $this->validationResults['encoding'] = $results;
        return $results;
    }


    /**
     * Deep encoding analysis for a single GRF
     * 
     * @param string $grfPath
     * @param string $grfFile
     * @return array
     */
    private function analyzeGrfEncodingDeep($grfPath, $grfFile)
    {
        $result = [
            'file' => $grfFile,
            'totalFiles' => 0,
            'badUfffd' => 0,
            'badC1Control' => 0,
            'mojibakeDetected' => 0,
            'needsConversion' => 0,
            'examples' => [],
        ];

        $fp = @fopen($grfPath, 'rb');
        if (!$fp) {
            return $result;
        }

        try {
            // Read header
            $header = fread($fp, 46);
            if (strlen($header) < 46) {
                fclose($fp);
                return $result;
            }

            $tableOffset = unpack('V', substr($header, 30, 4))[1];
            $seed = unpack('V', substr($header, 34, 4))[1];
            $nFiles = unpack('V', substr($header, 38, 4))[1];
            $version = unpack('V', substr($header, 42, 4))[1];

            $fileCount = max($nFiles - $seed - 7, 0);
            $result['totalFiles'] = $fileCount;

            // Read and decompress file table
            $fileTablePos = $tableOffset + 46;
            fseek($fp, $fileTablePos);

            $tableHeader = fread($fp, 8);
            if (strlen($tableHeader) < 8) {
                fclose($fp);
                return $result;
            }

            $compressedSize = unpack('V', substr($tableHeader, 0, 4))[1];
            $compressedData = fread($fp, $compressedSize);

            $tableData = @gzuncompress($compressedData);
            if ($tableData === false) {
                $tableData = @gzinflate($compressedData);
            }

            if ($tableData === false) {
                fclose($fp);
                return $result;
            }

            fclose($fp);

            // Analyze all filenames
            $offsetSize = ($version === 0x300) ? 8 : 4;
            $metaLen = 4 + 4 + 4 + 1 + $offsetSize;
            $position = 0;

            for ($i = 0; $i < $fileCount && $position < strlen($tableData); $i++) {
                $end = $position;
                while ($end < strlen($tableData) && ord($tableData[$end]) !== 0) {
                    $end++;
                }

                if ($end >= strlen($tableData)) {
                    break;
                }

                $filenameBytes = substr($tableData, $position, $end - $position);

                if (strlen($filenameBytes) > 0) {
                    // Check for U+FFFD
                    $decoded = mb_convert_encoding($filenameBytes, 'UTF-8', 'ISO-8859-1');
                    if (strpos($decoded, "\xEF\xBF\xBD") !== false) {
                        $result['badUfffd']++;
                    }

                    // Check for C1 control characters (U+0080-U+009F)
                    if ($this->hasC1Controls($filenameBytes)) {
                        $result['badC1Control']++;
                    }

                    // Check for mojibake patterns
                    if ($this->isMojibake($decoded)) {
                        $result['mojibakeDetected']++;
                        $result['needsConversion']++;

                        if (count($result['examples']) < 10) {
                            $result['examples'][] = [
                                'grfPath' => $decoded,
                                'bytes' => bin2hex($filenameBytes),
                            ];
                        }
                    }
                }

                $position = $end + 1 + $metaLen;
            }

        } catch (Exception $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }

        return $result;
    }


    /**
     * Check if string has C1 control characters (U+0080-U+009F)
     * 
     * @param string $str
     * @return bool
     */
    private function hasC1Controls($str)
    {
        for ($i = 0; $i < strlen($str); $i++) {
            $c = ord($str[$i]);
            if ($c >= 0x80 && $c <= 0x9F) {
                return true;
            }
        }
        return false;
    }


    /**
     * Check if string appears to be mojibake (mis-decoded Korean text)
     * Common patterns: À¯, Àú, ÀÎ, Å×, etc.
     * 
     * @param string $str
     * @return bool
     */
    private function isMojibake($str)
    {
        // Common mojibake patterns for Korean CP949 decoded as Latin-1
        $patterns = [
            '/[\xC0-\xCF][\x80-\xBF]/', // Àx pattern (very common)
            '/Å[ÍÎÏ]/',                 // ÅÍ, ÅÎ, etc.
            '/[\xB0-\xBF][\x80-\xBF]/', // °x pattern
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $str)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Get validation results
     * 
     * @return array
     */
    public function getResults()
    {
        $elapsedMs = round((microtime(true) - $this->startTime) * 1000, 2);

        return [
            'success' => count($this->errors) === 0,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'info' => $this->info,
            'details' => $this->validationResults,
            'summary' => [
                'errorCount' => count($this->errors),
                'warningCount' => count($this->warnings),
                'infoCount' => count($this->info),
            ],
            'elapsedMs' => $elapsedMs,
        ];
    }


    /**
     * Get validation status as JSON-ready array
     * 
     * @return array
     */
    public function getStatusJSON()
    {
        $results = $this->getResults();

        return [
            'valid' => $results['success'],
            'timestamp' => date('c'),
            'summary' => [
                'errors' => $results['summary']['errorCount'],
                'warnings' => $results['summary']['warningCount'],
            ],
            'hasWarnings' => $results['summary']['warningCount'] > 0,
            'details' => $results['details'],
            'elapsedMs' => $results['elapsedMs'],
        ];
    }


    /**
     * Print validation report to console
     * 
     * @param array|null $results
     * @return bool Returns true if validation passed
     */
    public function printReport($results = null)
    {
        if ($results === null) {
            $results = $this->getResults();
        }

        echo "\n";
        echo str_repeat('=', 80) . "\n";
        echo "📋 VALIDATION REPORT\n";
        echo str_repeat('=', 80) . "\n\n";

        // Errors
        if (!empty($results['errors'])) {
            echo "❌ ERRORS:\n";
            foreach ($results['errors'] as $error) {
                $lines = explode("\n", $error);
                foreach ($lines as $line) {
                    echo "   {$line}\n";
                }
                echo "\n";
            }
        }

        // Warnings
        if (!empty($results['warnings'])) {
            echo "⚠️  WARNINGS:\n";
            foreach ($results['warnings'] as $warning) {
                echo "   {$warning}\n";
            }
            echo "\n";
        }

        // Info
        if (!empty($results['info'])) {
            echo "✓ INFORMATION:\n";
            foreach ($results['info'] as $info) {
                echo "   {$info}\n";
            }
            echo "\n";
        }

        echo str_repeat('=', 80) . "\n";

        if ($results['success']) {
            echo "✅ Validation completed successfully!";
            if ($results['summary']['warningCount'] > 0) {
                echo " ({$results['summary']['warningCount']} warning(s))";
            }
            echo "\n";
        } else {
            echo "❌ Validation failed! {$results['summary']['errorCount']} error(s) found\n";
            echo "💡 Tip: Run 'php doctor.php' for detailed diagnosis\n";
        }

        echo str_repeat('=', 80) . "\n";
        echo "Completed in {$results['elapsedMs']}ms\n\n";

        return $results['success'];
    }


    /**
     * Quick validation (for startup check)
     * Returns true if system can operate, false if critical errors
     * 
     * @return bool
     */
    public function quickValidate()
    {
        $this->validatePhpVersion();
        $this->validateExtensions();
        $this->validateConfig();
        
        // Only check if GRFs exist, don't validate format (faster)
        $CONFIGS = require('configs.php');
        $dataIniPath = $CONFIGS['CLIENT_RESPATH'] . $CONFIGS['CLIENT_DATAINI'];
        
        if (!file_exists($dataIniPath)) {
            $this->addError("DATA.INI not found");
            return false;
        }

        return count($this->errors) === 0;
    }
}
