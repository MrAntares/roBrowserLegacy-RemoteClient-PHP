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
	const FLAG_ENCRYPT_MIXED = 0x02;       // Mixed encryption (header blocks)
	const FLAG_ENCRYPT_HEADER = 0x03;      // Header encryption
	const FLAG_ENCRYPT_FULL = 0x04;        // Unused in practice
	const FLAG_ENCRYPT_MIXED_ALT = 0x05;   // Mixed encryption variant


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

		if (filesize($filename) < self::HEADER_SIZE) {
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

		// Set 64-bit offset flag for version 0x300
		$this->uses64BitOffsets = ($this->version === self::VERSION_300);

		// Initialize DES decryption handler
		$this->des = new GrfDES();

		Debug::write('Loading GRF version 0x'. dechex($this->version) . ($this->uses64BitOffsets ? ' (64-bit offsets)' : ' (32-bit offsets)'), 'info');

		// Load table list
		fseek( $this->fp, $this->header['table_offset'], SEEK_CUR);
		$fileTableInfo = unpack("Lpack_size/Lreal_size", fread($this->fp, 0x08));
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

		// Extract file content
		fseek( $this->fp, $fileInfo['position'] + self::HEADER_SIZE, SEEK_SET );
		$compressedData = fread($this->fp, $fileInfo['pack_size']);

		if ($compressedData === false || strlen($compressedData) < $fileInfo['pack_size']) {
			Debug::write('Failed to read compressed data from GRF '. $this->filename, 'error');
			return false;
		}

		// Handle encryption based on flags
		$flags = $fileInfo['flags'];

		if ($flags === self::FLAG_ENCRYPT_MIXED || $flags === self::FLAG_ENCRYPT_MIXED_ALT) {
			// Mixed encryption: decrypt header blocks based on file size
			$compressedData = $this->des->decryptMixed($compressedData, $fileInfo['length_aligned']);
		} elseif ($flags === self::FLAG_ENCRYPT_HEADER) {
			// Header encryption: decrypt first block only
			$compressedData = $this->des->decryptHeader($compressedData);
		} elseif ($flags !== self::FLAG_FILE) {
			Debug::write('Unknown encryption flag '. $flags .' in GRF '. $this->filename, 'error');
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


/**
 * GRF DES Decryption
 *
 * Implements the modified DES algorithm used by Ragnarok Online GRF files.
 * Based on the original roBrowser implementation.
 */
class GrfDES
{
	/**
	 * DES block size in bytes
	 */
	const BLOCK_SIZE = 8;

	/**
	 * Initial Permutation table
	 */
	private static $IP = [
		58, 50, 42, 34, 26, 18, 10,  2,
		60, 52, 44, 36, 28, 20, 12,  4,
		62, 54, 46, 38, 30, 22, 14,  6,
		64, 56, 48, 40, 32, 24, 16,  8,
		57, 49, 41, 33, 25, 17,  9,  1,
		59, 51, 43, 35, 27, 19, 11,  3,
		61, 53, 45, 37, 29, 21, 13,  5,
		63, 55, 47, 39, 31, 23, 15,  7
	];

	/**
	 * Final Permutation table (inverse of IP)
	 */
	private static $FP = [
		40,  8, 48, 16, 56, 24, 64, 32,
		39,  7, 47, 15, 55, 23, 63, 31,
		38,  6, 46, 14, 54, 22, 62, 30,
		37,  5, 45, 13, 53, 21, 61, 29,
		36,  4, 44, 12, 52, 20, 60, 28,
		35,  3, 43, 11, 51, 19, 59, 27,
		34,  2, 42, 10, 50, 18, 58, 26,
		33,  1, 41,  9, 49, 17, 57, 25
	];

	/**
	 * Nibble substitution table (RO-specific, prime numbers)
	 * Used for nibble-level transposition in the modified DES algorithm
	 */
	private static $TP = [
		0x02, 0x03, 0x05, 0x07, 0x0B, 0x0D, 0x11, 0x13,
		0x17, 0x1D, 0x1F, 0x25, 0x29, 0x2B, 0x2F, 0x35,
		0x3B, 0x3D, 0x43, 0x47, 0x49, 0x4F, 0x53, 0x59,
		0x61, 0x65, 0x67, 0x6B, 0x6D, 0x71, 0x7F, 0x83,
		0x89, 0x8B, 0x95, 0x97, 0x9D, 0xA3, 0xA7, 0xAD,
		0xB3, 0xB5, 0xBF, 0xC1, 0xC5, 0xC7, 0xD3, 0xDF,
		0xE3, 0xE5, 0xE9, 0xEF, 0xF1, 0xFB, 0x01, 0x07,
		0x0D, 0x0F, 0x15, 0x19, 0x1B, 0x25, 0x33, 0x37,
	];

	/**
	 * Expansion table
	 */
	private static $E = [
		32,  1,  2,  3,  4,  5,
		 4,  5,  6,  7,  8,  9,
		 8,  9, 10, 11, 12, 13,
		12, 13, 14, 15, 16, 17,
		16, 17, 18, 19, 20, 21,
		20, 21, 22, 23, 24, 25,
		24, 25, 26, 27, 28, 29,
		28, 29, 30, 31, 32,  1
	];

	/**
	 * P-box permutation
	 */
	private static $P = [
		16,  7, 20, 21, 29, 12, 28, 17,
		 1, 15, 23, 26,  5, 18, 31, 10,
		 2,  8, 24, 14, 32, 27,  3,  9,
		19, 13, 30,  6, 22, 11,  4, 25
	];

	/**
	 * S-boxes
	 */
	private static $S = [
		// S1
		[
			[14,  4, 13,  1,  2, 15, 11,  8,  3, 10,  6, 12,  5,  9,  0,  7],
			[ 0, 15,  7,  4, 14,  2, 13,  1, 10,  6, 12, 11,  9,  5,  3,  8],
			[ 4,  1, 14,  8, 13,  6,  2, 11, 15, 12,  9,  7,  3, 10,  5,  0],
			[15, 12,  8,  2,  4,  9,  1,  7,  5, 11,  3, 14, 10,  0,  6, 13]
		],
		// S2
		[
			[15,  1,  8, 14,  6, 11,  3,  4,  9,  7,  2, 13, 12,  0,  5, 10],
			[ 3, 13,  4,  7, 15,  2,  8, 14, 12,  0,  1, 10,  6,  9, 11,  5],
			[ 0, 14,  7, 11, 10,  4, 13,  1,  5,  8, 12,  6,  9,  3,  2, 15],
			[13,  8, 10,  1,  3, 15,  4,  2, 11,  6,  7, 12,  0,  5, 14,  9]
		],
		// S3
		[
			[10,  0,  9, 14,  6,  3, 15,  5,  1, 13, 12,  7, 11,  4,  2,  8],
			[13,  7,  0,  9,  3,  4,  6, 10,  2,  8,  5, 14, 12, 11, 15,  1],
			[13,  6,  4,  9,  8, 15,  3,  0, 11,  1,  2, 12,  5, 10, 14,  7],
			[ 1, 10, 13,  0,  6,  9,  8,  7,  4, 15, 14,  3, 11,  5,  2, 12]
		],
		// S4
		[
			[ 7, 13, 14,  3,  0,  6,  9, 10,  1,  2,  8,  5, 11, 12,  4, 15],
			[13,  8, 11,  5,  6, 15,  0,  3,  4,  7,  2, 12,  1, 10, 14,  9],
			[10,  6,  9,  0, 12, 11,  7, 13, 15,  1,  3, 14,  5,  2,  8,  4],
			[ 3, 15,  0,  6, 10,  1, 13,  8,  9,  4,  5, 11, 12,  7,  2, 14]
		],
		// S5
		[
			[ 2, 12,  4,  1,  7, 10, 11,  6,  8,  5,  3, 15, 13,  0, 14,  9],
			[14, 11,  2, 12,  4,  7, 13,  1,  5,  0, 15, 10,  3,  9,  8,  6],
			[ 4,  2,  1, 11, 10, 13,  7,  8, 15,  9, 12,  5,  6,  3,  0, 14],
			[11,  8, 12,  7,  1, 14,  2, 13,  6, 15,  0,  9, 10,  4,  5,  3]
		],
		// S6
		[
			[12,  1, 10, 15,  9,  2,  6,  8,  0, 13,  3,  4, 14,  7,  5, 11],
			[10, 15,  4,  2,  7, 12,  9,  5,  6,  1, 13, 14,  0, 11,  3,  8],
			[ 9, 14, 15,  5,  2,  8, 12,  3,  7,  0,  4, 10,  1, 13, 11,  6],
			[ 4,  3,  2, 12,  9,  5, 15, 10, 11, 14,  1,  7,  6,  0,  8, 13]
		],
		// S7
		[
			[ 4, 11,  2, 14, 15,  0,  8, 13,  3, 12,  9,  7,  5, 10,  6,  1],
			[13,  0, 11,  7,  4,  9,  1, 10, 14,  3,  5, 12,  2, 15,  8,  6],
			[ 1,  4, 11, 13, 12,  3,  7, 14, 10, 15,  6,  8,  0,  5,  9,  2],
			[ 6, 11, 13,  8,  1,  4, 10,  7,  9,  5,  0, 15, 14,  2,  3, 12]
		],
		// S8
		[
			[13,  2,  8,  4,  6, 15, 11,  1, 10,  9,  3, 14,  5,  0, 12,  7],
			[ 1, 15, 13,  8, 10,  3,  7,  4, 12,  5,  6, 11,  0, 14,  9,  2],
			[ 7, 11,  4,  1,  9, 12, 14,  2,  0,  6, 10, 13, 15,  3,  5,  8],
			[ 2,  1, 14,  7,  4, 10,  8, 13, 15, 12,  9,  0,  3,  5,  6, 11]
		]
	];

	/**
	 * Decrypt with mixed encryption (based on cycle)
	 *
	 * @param string $data Encrypted data
	 * @param int $length Aligned length from file entry
	 * @return string Decrypted data
	 */
	public function decryptMixed($data, $length)
	{
		$dataLen = strlen($data);

		if ($dataLen < self::BLOCK_SIZE) {
			return $data;
		}

		// Calculate number of blocks to decrypt based on cycle
		$cycle = $this->getCycle($length);
		$blocks = intval($dataLen / self::BLOCK_SIZE);

		// Limit to first 20 blocks (0x14)
		$decryptBlocks = min($blocks, 20);

		$result = '';
		$offset = 0;
		$blockIndex = 0;

		for ($i = 0; $i < $decryptBlocks; $i++) {
			$block = substr($data, $offset, self::BLOCK_SIZE);

			// Decrypt block if it's part of the cycle pattern
			if ($blockIndex < $cycle) {
				$block = $this->decryptBlock($block);
			} else {
				// Shuffle the block
				$block = $this->shuffleBlock($block);
			}

			$result .= $block;
			$offset += self::BLOCK_SIZE;
			$blockIndex++;

			if ($blockIndex >= $cycle + 7) {
				$blockIndex = 0;
			}
		}

		// Append remaining unencrypted data
		if ($offset < $dataLen) {
			$result .= substr($data, $offset);
		}

		return $result;
	}

	/**
	 * Decrypt header only (first block)
	 *
	 * @param string $data Encrypted data
	 * @return string Decrypted data
	 */
	public function decryptHeader($data)
	{
		$dataLen = strlen($data);

		if ($dataLen < self::BLOCK_SIZE) {
			return $data;
		}

		// Decrypt only the first block
		$firstBlock = $this->decryptBlock(substr($data, 0, self::BLOCK_SIZE));

		return $firstBlock . substr($data, self::BLOCK_SIZE);
	}

	/**
	 * Get cycle value based on aligned length
	 *
	 * @param int $length Aligned length
	 * @return int Cycle value
	 */
	private function getCycle($length)
	{
		if ($length < 3) {
			return 1;
		} elseif ($length < 5) {
			return ($length + 1);
		} elseif ($length < 7) {
			return ($length + 9);
		} elseif ($length < 11) {
			return ($length - 2);
		} elseif ($length < 13) {
			return ($length + 1);
		} elseif ($length < 15) {
			return ($length + 13);
		} elseif ($length < 17) {
			return ($length + 2);
		}
		return 15;
	}

	/**
	 * Shuffle block bytes
	 *
	 * @param string $block 8-byte block
	 * @return string Shuffled block
	 */
	private function shuffleBlock($block)
	{
		if (strlen($block) !== 8) {
			return $block;
		}

		return $block[3] . $block[4] . $block[6] . $block[0] .
		       $block[1] . $block[2] . $block[5] . $block[7];
	}

	/**
	 * Decrypt a single 8-byte DES block
	 *
	 * @param string $block 8-byte encrypted block
	 * @return string 8-byte decrypted block
	 */
	private function decryptBlock($block)
	{
		if (strlen($block) !== 8) {
			return $block;
		}

		// Convert block to bit array
		$bits = $this->bytesToBits($block);

		// Initial permutation
		$permuted = $this->permute($bits, self::$IP, 64);

		// Split into left and right halves
		$left = array_slice($permuted, 0, 32);
		$right = array_slice($permuted, 32, 32);

		// 16 rounds of DES (in reverse order for decryption)
		for ($round = 15; $round >= 0; $round--) {
			$prevRight = $right;

			// Expand right half from 32 to 48 bits
			$expanded = $this->permute($right, self::$E, 48);

			// XOR with round key
			$roundKey = $this->getRoundKey($round);
			for ($i = 0; $i < 48; $i++) {
				$expanded[$i] ^= $roundKey[$i];
			}

			// S-box substitution
			$sboxOutput = [];
			for ($i = 0; $i < 8; $i++) {
				$offset = $i * 6;
				$row = ($expanded[$offset] << 1) | $expanded[$offset + 5];
				$col = ($expanded[$offset + 1] << 3) | ($expanded[$offset + 2] << 2) |
				       ($expanded[$offset + 3] << 1) | $expanded[$offset + 4];
				$val = self::$S[$i][$row][$col];

				$sboxOutput[] = ($val >> 3) & 1;
				$sboxOutput[] = ($val >> 2) & 1;
				$sboxOutput[] = ($val >> 1) & 1;
				$sboxOutput[] = $val & 1;
			}

			// P-box permutation
			$pboxOutput = $this->permute($sboxOutput, self::$P, 32);

			// XOR with left half
			$right = [];
			for ($i = 0; $i < 32; $i++) {
				$right[] = $left[$i] ^ $pboxOutput[$i];
			}

			$left = $prevRight;
		}

		// Combine halves (swap for final step)
		$combined = array_merge($right, $left);

		// Final permutation
		$final = $this->permute($combined, self::$FP, 64);

		// Apply transposition
		$transposed = $this->transpose($this->bitsToBytes($final));

		return $transposed;
	}

	/**
	 * Get round key (RO uses fixed key pattern)
	 *
	 * @param int $round Round number (0-15)
	 * @return array 48-bit key as array
	 */
	private function getRoundKey($round)
	{
		// RO GRF uses a fixed key pattern based on round
		$key = [];
		for ($i = 0; $i < 48; $i++) {
			$key[] = (($i + $round) % 2);
		}
		return $key;
	}

	/**
	 * Apply permutation table
	 *
	 * @param array $input Input bit array
	 * @param array $table Permutation table
	 * @param int $size Output size
	 * @return array Permuted bit array
	 */
	private function permute($input, $table, $size)
	{
		$output = [];
		for ($i = 0; $i < $size; $i++) {
			$output[] = $input[$table[$i] - 1];
		}
		return $output;
	}

	/**
	 * Convert bytes to bit array
	 *
	 * @param string $bytes Input bytes
	 * @return array Bit array
	 */
	private function bytesToBits($bytes)
	{
		$bits = [];
		$len = strlen($bytes);
		for ($i = 0; $i < $len; $i++) {
			$byte = ord($bytes[$i]);
			for ($j = 7; $j >= 0; $j--) {
				$bits[] = ($byte >> $j) & 1;
			}
		}
		return $bits;
	}

	/**
	 * Convert bit array to bytes
	 *
	 * @param array $bits Input bit array
	 * @return string Output bytes
	 */
	private function bitsToBytes($bits)
	{
		$bytes = '';
		$len = count($bits);
		for ($i = 0; $i < $len; $i += 8) {
			$byte = 0;
			for ($j = 0; $j < 8 && ($i + $j) < $len; $j++) {
				$byte = ($byte << 1) | $bits[$i + $j];
			}
			$bytes .= chr($byte);
		}
		return $bytes;
	}

	/**
	 * Apply RO-specific nibble transposition
	 *
	 * Performs nibble-level substitution using the TP (prime number) table,
	 * followed by byte-level shuffling. This is part of RO's modified DES.
	 *
	 * @param string $block 8-byte block
	 * @return string Transposed block
	 */
	private function transpose($block)
	{
		if (strlen($block) !== 8) {
			return $block;
		}

		// Step 1: Apply nibble substitution using TP table
		$substituted = '';
		for ($i = 0; $i < 8; $i++) {
			$byte = ord($block[$i]);

			// Extract high and low nibbles
			$highNibble = ($byte >> 4) & 0x0F;
			$lowNibble = $byte & 0x0F;

			// Substitute nibbles using TP table
			// Use position-based lookup into TP table
			$tpIndex = $i * 2;
			$newHigh = (self::$TP[$tpIndex] >> 4) ^ $highNibble;
			$newLow = (self::$TP[$tpIndex + 1] & 0x0F) ^ $lowNibble;

			// Recombine nibbles
			$substituted .= chr((($newHigh & 0x0F) << 4) | ($newLow & 0x0F));
		}

		// Step 2: Apply byte-level shuffling
		return $substituted[3] . $substituted[4] . $substituted[6] . $substituted[0] .
		       $substituted[1] . $substituted[2] . $substituted[5] . $substituted[7];
	}
}
