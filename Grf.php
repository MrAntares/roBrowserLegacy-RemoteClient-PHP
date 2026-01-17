<?php

/**
* @fileoverview Grf - Load and Parse .grf file (versions 0x200 and 0x300 without DES encryption).
* @author Vincent Thibault (alias KeyWorld - Twitter: @robrowser)
* @version 2.0.0
* 
* Changelog:
*   v2.0.0 - Added support for GRF version 0x300 (64-bit file offsets)
*   v1.0.0 - Initial version with 0x200 support
*/

class Grf
{

	/**
	 * @var string fileTable binary
	 */
	private $fileTable;


	/**
	 * @var array file header
	 */
	private $header;


	/**
	 * @var bool is file loaded
	 */
	public $loaded = false;


	/**
	 * @var resource file pointer
	 */
	protected $fp;


	/**
	 * @var string filename
	 */
	public $filename = '';


	/**
	 * @var int GRF version (0x200 or 0x300)
	 */
	private $version = 0;


	/**
	 * @var bool Whether this GRF uses 64-bit offsets (0x300)
	 */
	private $uses64BitOffsets = false;


	/**
	 * Header size in bytes
	 */
	const HEADER_SIZE = 46;

	/**
	 * Supported GRF versions
	 */
	const VERSION_200 = 0x200;
	const VERSION_300 = 0x300;


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
		$this->version = $this->header['version'];

		// Validate signature
		if ($this->header['signature'] !== 'Master of Magic') {
			Debug::write('Invalid GRF signature in "'. $this->filename .'"', 'error');
			return;
		}

		// Check version compatibility (0x200 or 0x300)
		if ($this->version !== self::VERSION_200 && $this->version !== self::VERSION_300) {
			Debug::write('Unsupported GRF version 0x'. dechex($this->version) .' in "'. $this->filename .'". Only 0x200 and 0x300 are supported.', 'error');
			return;
		}

		// Check for DES encryption (key should be all zeros for non-encrypted GRFs)
		$key = $this->header['key'];
		$hasEncryption = false;
		for ($i = 0; $i < strlen($key); $i++) {
			if (ord($key[$i]) !== 0) {
				$hasEncryption = true;
				break;
			}
		}
		
		if ($hasEncryption) {
			Debug::write('GRF "'. $this->filename .'" appears to have DES encryption. Only non-encrypted GRFs are supported.', 'error');
			return;
		}

		// Set 64-bit offset flag for version 0x300
		$this->uses64BitOffsets = ($this->version === self::VERSION_300);

		Debug::write('Loading GRF version 0x'. dechex($this->version) . ($this->uses64BitOffsets ? ' (64-bit offsets)' : ' (32-bit offsets)'), 'info');

		// Load table list
		fseek( $this->fp, $this->header['table_offset'], SEEK_CUR);
		$fileTableInfo   = unpack("Lpack_size/Lreal_size", fread($this->fp, 0x08));
		$this->fileTable = @gzuncompress( fread( $this->fp, $fileTableInfo['pack_size'] ), $fileTableInfo['real_size'] );

		// Extraction error
		if ($this->fileTable === false) {
			Debug::write('Can\'t extract fileTable in GRF "'. $this->filename .'"', 'error');
			return;
		}

