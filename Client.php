<?php

/**
* @fileoverview Client - File Manager
* @author Vincent Thibault (alias KeyWorld - Twitter: @robrowser)
* @version 1.5.1
*/


final class Client
{
	/**
	 * @var {string} client path
	 */
	static public $path = '';


	/**
	 * @var {string} data.ini file
	 */
	static public $data_ini = '';


	/**
	 * @var {Array} grf list
	 */
	static private $grfs = array();


	/**
	 * @var {boolean} auto extract mode
	 */
	static public $AutoExtract = false;

    /**
     * @var array Stores the file list on the data directory.
     */
    static public $FileList = [];


	/**
	 * Initialize client file
	 */
	static public function init($search_data_dir)
	{
        if($search_data_dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(getcwd().'/data', FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fi) {
                self::$FileList[] = $fi->getPathname();
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
		$data_ini = parse_ini_file($path, true );
		$grfs     = array();
		$info     = pathinfo($path);

		$keys     = array_keys($data_ini);
		$index    = array_search('data', array_map('strtolower', $keys));

		if ($index === false) {
			Debug::write('Can\'t find token "[Data]" in "' . $path . '".', 'error');
			return;
		}

		$grfs = $data_ini[ $keys[$index] ];
		ksort($grfs);

		Debug::write('File ' . $path . ' loaded.', 'success');
		Debug::write('GRFs to use :', 'info');

		// Open GRFs files
		foreach ($grfs as $index => $grf_filename) {
			Debug::write($index . ') ' . $info['dirname'] . '/' . $grf_filename);

			self::$grfs[$index] = new Grf($info['dirname'] . '/' . $grf_filename);
			self::$grfs[$index]->filename = $grf_filename;
		}
	}



	/**
	 * Get a file from client, search it on data folder first and then on grf
	 *
	 * @param {string} file path
	 * @return {boolean} success
	 */
	static public function getFile($path)
	{
		$local_path         = self::$path;
		$local_path        .= str_replace('\\', '/', $path );
		$local_pathEncoded  = mb_convert_encoding($local_path, 'UTF-8');
		$grf_path           = str_replace('/', '\\', $path );

		Debug::write('Searching file ' . $path . '...', 'title');

		// Read data first
		if (file_exists($local_pathEncoded) && !is_dir($local_pathEncoded) && is_readable($local_pathEncoded)) {
			Debug::write('File found at ' . $local_path, 'success');

			// Store file
			if(self::$AutoExtract) {
				return self::store( $path, file_get_contents($local_pathEncoded) );
			}

			return file_get_contents($local_pathEncoded);
		}
		else {
			Debug::write('File not found at ' . $local_path);
		}

		foreach (self::$grfs as $grf) {

			// Load GRF just if needed
			if (!$grf->loaded) {
				Debug::write('Loading GRF: ' . $grf->filename, 'info');
				$grf->load();
			}

			// If file is found
			if ($grf->getFile($grf_path, $content)) {
				if (self::$AutoExtract) {
					return self::store( $path, $content );
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
	static public function store( $path, $content )
	{
		$path         = utf8_encode($path);
		$current_path = self::$path;
		$local_path   = $current_path . str_replace('\\', '/', $path );
		$parent_path  = preg_replace("/[^\/]+$/", '', $local_path);

		if (!file_exists($parent_path)) {
			if (!@mkdir( $parent_path, 0777, true)) {
				Debug::write("Can't build path '{$parent_path}', need write permission ?", 'error');
				return $content;
			}
		}

		if (!is_writable($parent_path)) {
			Debug::write("Can't write file to '{$parent_path}', need write permission.", 'error');
			return $content;
		}

		// storing bmp images as png
		if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'bmp')  {
			$img  = imagecreatefrombmpstring( $content );
			$path = str_ireplace('.bmp', '.png', $local_path);
			imagepng($img, $path );
			return file_get_contents( $path );
		}

		// Saving file
		file_put_contents( $local_path, $content);
		return $content;
	}


    /**
     * Search files in the GRF file and on the data directory.
     *
     * @param {string} regex
     * @return array {Array} file list
     */
	static public function search($filter) {
		$out = array();

        $grf_filter = mb_convert_encoding('/'. $filter. '/i', 'UTF-8');
		foreach (self::$grfs as $grf) {

			if (!$grf->loaded) {
				$grf->load();
			}

			$list = $grf->search($grf_filter);
			$out  = array_unique( array_merge($out, $list) );
		}

        $matches = array_filter(self::$FileList, function($item) use ($filter) {
            return stripos($item, $filter) !== false;
        });

        return array_unique(array_merge($out, $matches));
	}
}