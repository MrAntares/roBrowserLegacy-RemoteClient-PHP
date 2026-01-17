<?php

/**
 * @fileoverview LRU (Least Recently Used) Cache Implementation
 * @author roBrowser Legacy Team
 * @version 1.0.0
 * 
 * A memory-efficient LRU cache for storing file contents.
 * When the cache reaches its limits, the least recently used items are evicted.
 */

class LRUCache
{
    /**
     * @var array Cache storage (key => value)
     */
    private $cache = array();

    /**
     * @var array Access order tracking (key => timestamp)
     */
    private $accessOrder = array();

    /**
     * @var int Maximum number of items in cache
     */
    private $maxItems;

    /**
     * @var int Maximum memory usage in bytes
     */
    private $maxMemory;

    /**
     * @var int Current memory usage in bytes
     */
    private $currentMemory = 0;

    /**
     * @var int Cache hit counter
     */
    private $hits = 0;

    /**
     * @var int Cache miss counter
     */
    private $misses = 0;

    /**
     * @var int Total evictions counter
     */
    private $evictions = 0;

    /**
     * @var bool Whether cache is enabled
     */
    private $enabled = true;


    /**
     * Constructor
     *
     * @param int $maxItems Maximum number of items (default: 100)
     * @param int $maxMemoryMB Maximum memory in MB (default: 256)
     */
    public function __construct($maxItems = 100, $maxMemoryMB = 256)
    {
        $this->maxItems = max(1, (int)$maxItems);
        $this->maxMemory = max(1, (int)$maxMemoryMB) * 1024 * 1024; // Convert to bytes
    }


    /**
     * Enable or disable the cache
     *
     * @param bool $enabled
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (bool)$enabled;
    }


    /**
     * Check if cache is enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->enabled;
    }


    /**
     * Get an item from cache
     *
     * @param string $key Cache key
     * @return mixed|null Value if found, null if not found or disabled
     */
    public function get($key)
    {
        if (!$this->enabled) {
            return null;
        }

        $normalizedKey = $this->normalizeKey($key);

        if (isset($this->cache[$normalizedKey])) {
            // Update access time (move to end = most recently used)
            $this->accessOrder[$normalizedKey] = microtime(true);
            $this->hits++;
            return $this->cache[$normalizedKey];
        }

        $this->misses++;
        return null;
    }


    /**
     * Check if key exists in cache
     *
     * @param string $key Cache key
     * @return bool
     */
    public function has($key)
    {
        if (!$this->enabled) {
            return false;
        }

        return isset($this->cache[$this->normalizeKey($key)]);
    }


    /**
     * Set an item in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @return bool True if stored, false if too large or disabled
     */
    public function set($key, $value)
    {
        if (!$this->enabled) {
            return false;
        }

        $normalizedKey = $this->normalizeKey($key);
        $valueSize = $this->getSize($value);

        // Don't cache items larger than 25% of max memory
        if ($valueSize > $this->maxMemory * 0.25) {
            Debug::write("Cache: Item too large to cache ({$this->formatBytes($valueSize)})", 'info');
            return false;
        }

        // If key already exists, remove it first (will be re-added)
        if (isset($this->cache[$normalizedKey])) {
            $this->remove($normalizedKey);
        }

        // Evict items until we have room
        while ($this->shouldEvict($valueSize)) {
            $this->evictOldest();
        }

        // Store the item
        $this->cache[$normalizedKey] = $value;
        $this->accessOrder[$normalizedKey] = microtime(true);
        $this->currentMemory += $valueSize;

        return true;
    }


    /**
     * Remove an item from cache
     *
     * @param string $key Cache key
     * @return bool True if removed, false if not found
     */
    public function remove($key)
    {
        $normalizedKey = $this->normalizeKey($key);

        if (isset($this->cache[$normalizedKey])) {
            $this->currentMemory -= $this->getSize($this->cache[$normalizedKey]);
            unset($this->cache[$normalizedKey]);
            unset($this->accessOrder[$normalizedKey]);
            return true;
        }

        return false;
    }


    /**
     * Clear all cache entries
     */
    public function clear()
    {
        $this->cache = array();
        $this->accessOrder = array();
        $this->currentMemory = 0;
    }


    /**
     * Check if we need to evict items
     *
     * @param int $newItemSize Size of item being added
     * @return bool
     */
    private function shouldEvict($newItemSize)
    {
        // Check item count
        if (count($this->cache) >= $this->maxItems) {
            return true;
        }

        // Check memory usage
        if ($this->currentMemory + $newItemSize > $this->maxMemory) {
            return true;
        }

        return false;
    }


    /**
     * Evict the oldest (least recently used) item
     */
    private function evictOldest()
    {
        if (empty($this->accessOrder)) {
            return;
        }

        // Find the least recently used key
        $oldestKey = null;
        $oldestTime = PHP_INT_MAX;

        foreach ($this->accessOrder as $key => $time) {
            if ($time < $oldestTime) {
                $oldestTime = $time;
                $oldestKey = $key;
            }
        }

        if ($oldestKey !== null) {
            $this->remove($oldestKey);
            $this->evictions++;
        }
    }


    /**
     * Normalize cache key (case-insensitive, consistent path separators)
     *
     * @param string $key
     * @return string
     */
    private function normalizeKey($key)
    {
        return strtolower(str_replace('\\', '/', $key));
    }


    /**
     * Get size of a value in bytes
     *
     * @param mixed $value
     * @return int
     */
    private function getSize($value)
    {
        if (is_string($value)) {
            return strlen($value);
        }
        return strlen(serialize($value));
    }


    /**
     * Format bytes to human-readable string
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes($bytes)
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return round($bytes / 1048576, 2) . ' MB';
        }
    }


    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getStats()
    {
        $totalRequests = $this->hits + $this->misses;
        $hitRate = $totalRequests > 0 ? round(($this->hits / $totalRequests) * 100, 2) : 0;

        return array(
            'enabled' => $this->enabled,
            'items' => count($this->cache),
            'maxItems' => $this->maxItems,
            'memoryUsed' => $this->formatBytes($this->currentMemory),
            'memoryUsedBytes' => $this->currentMemory,
            'maxMemory' => $this->formatBytes($this->maxMemory),
            'maxMemoryBytes' => $this->maxMemory,
            'memoryUsagePercent' => round(($this->currentMemory / $this->maxMemory) * 100, 2),
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hitRate' => $hitRate . '%',
            'evictions' => $this->evictions,
        );
    }


    /**
     * Get cache statistics as formatted string
     *
     * @return string
     */
    public function getStatsString()
    {
        $stats = $this->getStats();
        return sprintf(
            "Cache Stats: %d/%d items | %s/%s memory (%.1f%%) | Hit rate: %s | Evictions: %d",
            $stats['items'],
            $stats['maxItems'],
            $stats['memoryUsed'],
            $stats['maxMemory'],
            $stats['memoryUsagePercent'],
            $stats['hitRate'],
            $stats['evictions']
        );
    }
}