		// Grf now loaded
		$this->loaded = true;
		Debug::write('GRF "'. $this->filename .'" loaded successfully', 'success');
	}


	/**
	 * Search a filename and extract its content
	 *
	 * @param string $filename File path to search
	 * @param string &$content Reference to store file content
	 * @return bool True if file was found and extracted
	 */
	public function getFile($filename, &$content)
	{
		if (!$this->loaded) {
			return false;
		}

		// Case sensitive search (faster)
		$position = strpos( $this->fileTable, $filename . "\0");

		// Case insensitive fallback (slower)
		if ($position === false){
			$position = stripos( $this->fileTable, $filename . "\0");
		}

		// File not found
		if ($position === false) {
			Debug::write('File not found in '. $this->filename);
			return false;
		}

		// Move position past the filename and null terminator
		$position += strlen($filename) + 1;

		// Extract file info from fileList
		// Structure differs between 0x200 (32-bit offset) and 0x300 (64-bit offset)
		if ($this->uses64BitOffsets) {
			// GRF 0x300: pack_size(4) + length_aligned(4) + real_size(4) + flags(1) + position(8) = 21 bytes
			$fileInfo = $this->unpackFileEntry300(substr($this->fileTable, $position, 21));
		} else {
			// GRF 0x200: pack_size(4) + length_aligned(4) + real_size(4) + flags(1) + position(4) = 17 bytes
			$fileInfo = unpack('Lpack_size/Llength_aligned/Lreal_size/Cflags/Lposition', substr($this->fileTable, $position, 17));
		}

		// Check if file is stored without encryption (flags = 1)
		if ($fileInfo['flags'] !== 1) {
			Debug::write('Can\'t decrypt file in GRF '. $this->filename . ' (flags: ' . $fileInfo['flags'] . ')', 'error');
			return false;
		}

		// Extract file content
		fseek( $this->fp, $fileInfo['position'] + self::HEADER_SIZE, SEEK_SET );
		$compressedData = fread($this->fp, $fileInfo['pack_size']);
		
		if ($compressedData === false || strlen($compressedData) < $fileInfo['pack_size']) {
			Debug::write('Failed to read compressed data from GRF '. $this->filename, 'error');
			return false;
		}

		$content = @gzuncompress($compressedData, $fileInfo['real_size']);
		
		if ($content === false) {
			Debug::write('Failed to decompress file from GRF '. $this->filename, 'error');
			return false;
		}

		Debug::write('File found and extracted from '. $this->filename, 'success');
		return true;
	}


	/**
	 * Unpack file entry for GRF 0x300 (64-bit offset)
	 *
	 * @param string $data Binary data (21 bytes)
	 * @return array File entry info
	 */
	private function unpackFileEntry300($data)
	{
		// First unpack the 32-bit values
		$info = unpack('Lpack_size/Llength_aligned/Lreal_size/Cflags', substr($data, 0, 13));
		
		// Handle 64-bit position (PHP doesn't have native 64-bit unpack on all platforms)
		// Read as two 32-bit values (little-endian)
		$posLow  = unpack('L', substr($data, 13, 4))[1];
		$posHigh = unpack('L', substr($data, 17, 4))[1];
		
		// Combine into 64-bit value
		// Note: For files > 4GB, this might lose precision on 32-bit PHP
		if (PHP_INT_SIZE >= 8) {
			$info['position'] = $posLow | ($posHigh << 32);
		} else {
			// On 32-bit PHP, we can only handle files up to 4GB
			if ($posHigh > 0) {
				Debug::write('Warning: 64-bit file offset not fully supported on 32-bit PHP', 'warning');
			}
			$info['position'] = $posLow;
		}
		
		return $info;
	}


	/**
	 * Search for files matching a regex pattern
	 *
	 * @param string $regex Regular expression pattern
	 * @return array List of matching file paths
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
	 * Get GRF version
	 *
	 * @return int Version number (0x200 or 0x300)
	 */
	public function getVersion()
	{
		return $this->version;
	}


	/**
	 * Get GRF version as hex string
	 *
	 * @return string Version as hex (e.g., "0x200")
	 */
	public function getVersionHex()
	{
		return '0x' . strtoupper(dechex($this->version));
	}


	/**
	 * Check if this GRF uses 64-bit offsets
	 *
	 * @return bool True if using 64-bit offsets (0x300)
	 */
	public function uses64Bit()
	{
		return $this->uses64BitOffsets;
	}


	/**
	 * Get GRF statistics
	 *
	 * @return array Statistics about this GRF
	 */
	public function getStats()
	{
		return array(
			'filename' => $this->filename,
			'version' => $this->getVersionHex(),
			'uses64BitOffsets' => $this->uses64BitOffsets,
			'loaded' => $this->loaded,
			'fileCount' => isset($this->header['filecount']) ? $this->header['filecount'] : 0,
			'tableSize' => $this->fileTable ? strlen($this->fileTable) : 0,
		);
	}
}