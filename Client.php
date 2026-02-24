<?php

/**
 * @fileoverview Client - File Manager
 * @author Vincent Thibault (alias KeyWorld - Twitter: @robrowser)
 * @version 2.0.0
 * 
 * Changelog:
 *   v2.0.0 - Added LRU cache support for improved performance
 *   v1.5.1 - Previous version
 */


final class Client
{
	/**
	 * @var string client path
	 */
	static public $path = '';


	/**
	 * @var string data.ini file
	 */
	static public $data_ini = '';


	/**
	 * @var array grf list
	 */
	static private $grfs = array();


	/**
	 * @var bool auto extract mode
	 */
	static public $AutoExtract = false;

	/**
	 * @var array Stores the file list on the data directory.
	 */
	static public $FileList = [];

	/**
	 * @var array File index for O(1) lookups
	 * Maps normalized path => ['grfIndex' => int, 'originalPath' => string]
	 */
	static private $fileIndex = [];

	/**
	 * @var bool Whether the file index has been built
	 */
	static private $indexBuilt = false;


	/**
	 * @var LRUCache File content cache
	 */
	static private $cache = null;


	/**
	 * @var array Cache configuration
	 */
	static private $indexCacheConfig = [
		'enabled' => true,
		'dir' => 'cache/',
		'encoding' => 'CP949'
	];


	/**
	 * Initialize client file
	 *
	 * @param bool $search_data_dir Whether to index the data directory
	 * @param array $cacheConfig Cache configuration (enabled, maxFiles, maxMemoryMB)
	 * @param string $grfEncoding Encoding for filenames in GRFs (e.g. 'CP949')
	 */
	static public function init($search_data_dir, $cacheConfig = array(), $grfEncoding = 'CP949')
	{
		// Initialize LRU cache
		self::initCache($cacheConfig);

		// Initialize Index Cache Config
		self::$indexCacheConfig['encoding'] = $grfEncoding;
		if (isset($GLOBALS['CONFIGS'])) {
			self::$indexCacheConfig['enabled'] = isset($GLOBALS['CONFIGS']['INDEX_CACHE_ENABLED']) ? $GLOBALS['CONFIGS']['INDEX_CACHE_ENABLED'] : true;
			self::$indexCacheConfig['dir'] = isset($GLOBALS['CONFIGS']['INDEX_CACHE_DIR']) ? $GLOBALS['CONFIGS']['INDEX_CACHE_DIR'] : 'cache/';
		}

		// Set GRF encoding
		foreach (self::$grfs as $grf) {
			$grf->setEncoding($grfEncoding);
		}

		if ($search_data_dir) {
			$cacheFile = self::getIndexCachePath('filelist');
			$cacheKey = self::getFileListCacheKey();

			if (self::$indexCacheConfig['enabled'] && ($cached = self::loadIndexCache($cacheFile, $cacheKey))) {
				self::$FileList = $cached;
			} else {
				$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(getcwd() . '/data', FilesystemIterator::SKIP_DOTS));
				foreach ($iterator as $fi) {
					self::$FileList[] = $fi->getPathname();
				}
				if (self::$indexCacheConfig['enabled']) {
					self::saveIndexCache($cacheFile, $cacheKey, self::$FileList);
				}
			}
		}

		if (empty(self::$data_ini)) {
			Debug::write('No DATA.INI file defined in configs ?');
			return;
		}

		$path = self::$path . self::$data_ini;

		if (!file_exists($path)) {
			Debug::write('File not found: ' . $path, 'error');
			return;
		}

		if (!is_readable($path)) {
			Debug::write('Can\'t read file: ' . $path, 'error');
			return;
		}

		// Setup GRF context
		$data_ini = parse_ini_file($path, true);
		$grfs     = array();
		$info     = pathinfo($path);

		$keys     = array_keys($data_ini);
		$index    = array_search('data', array_map('strtolower', $keys));

		if ($index === false) {
			Debug::write('Can\'t find token "[Data]" in "' . $path . '".', 'error');
			return;
		}

		$grfs = $data_ini[$keys[$index]];
		ksort($grfs);

		Debug::write('File ' . $path . ' loaded.', 'success');
		Debug::write('GRFs to use :', 'info');

