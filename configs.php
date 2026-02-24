<?php

    return array(


        /**
         * If debug mode is set to true, you will be able to see some trace information and
         * locate more easily errors.
         *
         * Note: once the bugs are resolved, set it to false else roBrowser will not be
         * able to work properly.
         */
        'DEBUG'               => getenv('DEBUG') ?? false,


        /**
         * Define where is located your full client files
         * By default it's on the directory 'resources/' but you can update it if you need
         *
         * Note: The files required in this directory are DATA.INI and your GRFs files.
         *       All others files will not be read.
         */
        'CLIENT_RESPATH'               =>     getenv('CLIENT_RESPATH') ?? 'resources/',


        /**
         * Name of the DATA.INI file
         * This file is used to know the GRFs the remote client have to load and the right
         * order to load them.
         *
         * Note: this file name is CASE SENSITIVE and should be located in resources/ folder
         *
         * Example of the content of this file:
         *
         *	[Data]
         *	0=custom.grf
         *	1=rdata.grf
         *	2=data.grf
         */
        'CLIENT_DATAINI'               =>     getenv('CLIENT_DATAINI') ?? 'DATA.INI',


        /**
         * If set to true, files loaded from GRFs will be extracted to the data folder
         * It will avoid to load GRFs each time the client request a file and
         * save server resources.
         *
         * Note: it required write access to the data folder.
         */
        'CLIENT_AUTOEXTRACT'               => getenv('CLIENT_AUTOEXTRACT') ?? true,


        /**
         * Do we enable post method to get back information about files stored in GRF ?
         * It's used in Grf Viewer to list files of a repertoire or to search files.
         *
         * If you don't use the Grf Viewer, Model Viewer, Map Viewer and Str Viewer you
         * can just disable this feature.
         */
        'CLIENT_ENABLESEARCH'               => getenv('CLIENT_ENABLESEARCH') ?? false,


        /**
         * Set the script memory limit. This value should follow the php documentation on how to set the values.
         * @see https://www.php.net/manual/en/ini.core.php#ini.memory-limit
         */
        'MEMORY_LIMIT'               =>     getenv('MEMORY_LIMIT') ?? '1000M',


        /**
         * Gzip/Deflate Compression Settings
         * Compresses text-based responses to reduce bandwidth
         */

        /**
         * Enable or disable response compression
         * When enabled, text-based files (xml, txt, lua, etc.) will be compressed
         * 
         * Note: Apache and Nginx already compress responses, so this is not needed, unless you can't change the server configuration.
         */
        'COMPRESSION_ENABLED'        => getenv('COMPRESSION_ENABLED') ?? false,

        /**
         * Minimum file size in bytes to apply compression
         * Files smaller than this won't be compressed (overhead not worth it)
         * Default: 1024 (1KB)
         */
        'COMPRESSION_MIN_SIZE'       => getenv('COMPRESSION_MIN_SIZE') ?? 1024,

        /**
         * Compression level (1-9)
         * 1 = fastest, least compression
         * 9 = slowest, best compression
         * 6 = balanced (recommended)
         */
        'COMPRESSION_LEVEL'          => getenv('COMPRESSION_LEVEL') ?? 6,

        /**
         * Enable LRU (Least Recently Used) cache for file contents.
         * This significantly improves performance by caching frequently accessed files in memory.
         *
         * When enabled, files extracted from GRFs are cached in memory, reducing disk I/O
         * and GRF parsing for repeated requests.
         * 
         * Note: this feature is intead to be used in a daemon mode. If you are not using a daemon mode, 
         * it's recommended to disable this feature.
         */
        'CACHE_ENABLED'               => getenv('CACHE_ENABLED') ?? false,


        /**
         * Maximum number of files to keep in cache.
         * When this limit is reached, the least recently used files are evicted.
         *
         * Recommended: 100-500 depending on your server's memory
         */
        'CACHE_MAX_FILES'               => getenv('CACHE_MAX_FILES') ?? 100,


        /**
         * Maximum memory usage for cache in megabytes.
         * When this limit is reached, the least recently used files are evicted.
         *
         * Recommended: 128-512 MB depending on your server's available memory
         * Note: This should be less than your PHP memory_limit
         */
        'CACHE_MAX_MEMORY_MB'               => getenv('CACHE_MAX_MEMORY_MB') ?? 256,


        /**
         * Missing Files Log Settings
         * Log files that are requested but not found
         */

        /**
         * Enable missing files logging
         * When enabled, files that are requested but not found will be logged
         */
        'MISSING_LOG_ENABLED'        => getenv('MISSING_LOG_ENABLED') ?? false,

        /**
         * Path to the missing files log
         * Default: logs/missing-files.log
         */
        'MISSING_LOG_FILE'           => getenv('MISSING_LOG_FILE') ?? 'logs/missing-files.log',

        /**
         * Maximum entries to keep in memory per request
         * Default: 1000
         */
        'MISSING_LOG_MAX_ENTRIES'    => getenv('MISSING_LOG_MAX_ENTRIES') ?? 1000,


        /**
         * Korean Path Mapping Settings
         * Handles Korean filename encoding conversion (CP949/EUC-KR to UTF-8)
         */

        /**
         * Enable path mapping for Korean filenames
         * When enabled, Korean UTF-8 paths will be resolved to their GRF equivalents
         */
        'PATH_MAPPING_ENABLED'       => getenv('PATH_MAPPING_ENABLED') ?? true,

        /**
         * Path to the mapping file (JSON format)
         * Default: path-mapping.json
         */
        'PATH_MAPPING_FILE'          => getenv('PATH_MAPPING_FILE') ?? 'path-mapping.json',


        /**
         * GRF Filename Encoding
         * Common values: 
         * - CP949 (Korean) - Default
         * - CP874 (Thai)
         * - ISO-8859-1 (Western)
         * - UTF-8 (Modern/Repacked GRFs)
         */
        'GRF_ENCODING'               => getenv('GRF_ENCODING') ?? 'CP949',


        /**
         * Warm Cache Settings
         * Pre-load frequently accessed files into cache at startup
         */

        /**
         * Enable warm cache on startup
         * When enabled, commonly accessed files are pre-loaded into cache
         * 
         * Note: this feature is intead to be used in a daemon mode. If you are not using a daemon mode, 
         * it's recommended to disable this feature.
         */
        'WARM_CACHE_ENABLED'         => getenv('WARM_CACHE_ENABLED') ?? false,

        /**
         * Maximum number of files to warm
         * Default: 50
         */
        'WARM_CACHE_MAX_FILES'       => getenv('WARM_CACHE_MAX_FILES') ?? 50,

        /**
         * Maximum memory to use for warming in megabytes
         * Default: 50 MB
         */
        'WARM_CACHE_MAX_MEMORY_MB'   => getenv('WARM_CACHE_MAX_MEMORY_MB') ?? 50,

        /**
         * Persistent Index Cache Settings
         * Caches the file index (GRF contents and data directory scan) to avoid
         * overhead on every request.
         * 
         * Note: this feature is intead to be used in a daemon mode. If you are not using a daemon mode, 
         * it's recommended to disable this feature.
         */
        'INDEX_CACHE_ENABLED'        => getenv('INDEX_CACHE_ENABLED') ?? false,
        'INDEX_CACHE_DIR'            => getenv('INDEX_CACHE_DIR') ?? 'cache/',
    );
