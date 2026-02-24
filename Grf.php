<?php

/**
* @fileoverview Grf - Load and Parse .grf file (versions 0x200 and 0x300 with DES encryption support).
* @author Vincent Thibault (alias KeyWorld - Twitter: @robrowser)
* @version 2.2.0
*
* Changelog:
*   v2.2.0 - Added DES encryption support for encrypted GRF files
*   v2.1.0 - Added getFileList() and getFileCount() for file indexing
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
	private $header = [
		'signature' => '',
		'keys' => [],
		'table_offset' => 0,
		'seeds' => 0,
		'filecount' => 0,
		'version' => 0,
		'majorVersion' => 0,
		'minorVersion' => 0,
		'realfilecount' => 0
	];


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
	 * @var int file size
	 */
	public $filesize = 0;


	/**
	 * @var int GRF version (0x200 or 0x300)
	 */
	private $version = 0;


	/**
	 * @var bool Whether this GRF uses 64-bit offsets (0x300)
	 */
	private $uses64BitOffsets = false;


	/**
	 * @var array cached file list for performance
	 */
	private $cachedFileList = null;


	/**
	 * @var GrfDES DES decryption handler
	 */
	private $des = null;


	/**
	 * File entry flags
	 */
	const FLAG_FILE = 0x01;                // Compressed only
	const FLAG_ENCRYPT_HEADER = 0x02;      // Header encryption
	const FLAG_ENCRYPT_MIXED = 0x03;       // Mixed encryption (header blocks)
	const FLAG_ENCRYPT_FULL = 0x04;        // Unused in practice
	const FLAG_ENCRYPT_MIXED_ALT = 0x05;   // Mixed encryption variant


	/**
	 * Header size in bytes
	 */
	const HEADER_SIZE = 0x2E;

	/**
	 * Header signatures
	 */
	const SIG_MAGIC = "Master of Magic";
	const SIG_EH3 = "Event Horizon";

	/**
	 * Supported GRF versions
	 */
	const VERSION_200 = 0x200;
	const VERSION_300 = 0x300;


	/**
	 * Constructor, open the filename if specify
	 *
	 * @param string $filename optional filename
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
	 * @param string $filename file path
	 */
	public function open( $filename )
	{
		if (!file_exists($filename) || !is_readable($filename)) {
			Debug::write('Can\'t open GRF file "' . $filename . '"', 'error');
			return;
		}

		$this->filesize = filesize($filename);

		if ($this->filesize < self::HEADER_SIZE) {
			Debug::write('Not enough data in GRF "'. $filename .'" to contain a valid header', 'error');
			return;
		}

		// Open it
		$this->fp = fopen( $filename, 'r' );
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

		// read header
		$header_bytes = fread($this->fp, self::HEADER_SIZE);

		// unpack signature
		$this->header['signature'] = unpack("Z16signature", substr($header_bytes, 0, 16))['signature'];

		// check signature
		if ($this->header['signature'] !== self::SIG_MAGIC && $this->header['signature'] !== self::SIG_EH3) {
			Debug::write('Invalid GRF signature in "'. $this->filename .'", expected "'. self::SIG_MAGIC .'", got "'. $this->header['signature'] .'", or "'. self::SIG_EH3 .'", got "'. $this->header['signature'] .'",', 'error');
			return;
		}

		// unpack keys
		$this->header['keys'] = unpack("C14key", substr($header_bytes, 16, 14));

		// unpack version
		$this->header['version'] = unpack("Lversion", substr($header_bytes, 42, 4))['version'];
		$this->version = $this->header['version'];

		// check version
		if ($this->header['version'] !== self::VERSION_200 && $this->header['version'] !== self::VERSION_300) {
			Debug::write('Unsupported GRF version 0x'. dechex($this->header['version']) .' in "'. $this->filename .'". Only 0x200 and 0x300 are supported.', 'error');
			return;
		}

		// set major and minor version
		$this->header['majorVersion'] = $this->header['version'] >> 8;
		$this->header['minorVersion'] = $this->header['version'] & 0x000000FF;

		// set table offset, seeds, filecount and uses64BitOffsets based on majorVersion
		if ($this->header['majorVersion'] == 3 && $this->header['minorVersion'] == 0) {
			$this->header['table_offset'] = unpack("Qtable_offset", substr($header_bytes, 30, 8))['table_offset'];
			$this->header['seeds'] = 0;
			$this->header['filecount'] = $this->header['realfilecount'] = unpack("Lfilecount", substr($header_bytes, 38, 4))['filecount'];
			$this->uses64BitOffsets = true;
		} else {
			$this->header['table_offset'] = unpack("Ltable_offset", substr($header_bytes, 30, 4))['table_offset'];
			$this->header['seeds'] = unpack("Lseeds", substr($header_bytes, 34, 4))['seeds'];
			$this->header['filecount'] = unpack("Lfilecount", substr($header_bytes, 38, 4))['filecount'];
			$this->header['realfilecount'] = $this->header['filecount'] - $this->header['seeds'] - 7;
			$this->uses64BitOffsets = false;
		}

		// check if php has support to 64-bit
		if($this->uses64BitOffsets && PHP_INT_SIZE < 8) {
			Debug::write('GRF "'. $this->filename .'" uses 64-bit offsets, but PHP_INT_SIZE is less than 8', 'error');
			return;
		}

		// check table offset
		if ($this->header['table_offset'] < 0 || $this->header['table_offset'] + self::HEADER_SIZE > $this->filesize) {
			Debug::write('Invalid table offset in \"'. $this->filename .'\", expected between 0 and ' . $this->filesize .', got ' . $this->header['table_offset'], 'error');
			return;
		}

		// check filecount / realfilecount
		if ($this->header['filecount'] <= 0 || $this->header['realfilecount'] <= 0) {
			Debug::write('Invalid filecount / realfilecount in \"'. $this->filename .'\", expected at least 1, got ' . $this->header['filecount'] . ' / ' . $this->header['realfilecount'], 'error');
			return;
		}

		// Initialize DES decryption handler
		$this->des = new GrfDES();

		Debug::write('Loading GRF version 0x'. dechex($this->version) . ($this->uses64BitOffsets ? ' (64-bit offsets)' : ' (32-bit offsets)'), 'info');

		// move cursor to table offset
		if ($this->header['version'] == self::VERSION_300) {
			// self::VERSION_300 has a unknow Int32 field before the fileTable
			fseek( $this->fp, $this->header['table_offset'] + 4, SEEK_CUR);
		} else {
			fseek( $this->fp, $this->header['table_offset'], SEEK_CUR);
		}

		$fileTableInfo = unpack("Lpack_size/Lreal_size", fread($this->fp, 0x08));

		// check fileTableInfo
		if ($fileTableInfo['pack_size'] <= 0 || $fileTableInfo['real_size'] <= 0) {
			Debug::write('Invalid fileTableInfo in "'. $this->filename .'", expected at least 1 byte, got ' . $fileTableInfo['pack_size'] . ' / ' . $fileTableInfo['real_size'], 'error');
			return;
		}

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
			$fileInfo = unpack('Lpack_size/Llength_aligned/Lreal_size/Cflags/Qposition', substr($this->fileTable, $position, 21));
		} else {
			// GRF 0x200: pack_size(4) + length_aligned(4) + real_size(4) + flags(1) + position(4) = 17 bytes
			$fileInfo = unpack('Lpack_size/Llength_aligned/Lreal_size/Cflags/Lposition', substr($this->fileTable, $position, 17));
		}

		// Extract file content
		fseek( $this->fp, $fileInfo['position'] + self::HEADER_SIZE, SEEK_SET );

		// Handle encryption based on flags
		$flags = $fileInfo['flags'];
		$isEncrypted = ($flags !== self::FLAG_FILE);
		$readSize = $isEncrypted ? $fileInfo['length_aligned'] : $fileInfo['pack_size'];

		// Debugging
		Debug::write("File: $filename, Flags: $flags, ReadSize: $readSize, PackSize: {$fileInfo['pack_size']}, Aligned: {$fileInfo['length_aligned']}", 'info');

		$compressedData = fread($this->fp, $readSize);

		if ($compressedData === false || strlen($compressedData) < $readSize) {
			Debug::write('Failed to read data from GRF '. $this->filename, 'error');
			return false;
		}

		if ($isEncrypted) {
			// Calculate cycle from pack_size
			$cycle = 0;
			$isDataCrypted = false; // type in C#

			// Check extension
			$ext = strtolower(substr($filename, strrpos($filename, '.') ?: 0));
			$skipExtensions = ['.gnd', '.gat', '.act', '.str'];

			if (in_array($ext, $skipExtensions)) {
				$cycle = 0;
				$isDataCrypted = true;
			} else {
				$cycle = 1;
				for ($i = 10; $fileInfo['pack_size'] >= $i; $i *= 10) {
					$cycle++;
				}
			}

			if ($flags === self::FLAG_ENCRYPT_MIXED || $flags === self::FLAG_ENCRYPT_MIXED_ALT) {
				// Mixed encryption: decrypt header blocks based on file size
				$compressedData = $this->des->decryptMixed($compressedData, $cycle, $isDataCrypted);
			} elseif ($flags === self::FLAG_ENCRYPT_HEADER) {
				// Header encryption: decrypt first blocks
				$compressedData = $this->des->decryptHeader($compressedData);
			}

			// Trim padding bytes added for DES alignment
			$compressedData = substr($compressedData, 0, $fileInfo['pack_size']);
		}

		// Debug first 16 bytes of data to check for 0x78 zlib header
		$hex = bin2hex(substr($compressedData, 0, 16));
		Debug::write("File: $filename, Zlib Check (hex): $hex", 'info');

		$content = gzuncompress($compressedData, $fileInfo['real_size']);

		if ($content === false) {
			Debug::write('Failed to decompress file from GRF '. $this->filename . " (Zlib error: " . error_get_last()['message'] . ")", 'error');
			return false;
		}

		Debug::write('File found and extracted from '. $this->filename, 'success');
		return true;
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
		$entrySize = $this->getFileEntrySize();

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

			// Move past filename + null + file entry size
			// 0x200: 17 bytes (pack_size(4) + length_aligned(4) + real_size(4) + flags(1) + position(4))
			// 0x300: 21 bytes (pack_size(4) + length_aligned(4) + real_size(4) + flags(1) + position(8))
			$offset = $nullPos + 1 + $entrySize;
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
	 * Get file entry size based on GRF version
	 *
	 * @return int Entry size in bytes (17 for 0x200, 21 for 0x300)
	 */
	private function getFileEntrySize()
	{
		return $this->uses64BitOffsets ? 21 : 17;
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