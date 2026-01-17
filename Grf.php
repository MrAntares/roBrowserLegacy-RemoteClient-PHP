<?php

/**
* @fileoverview Grf - Load and Parse .grf file (only 0x200 version without DES encryption).
* @author Vincent Thibault (alias KeyWorld - Twitter: @robrowser)
* @version 1.1.0
*/

class Grf
{

	/**
	 * @var {string} fileTable binary
	 */
	private $fileTable;


	/**
	 * @var {Array} file header
	 */
	private $header;


	/**
	 * @var {boolean} is file loaded
	 */
	public $loaded = false;


	/**
	 * @var {fp}
	 */
	protected $fp;


	/**
	 * @var {string} filename
	 */
	public $filename = '';


	/**
	 * @var {array} cached file list
	 */
	private $cachedFileList = null;


	/**
	 * @var {const} header size
	 */
	const HEADER_SIZE = 46;


	/**
	 * Constructor, open the filename if specify
	 *
	 * @param {string} optional filename
	 */
	public function __construct( $filename = false )
	{
		if ($filename) {
			$this->open($filename);
		}
	}


	/**
	 * Clean up memory
	 */
	public function __destruct()
	{
		if ($this->fp && is_resource($this->fp)) {
			fclose($this->fp);
		}
	}


	/**
	 * Open a file
	 *
	 * @param {string} file path
	 */
	public function open( $filename )
	{
		if (!file_exists($filename) || !is_readable($filename)) {
			Debug::write('Can\'t open GRF file "' . $filename . '"', 'error');
			return;
		}

		if (filesize($filename) < self::HEADER_SIZE) {
			Debug::write('Not enough data in GRF "'. $filename .'" to contain a valid header', 'error');
			return;
		}

		// Open it
		$this->fp   = fopen( $filename, 'r' );
	}


	/**
	 * Load the GRF
	 */
	public function load()
	{
		if (empty($this->fp)) {
			Debug::write('File "'. $this->filename .'" not opened yet', 'error');
			return;
		}

		// Parse header.
		$this->header = unpack("a15signature/a15key/Ltable_offset/Lseeds/Lfilecount/Lversion", fread($this->fp, self::HEADER_SIZE) );

		if ($this->header['signature'] !== 'Master of Magic' || $this->header['version'] !== 0x200) {
			Debug::write('Invalid GRF version "'. $this->filename .'". Can\'t opened it', 'error');
			return;
		}

		// Load table list
		fseek( $this->fp, $this->header['table_offset'], SEEK_CUR);
		$fileTableInfo   = unpack("Lpack_size/Lreal_size", fread($this->fp, 0x08));
		$this->fileTable = @gzuncompress( fread( $this->fp, $fileTableInfo['pack_size'] ), $fileTableInfo['real_size'] );

		// Extraction error
		if ($this->fileTable === false) {
			Debug::write('Can\t extract fileTable in GRF "'. $this->filename .'"', 'error');
			return;
		}

		// Grf now loaded
		$this->loaded = true;
	}


	/**
	 * Search a filename
	 *
	 * @param {string} filename
	 * @param {string} content reference
	 */
	public function getFile($filename, &$content)
	{
		if (!$this->loaded) {
			return false;
		}

		// Case sensitive. faster
		$position = strpos( $this->fileTable, $filename . "\0");

		// Not case sensitive, slower...
		if ($position === false){
			$position = stripos( $this->fileTable, $filename . "\0");
		}

		// File not found
		if ($position === false) {
			Debug::write('File not found in '. $this->filename);
			return false;
		}

		// Extract file info from fileList
		$position += strlen($filename) + 1;
		$fileInfo  = unpack('Lpack_size/Llength_aligned/Lreal_size/Cflags/Lposition', substr($this->fileTable, $position, 17) );

		// Just open file.
		if ($fileInfo['flags'] !== 1) {
			Debug::write('Can\'t decrypt file in GRF '. $this->filename);
			return false;
		}

		// Extract file
		fseek( $this->fp, $fileInfo['position'] + self::HEADER_SIZE, SEEK_SET );
		$content = gzuncompress( fread($this->fp, $fileInfo['pack_size']), $fileInfo['real_size'] );

		Debug::write('File found and extracted from '. $this->filename, 'success');
		return true;
	}


	/**
	 * Filter
	 * Find all occurences of a string in GRF list
	 *
	 * @param {string} regex
	 */
	public function search( $regex )
	{
		$list = array();
		@preg_match_all( $regex, $this->fileTable, $matches );

		if (!empty($matches)) {
			$list = $matches[0];
			sort($list);
		}

		return $list;
	}


	/**
	 * Get list of all files in the GRF
	 * Parses the fileTable to extract all file paths
	 * Results are cached for performance
	 *
	 * @return array List of file paths
	 */
	public function getFileList()
	{
		if (!$this->loaded) {
			return [];
		}

		// Return cached list if available
		if ($this->cachedFileList !== null) {
			return $this->cachedFileList;
		}

		$files = [];
		$offset = 0;
		$tableLength = strlen($this->fileTable);

		while ($offset < $tableLength) {
			// Find null terminator for filename
			$nullPos = strpos($this->fileTable, "\0", $offset);
			
			if ($nullPos === false) {
				break;
			}

			// Extract filename
			$filename = substr($this->fileTable, $offset, $nullPos - $offset);
			
			if (strlen($filename) > 0) {
				$files[] = $filename;
			}

			// Move past filename + null + 17 bytes of file info
			// File info: pack_size(4) + length_aligned(4) + real_size(4) + flags(1) + position(4) = 17 bytes
			$offset = $nullPos + 1 + 17;
		}

		// Cache the result
		$this->cachedFileList = $files;

		return $files;
	}


	/**
	 * Get file count
	 *
	 * @return int Number of files in the GRF
	 */
	public function getFileCount()
	{
		return count($this->getFileList());
	}
}