		// Open GRFs files
		foreach ($grfs as $index => $grf_filename) {
			Debug::write($index . ') ' . $info['dirname'] . '/' . $grf_filename);

			self::$grfs[$index] = new Grf($info['dirname'] . '/' . $grf_filename);
			self::$grfs[$index]->filename = $grf_filename;
			self::$grfs[$index]->setEncoding($grfEncoding);
		}

		if (self::$cache !== null && self::$cache->isEnabled()) {
			Debug::write('LRU Cache: Enabled', 'success');
		}

		// Build file index for O(1) lookups
		if(isset($GLOBALS['CONFIGS']['INDEX_CACHE_ENABLED']) && $GLOBALS['CONFIGS']['INDEX_CACHE_ENABLED']) {
			self::buildFileIndex();
		}
	}


	/**
	 * Build file index from all GRFs for O(1) lookups
	 * Files are indexed by normalized path (lowercase, forward slashes)
	 * Later GRFs override earlier ones (same as original behavior)
	 */
	static private function buildFileIndex()
	{
		if (self::$indexBuilt) {
			return;
		}

		$cacheFile = self::getIndexCachePath('grfindex');
		$cacheKey = self::getGrfIndexCacheKey();

		if (self::$indexCacheConfig['enabled'] && ($cached = self::loadIndexCache($cacheFile, $cacheKey))) {
			self::$fileIndex = $cached;
			self::$indexBuilt = true;
			Debug::write("File index loaded from cache: " . count(self::$fileIndex) . " unique files", 'success');
			return;
		}

		$startTime = microtime(true);
		$totalFiles = 0;

		foreach (self::$grfs as $grfIndex => $grf) {
			// Load GRF if not loaded
			if (!$grf->loaded) {
				Debug::write('Loading GRF for indexing: ' . $grf->filename, 'info');
				$grf->load();
			}

			if (!$grf->loaded) {
				continue;
			}

			// Get file list from GRF
			$files = $grf->getFileList();

			foreach ($files as $filePath) {
				// Normalize path: lowercase, forward slashes
				$normalizedPath = strtolower(str_replace('\\', '/', $filePath));

				// Later GRFs override earlier ones (higher priority)
				self::$fileIndex[$normalizedPath] = [
					'grfIndex' => $grfIndex,
					'originalPath' => $filePath
				];
				$totalFiles++;
			}
		}

		self::$indexBuilt = true;
		$elapsed = round((microtime(true) - $startTime) * 1000, 2);

		Debug::write("File index built: " . count(self::$fileIndex) . " unique files from {$totalFiles} total entries in {$elapsed}ms", 'success');

		if (self::$indexCacheConfig['enabled']) {
			self::saveIndexCache($cacheFile, $cacheKey, self::$fileIndex);
		}
	}


	/**
	 * Get path to cache file
	 * 
	 * @param string $type Cache type ('filelist' or 'grfindex')
	 * @return string File path
	 */
	static private function getIndexCachePath($type)
	{
		$dir = rtrim(self::$indexCacheConfig['dir'], '/') . '/';
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		return $dir . 'index_' . $type . '.cache';
	}


	/**
	 * Get cache key for file list
	 * 
	 * @return string Cache key
	 */
	static private function getFileListCacheKey()
	{
		$dataPath = getcwd() . '/data';
		$mtime = file_exists($dataPath) ? filemtime($dataPath) : 0;
		return md5($dataPath . '_' . $mtime);
	}


	/**
	 * Get cache key for GRF index
	 * 
	 * @return string Cache key
	 */
	static private function getGrfIndexCacheKey()
	{
		$parts = [self::$indexCacheConfig['encoding']];
		foreach (self::$grfs as $grf) {
			$parts[] = $grf->filename . ':' . (file_exists($grf->filename) ? filemtime($grf->filename) : 0);
		}
		return md5(implode('|', $parts));
	}


	/**
	 * Load index from cache file
	 * 
	 * @param string $file Cache file path
	 * @param string $key Cache key
	 * @return array|false Cached data or false
	 */
	static private function loadIndexCache($file, $key)
	{
		if (!file_exists($file)) {
			return false;
		}

		$content = file_get_contents($file);
		if ($content === false) {
			return false;
		}

		$data = unserialize($content);
		if (!is_array($data) || !isset($data['key']) || !isset($data['value']) || $data['key'] !== $key) {
			return false;
		}

		return $data['value'];
	}


	/**
	 * Save index to cache file
	 * 
	 * @param string $file Cache file path
	 * @param string $key Cache key
	 * @param array $value Data to cache
	 */
	static private function saveIndexCache($file, $key, $value)
	{
		$data = [
			'key' => $key,
			'value' => $value
		];
		file_put_contents($file, serialize($data));
	}


	/**
	 * Get file index statistics
	 * 
	 * @return array Statistics about the file index
	 */
	static public function getIndexStats()
	{
		$stats = [
			'indexBuilt' => self::$indexBuilt,
			'uniqueFiles' => count(self::$fileIndex),
			'grfCount' => count(self::$grfs),
			'grfs' => []
		];

		foreach (self::$grfs as $index => $grf) {
			$stats['grfs'][$index] = [
				'filename' => $grf->filename,
				'loaded' => $grf->loaded,
				'fileCount' => $grf->loaded ? count($grf->getFileList()) : 0
			];
		}

		return $stats;
	}

	/**
	 * Initialize LRU cache
	 *
	 * @param array $config Cache configuration
	 */
	static private function initCache($config = array())
	{
		$enabled = isset($config['enabled']) ? $config['enabled'] : true;
		$maxFiles = isset($config['maxFiles']) ? $config['maxFiles'] : 100;
		$maxMemoryMB = isset($config['maxMemoryMB']) ? $config['maxMemoryMB'] : 256;

		self::$cache = new LRUCache($maxFiles, $maxMemoryMB);
		self::$cache->setEnabled($enabled);

		if ($enabled) {
			Debug::write("Cache initialized: max {$maxFiles} files, {$maxMemoryMB}MB memory", 'info');
		} else {
			Debug::write('Cache is disabled', 'info');
		}
	}


	/**
	 * Get cache instance
	 *
	 * @return LRUCache|null
	 */
	static public function getCache()
	{
		return self::$cache;
	}


	/**
	 * Get cache statistics
	 *
	 * @return array|null
	 */
	static public function getCacheStats()
	{
		if (self::$cache === null) {
			return null;
		}
		return self::$cache->getStats();
	}



	/**
	 * Get a file from client, search it on cache, data folder, then on grf
	 *
	 * @param string $path File path
	 * @return string|false File content or false if not found
	 */
	static public function getFile($path)
	{
		$local_path         = self::$path;
		$local_path        .= str_replace('\\', '/', $path);
		$local_pathEncoded  = mb_convert_encoding($local_path, 'UTF-8');
		$grf_path           = str_replace('/', '\\', $path);
		$content = null;

		Debug::write('Searching file ' . $path . '...', 'title');

		// Check cache first (fastest path)
		if (self::$cache !== null) {
			$cached = self::$cache->get($path);
			if ($cached !== null) {
				Debug::write('File found in cache', 'success');
				return $cached;
			}
		}

		// Read from local data folder
		if (file_exists($local_pathEncoded) && !is_dir($local_pathEncoded) && is_readable($local_pathEncoded)) {
			Debug::write('File found at ' . $local_path, 'success');

			$content = file_get_contents($local_pathEncoded);

			// Add to cache
			if (self::$cache !== null && $content !== false) {
				self::$cache->set($path, $content);
			}

			// Store file if auto-extract is enabled
			if (self::$AutoExtract) {
				return self::store($path, $content);
			}

			return $content;
		} else {
			Debug::write('File not found at ' . $local_path);
		}

		// Use file index for O(1) lookup
		$normalizedPath = strtolower(str_replace('\\', '/', $path));

		if (isset(self::$fileIndex[$normalizedPath])) {
			$indexEntry = self::$fileIndex[$normalizedPath];
			$grfIndex = $indexEntry['grfIndex'];
			$originalPath = $indexEntry['originalPath'];
			$grf = self::$grfs[$grfIndex];

			Debug::write("File found in index: GRF #{$grfIndex} ({$grf->filename})", 'info');

			// Ensure GRF is loaded
			if (!$grf->loaded) {
				Debug::write('Loading GRF: ' . $grf->filename, 'info');
				$grf->load();
			}

			// Get file using original path (preserves case)
			if ($grf->getFile($originalPath, $content)) {
				if (self::$AutoExtract) {
					return self::store($path, $content);
				}
				return $content;
			}
		}

		Debug::write('File not found in index, falling back to sequential search');

		// Try path mapping for Korean filenames
		if (class_exists('PathMapping')) {
			$mappedPath = PathMapping::resolve($path);
			if ($mappedPath !== null) {
				Debug::write("Path mapping found: {$path} -> {$mappedPath}", 'info');

				// Try to find the mapped path in the index
				$normalizedMapped = strtolower(str_replace('\\', '/', $mappedPath));

				if (isset(self::$fileIndex[$normalizedMapped])) {
					$indexEntry = self::$fileIndex[$normalizedMapped];
					$grfIndex = $indexEntry['grfIndex'];
					$originalPath = $indexEntry['originalPath'];
					$grf = self::$grfs[$grfIndex];

					if (!$grf->loaded) {
						$grf->load();
					}

					if ($grf->getFile($originalPath, $content)) {
						// Cache with original requested path
						if (self::$cache !== null) {
							self::$cache->set($path, $content);
						}

						if (self::$AutoExtract) {
							return self::store($path, $content);
						}
						return $content;
					}
				}

				// Try direct GRF lookup with mapped path
				$mappedGrfPath = str_replace('/', '\\', $mappedPath);
				foreach (self::$grfs as $grf) {
					if (!$grf->loaded) {
						$grf->load();
					}

					if ($grf->getFile($mappedGrfPath, $content)) {
						if (self::$cache !== null) {
							self::$cache->set($path, $content);
						}

						if (self::$AutoExtract) {
							return self::store($path, $content);
						}
						return $content;
					}
				}
			}
		}

		// Fallback: Sequential search (for files not in index or edge cases)
		// Search in GRFs
		foreach (self::$grfs as $grf) {

			// Load GRF just if needed
			if (!$grf->loaded) {
				Debug::write('Loading GRF: ' . $grf->filename, 'info');
				$grf->load();
			}

			// If file is found
			if ($grf->getFile($grf_path, $content)) {
				// Add to cache
				if (self::$cache !== null) {
					self::$cache->set($path, $content);
				}

				if (self::$AutoExtract) {
					return self::store($path, $content);
				}

				return $content;
			}
		}

		return false;
	}



	/**
	 * Storing file in data folder (convert it if needed)
	 *
	 * @param {string} save to path
	 * @param {string} file content
	 * @return {string} content
	 */
	static public function store($path, $content)
	{
		$path         = mb_convert_encoding($path, 'UTF-8', 'ISO-8859-1');
		$current_path = self::$path;
		$local_path   = $current_path . str_replace('\\', '/', $path);
		$parent_path  = preg_replace("/[^\/]+$/", '', $local_path);

		if (!file_exists($parent_path)) {
			if (!@mkdir($parent_path, 0777, true)) {
				Debug::write("Can't build path '{$parent_path}', need write permission ?", 'error');
				return $content;
			}
		}

		if (!is_writable($parent_path)) {
			Debug::write("Can't write file to '{$parent_path}', need write permission.", 'error');
			return $content;
		}

		// storing bmp images as png
		if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'bmp') {
			$img  = imagecreatefrombmpstring($content);
			$path = str_ireplace('.bmp', '.png', $local_path);
			imagepng($img, $path);
			return file_get_contents($path);
		}

		// Saving file
		file_put_contents($local_path, $content);
		return $content;
	}


	/**
	 * Search files in the GRF file and on the data directory.
	 *
	 * @param string $filter
	 * @return array file list
	 */
	static public function search($filter)
	{
		$out = array();

		$grf_filter = mb_convert_encoding('/' . $filter . '/i', 'UTF-8');
		foreach (self::$grfs as $grf) {

			if (!$grf->loaded) {
				$grf->load();
			}

			$list = $grf->search($grf_filter);
			$out  = array_unique(array_merge($out, $list));
		}

		$matches = array_filter(self::$FileList, function ($item) use ($filter) {
			return stripos($item, $filter) !== false;
		});

		$matches = array_map(function ($i) {
			return str_replace(getcwd(), '', $i);
		}, $matches);

		return array_unique(array_merge($out, $matches));
	}
}
