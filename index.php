<?php

	// Include library
	require_once('Debug.php');
	require_once('LRUCache.php');
	require_once('Grf.php');
	require_once('Bmp.php');
	require_once('Client.php');
	require_once('Compression.php');
	require_once('HttpCache.php');
	require_once('HealthCheck.php');
	$CONFIGS = require_once('configs.php');

    // Apply configs
	if ($CONFIGS['DEBUG']) {
		Debug::enable();
	}

	// Configure compression
	Compression::configure(
		$CONFIGS['COMPRESSION_ENABLED'],
		$CONFIGS['COMPRESSION_MIN_SIZE'],
		$CONFIGS['COMPRESSION_LEVEL']
	);


	Client::$path        =  '';
	Client::$data_ini    =  $CONFIGS['CLIENT_RESPATH'] . $CONFIGS['CLIENT_DATAINI'];
	Client::$AutoExtract =  (bool)$CONFIGS['CLIENT_AUTOEXTRACT'];


	// Initialize client with cache configuration
	ini_set('memory_limit', $CONFIGS['MEMORY_LIMIT']);
	Client::init($CONFIGS['CLIENT_ENABLESEARCH'], array(
		'enabled' => $CONFIGS['CACHE_ENABLED'],
		'maxFiles' => $CONFIGS['CACHE_MAX_FILES'],
		'maxMemoryMB' => $CONFIGS['CACHE_MAX_MEMORY_MB'],
	));


	/**
	 * API ENDPOINTS
	 * Handle API requests before file serving
	 */
	$requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
	$requestPath = parse_url($requestUri, PHP_URL_PATH);

	// Health check endpoint: /api/health
	if (preg_match('#/api/health/?$#i', $requestPath)) {
		HealthCheck::outputJson(false);
	}

	// Simple health check endpoint: /api/health/simple
	if (preg_match('#/api/health/simple/?$#i', $requestPath)) {
		HealthCheck::outputJson(true);
	}

	// Cache stats endpoint: /api/cache-stats
	if (preg_match('#/api/cache-stats/?$#i', $requestPath)) {
		header('Content-Type: application/json');
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Access-Control-Allow-Origin: *');
		
		$stats = [
			'cache' => Client::getCacheStats(),
			'index' => Client::getIndexStats(),
		];
		
		echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		exit;
	}


	/**
	 * SEARCH ACCESS
	 * This features is only used in map/rsm/str/grf viewer
	 * If you are not using them, you can comment this block
	 */
	if (isset($_POST['filter']) && is_string($_POST['filter'])) {
		header('Status: 200 OK', true, 200);
		header('Content-type: text/plain');

		if (!$CONFIGS['CLIENT_ENABLESEARCH']) {
			exit();
		}

		$filter = ini_get('magic_quotes_gpc') ? stripslashes($_POST['filter']) : $_POST['filter'];
		$list   = Client::search($filter);

		die( implode("\n", $list) );
	}


	/**
	 * DIRECT ACCESS
	 */
	if (empty($_SERVER['REDIRECT_STATUS']) || $_SERVER['REDIRECT_STATUS'] != 404 || empty($_SERVER['REQUEST_URI'])) {
		Debug::write('Direct access, no file requested ! You have to request a file (from the url), for example: <a href="data/clientinfo.xml">data/clientinfo.xml</a>', 'error');
		Debug::output();
	}


	// Decode path
	$path      = str_replace('\\', '/', mb_convert_encoding(urldecode($_SERVER['REQUEST_URI']),'UTF-8'));
	$path      = preg_replace('/\?.*/', '', $path); // remove query
	$directory = basename(dirname(__FILE__));

	// Check Allowed directory
	if (!preg_match( '/\/('. $directory . '\/)?(data|BGM)\//', $path)) {
		Debug::write('Forbidden directory, you can just access files located in data and BGM folder.', 'error');
		Debug::output();
	}

	// Get file
	$path = preg_replace('/(.*('. $directory . '\/)?)(data|BGM\/.*)/', '$3', $path );
	$path = str_replace('/', '\\', $path);
	$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	$file = Client::getFile($path);


	// File not found, end.
	if ($file === false) {
		header('HTTP/1.1 404 Not Found', true, 404);
		header('Cache-Control: no-store');
		Debug::write('Failed, file not found...', 'error');
		Debug::output();
	}
	else {
		Debug::write('Success !', 'success');
	}


	// Process HTTP cache headers (ETag, Cache-Control, etc.)
	// This will send 304 Not Modified if client has valid cached version
	HttpCache::processCache($file, $path, $ext);


	header('Status: 200 OK', true, 200);

	// Set content type
	header('Content-type: ' . HttpCache::getContentType($ext));

	// Output
	if (Debug::isEnable()) {
		Debug::output();
	}

	// Apply compression if appropriate (checks client support, file size, extension)
	$output = Compression::compress($file, $ext);

	// Set Content-Length header (important for compressed responses)
	header('Content-Length: ' . strlen($output));

	echo $output;
