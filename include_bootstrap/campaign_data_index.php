<?php

/**
 * Helper class for reading, modifying, and writing campaign data index.json files.
 * 
 * The index.json lives at cache/campaign_data/{campaign_id}/index.json and tracks
 * which .bin files belong to a campaign and any processing errors.
 * Entries contain only path and name. Bin-to-map matching is stored in the
 * database (map.bin column), not in the index.
 */
class CampaignDataIndex
{
  private string $cache_dir;

  public string $status;
  public ?string $message;
  public int $bin_count;
  public array $data;

  private function __construct(string $cache_dir, array $fields)
  {
    $this->cache_dir = $cache_dir;
    $this->status = $fields['status'];
    $this->message = $fields['message'];
    $this->bin_count = $fields['bin_count'];
    $this->data = $fields['data'];
  }

  #region Factory Methods
  /**
   * Load an existing index.json from the campaign cache directory.
   * Returns null if the file does not exist or cannot be decoded.
   */
  public static function load(string $cache_dir): ?self
  {
    $path = "{$cache_dir}/index.json";
    if (!file_exists($path)) {
      return null;
    }
    $json = file_get_contents($path);
    if ($json === false) {
      return null;
    }
    $arr = json_decode($json, true);
    if ($arr === null) {
      return null;
    }
    return new self($cache_dir, [
      'status' => $arr['status'] ?? 'ok',
      'message' => $arr['message'] ?? null,
      'bin_count' => $arr['bin_count'] ?? 0,
      'data' => $arr['data'] ?? [],
    ]);
  }

  /**
   * Create an index representing an error state with no data entries.
   */
  public static function create_error(string $cache_dir, string $message): self
  {
    return new self($cache_dir, [
      'status' => 'error',
      'message' => $message,
      'bin_count' => 0,
      'data' => [],
    ]);
  }

  /**
   * Create an index from a completed processing run.
   * 
   * @param string $cache_dir
   * @param array $map_bins Array of bin entries (each with path and optionally name)
   * @param int[] $conversion_errors Indices into $map_bins that failed conversion
   */
  public static function create_from_processing(string $cache_dir, array $map_bins, array $conversion_errors): self
  {
    // Mark bins with conversion errors
    $conversion_error_set = array_flip($conversion_errors);
    foreach ($map_bins as $i => &$entry) {
      if (isset($conversion_error_set[$i])) {
        $entry['conversion_error'] = true;
      }
    }
    unset($entry);

    $has_errors = count($conversion_errors) > 0;

    return new self($cache_dir, [
      'status' => $has_errors ? 'error' : 'ok',
      'message' => $has_errors ? count($conversion_errors) . ' bin(s) failed to convert' : null,
      'bin_count' => count($map_bins),
      'data' => $map_bins,
    ]);
  }
  #endregion

  #region Data Manipulation
  /**
   * Remove all data entries where path matches the given bin path.
   */
  public function remove_by_path(string $path): void
  {
    $this->data = array_values(array_filter($this->data, function ($entry) use ($path) {
      return !isset($entry['path']) || $entry['path'] !== $path;
    }));
    $this->recalculate_counts();
  }

  /**
   * Add a new data entry if it doesn't already exist (matched by path).
   * Returns true if the entry was added, false if it already existed.
   */
  public function add_entry(array $entry): bool
  {
    // Check for duplicate path
    if (isset($entry['path'])) {
      foreach ($this->data as $existing) {
        if (isset($existing['path']) && $existing['path'] === $entry['path']) {
          return false;
        }
      }
    }

    $this->data[] = $entry;
    $this->recalculate_counts();
    return true;
  }

  private function recalculate_counts(): void
  {
    $this->bin_count = count($this->data);
  }
  #endregion

  #region Persistence
  /**
   * Save the index to cache_dir/index.json. Creates the directory if needed.
   */
  public function save(): void
  {
    if (!is_dir($this->cache_dir)) {
      mkdir($this->cache_dir, 0755, true);
    }

    $output = [
      'status' => $this->status,
      'message' => $this->message,
      'bin_count' => $this->bin_count,
      'data' => $this->data,
    ];

    file_put_contents(
      "{$this->cache_dir}/index.json",
      json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
  }
  #endregion
}
