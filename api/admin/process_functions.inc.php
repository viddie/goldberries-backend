<?php

/**
 * Shared functions for campaign/gamebanana mod processing.
 * Used by both process-campaign.php and process-gamebanana.php.
 */

#region Parse GameBanana URL
/**
 * Parses a full GameBanana URL into its components.
 * Supports both mods and wips categories.
 *
 * @param string $url Full GameBanana URL (e.g. https://gamebanana.com/mods/12345 or https://gamebanana.com/wips/83276)
 * @return array{category: string, item_type: string, id: int}|null Parsed components, or null if invalid
 */
function parse_gamebanana_url($url)
{
  if (!is_string($url))
    return null;
  if (!preg_match('#^https://gamebanana\.com/(mods|wips)/(\d+)#', $url, $matches))
    return null;

  $category = $matches[1];
  $item_type_map = ['mods' => 'Mod', 'wips' => 'Wip'];

  return [
    'category' => $category,
    'item_type' => $item_type_map[$category],
    'id' => intval($matches[2]),
  ];
}

/**
 * Builds the directory key for a GameBanana item (used in temp/cache directory paths).
 * Format: "{category}_{id}" to avoid ID collisions between mods and wips.
 *
 * @param string $category 'mods' or 'wips'
 * @param int $id GameBanana item ID
 * @return string Directory key (e.g. "mods_12345", "wips_83276")
 */
function gamebanana_dir_key($category, $id)
{
  return "{$category}_{$id}";
}
#endregion

#region Download and Scan Mod
/**
 * Downloads mod files from GameBanana and scans them for .bin map files and English.txt.
 * This is the shared pipeline used by both campaign processing and standalone GameBanana processing.
 *
 * @param int $mod_id GameBanana item ID
 * @param string $item_type GameBanana API item type ('Mod' or 'Wip')
 * @param string $temp_dir Directory to cache downloaded ZIP files
 * @param string $cache_dir Directory for error index output
 * @param bool $regenerate If true, forces re-download of ZIP files
 * @return array{success: bool, error?: string, gb_files?: array, bin_files?: array, map_bins?: array, dialog_map?: array, english_txt_found?: bool}
 */
function download_and_scan_mod($mod_id, $item_type, $temp_dir, $cache_dir, $regenerate = false)
{
  // If regenerate is requested, wipe the temp dir (downloaded ZIPs) to force re-download
  if ($regenerate && is_dir($temp_dir))
    delete_directory_recursive($temp_dir);
  if (!is_dir($temp_dir))
    mkdir($temp_dir, 0755, true);

  // Fetch file list from GameBanana API
  $api_url = "https://gamebanana.com/apiv11/{$item_type}/{$mod_id}?_csvProperties=_aFiles";
  $api_response = fetch_data($api_url);
  if ($api_response === false || $api_response === '') {
    delete_directory_recursive($temp_dir);
    $error = "Failed to fetch mod info from GameBanana";
    write_error_index($cache_dir, $error);
    return ['success' => false, 'error' => $error];
  }

  $mod_data = json_decode($api_response, true);
  if (!isset($mod_data['_aFiles']) || count($mod_data['_aFiles']) === 0) {
    delete_directory_recursive($temp_dir);
    $error = "No files found on GameBanana for mod {$mod_id}";
    write_error_index($cache_dir, $error);
    return ['success' => false, 'error' => $error];
  }

  $gb_files = $mod_data['_aFiles'];

  // Download all mod files (skip if already cached, unless regenerate is set)
  foreach ($gb_files as $file_info) {
    $filename = $file_info['_sFile'];
    $download_url = $file_info['_sDownloadUrl'];
    $dest = "{$temp_dir}/{$filename}";

    if (file_exists($dest)) {
      continue;
    }

    if (!download_large_file($download_url, $dest)) {
      $error = "Failed to download: {$filename}";
      write_error_index($cache_dir, $error);
      return ['success' => false, 'error' => $error];
    }
  }

  // Enumerate zip contents directly (no extraction to disk).
  // This avoids issues with ZipArchive::extractTo silently failing to extract some files on Windows.
  $bin_candidates = []; // keyed by Maps-relative path
  $english_txt_info = null; // tracks which zip contains the best English.txt
  $english_txt_date = -1;

  foreach ($gb_files as $file_info) {
    $filename = $file_info['_sFile'];
    $upload_date = $file_info['_tsDateAdded'] ?? 0;
    $archive_path = "{$temp_dir}/{$filename}";

    $zip = new ZipArchive();
    if ($zip->open($archive_path) !== true) {
      continue; // Skip non-zip files (images, readmes, etc.)
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
      $entry_name = $zip->getNameIndex($i);
      $normalized = str_replace('\\', '/', $entry_name);

      // Skip macOS resource fork entries (__MACOSX folder)
      if (preg_match('#(^|/)__MACOSX/#i', $normalized)) {
        continue;
      }

      // Check for Dialog/English.txt at archive root only (exclude __MACOSX etc.)
      if (preg_match('#^(?:[^/]+/)?Dialog/English\.txt$#i', $normalized)) {
        if ($upload_date > $english_txt_date) {
          $english_txt_info = ['zip_path' => $archive_path, 'entry_name' => $entry_name];
          $english_txt_date = $upload_date;
        }
      }

      // Check for Maps/**/*.bin at archive root only (exclude __MACOSX etc.)
      if (preg_match('#^(?:[^/]+/)?Maps/(.+\.bin)$#i', $normalized, $m)) {
        $relative_path = $m[1];
        if (!isset($bin_candidates[$relative_path]) || $upload_date > $bin_candidates[$relative_path]['upload_date']) {
          $bin_candidates[$relative_path] = [
            'zip_path' => $archive_path,
            'entry_name' => $entry_name,
            'relative' => $relative_path,
            'upload_date' => $upload_date,
          ];
        }
      }
    }

    $zip->close();
  }

  // Parse English.txt from zip
  $dialog_map = [];
  if ($english_txt_info !== null) {
    $zip = new ZipArchive();
    if ($zip->open($english_txt_info['zip_path']) === true) {
      $english_content = $zip->getFromName($english_txt_info['entry_name']);
      if ($english_content !== false) {
        $dialog_map = parse_english_txt($english_content);
      }
      $zip->close();
    }
  }

  // Assign names from dialog map
  foreach ($bin_candidates as $rel_path => &$entry) {
    $dialog_key = path_to_dialog_key($rel_path);
    $entry['name'] = $dialog_map[$dialog_key] ?? null;
  }
  unset($entry);

  // Build final deduplicated list (re-index as a plain array)
  $bin_files = array_values($bin_candidates);

  // Build data structure for index.json
  $map_bins = [];
  foreach ($bin_files as $entry) {
    $map_entry = ['path' => $entry['relative']];
    if ($entry['name'] !== null) {
      $map_entry['name'] = $entry['name'];
    }
    $map_bins[] = $map_entry;
  }

  return [
    'success' => true,
    'gb_files' => $gb_files,
    'bin_files' => $bin_files,
    'map_bins' => $map_bins,
    'dialog_map' => $dialog_map,
    'english_txt_found' => $english_txt_info !== null,
  ];
}
#endregion

#region Convert Bins to JSON
/**
 * Converts .bin files to JSON via maddie480's service and writes them to the cache directory.
 * Returns the list of bin indices that failed conversion.
 *
 * @param array $map_bins The index data entries (with path, name, map_id/hash)
 * @param array $bin_files The raw bin file info entries (with zip_path, entry_name, relative)
 * @param string $cache_dir Directory to write JSON files to
 * @param array $file_keys Map of bin index => file key (map_id string or hash)
 * @return int[] Array of bin indices that failed conversion
 */
function convert_bins_to_json($map_bins, $bin_files, $cache_dir, $file_keys)
{
  $conversion_errors = [];
  $bins_by_zip = [];
  foreach ($map_bins as $bin_idx => $entry) {
    $zip_path = $bin_files[$bin_idx]['zip_path'];
    $file_key = $file_keys[$bin_idx];
    $bins_by_zip[$zip_path][] = ['bin_idx' => $bin_idx, 'file_key' => $file_key];
  }

  foreach ($bins_by_zip as $zip_path => $entries) {
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
      foreach ($entries as $e)
        $conversion_errors[] = $e['bin_idx'];
      continue;
    }
    foreach ($entries as $e) {
      $bin_idx = $e['bin_idx'];
      $file_key = $e['file_key'];
      $bin_data = $zip->getFromName($bin_files[$bin_idx]['entry_name']);
      if ($bin_data === false) {
        $conversion_errors[] = $bin_idx;
        continue;
      }
      $json_result = post_bin_to_json($bin_data);
      if ($json_result !== false) {
        // Inject BinPath attribute into the root CelesteMap element
        $parsed = json_decode($json_result, true);
        if ($parsed !== null && isset($parsed['attributes'])) {
          $parsed['attributes']['BinPath'] = $bin_files[$bin_idx]['relative'];
          $json_result = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        file_put_contents("{$cache_dir}/{$file_key}.json", $json_result);
      } else {
        $conversion_errors[] = $bin_idx;
      }
    }
    $zip->close();
  }

  return $conversion_errors;
}
#endregion

#region Delete Old Indexed Files
/**
 * Deletes previously indexed .json data files from the cache directory.
 * Reads the existing index.json and removes all files referenced by map_id or hash.
 *
 * @param string $cache_dir
 * @return void
 */
function delete_old_indexed_files($cache_dir)
{
  if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
    return;
  }

  $existing_index_path = "{$cache_dir}/index.json";
  if (!file_exists($existing_index_path)) {
    return;
  }

  $existing_index = json_decode(file_get_contents($existing_index_path), true);
  $existing_bins = $existing_index['data'] ?? [];
  if (!is_array($existing_bins)) {
    return;
  }

  foreach ($existing_bins as $entry) {
    if (isset($entry['path']) && !isset($entry['conversion_error'])) {
      $hash = substr(md5($entry['path']), 0, 12);
      $old_file = "{$cache_dir}/{$hash}.json";
      if (file_exists($old_file)) {
        unlink($old_file);
      }
    }
    // Clean up legacy {map_id}.json files from pre-migration index entries
    if (isset($entry['map_id'])) {
      $old_file = "{$cache_dir}/{$entry['map_id']}.json";
      if (file_exists($old_file)) {
        unlink($old_file);
      }
    }
  }
}
#endregion

#region Write Index JSON
/**
 * Builds and saves the index.json file with metadata wrapper.
 *
 * @param string $cache_dir
 * @param array $map_bins
 * @param int[] $conversion_errors
 * @return void
 */
function write_index_json($cache_dir, $map_bins, $conversion_errors)
{
  $index = CampaignDataIndex::create_from_processing($cache_dir, $map_bins, $conversion_errors);
  $index->save();
}
#endregion

#region Copy Directory
/**
 * Recursively copies the contents of one directory to another.
 *
 * @param string $src Source directory
 * @param string $dst Destination directory (created if missing)
 * @return void
 */
function copy_directory_recursive($src, $dst)
{
  if (!is_dir($src))
    return;
  if (!is_dir($dst))
    mkdir($dst, 0755, true);

  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($iterator as $item) {
    $target = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
    if ($item->isDir()) {
      if (!is_dir($target))
        mkdir($target, 0755, true);
    } else {
      copy($item->getPathname(), $target);
    }
  }
}
#endregion

#region Helper Functions
/**
 * Writes an error index.json for a processing run that failed.
 * Ensures the cache directory exists and writes a minimal index with status=error.
 * @param string $cache_dir
 * @param string $message Error message
 */
function write_error_index($cache_dir, $message)
{
  $index = CampaignDataIndex::create_error($cache_dir, $message);
  $index->save();
}

/**
 * Downloads a file from a URL with support for large files and redirects.
 * @param string $url
 * @param string $dest Local file path to save to
 * @return bool
 */
function download_large_file($url, $dest)
{
  $fp = fopen($dest, 'wb');
  if ($fp === false)
    return false;

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_FILE, $fp);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
  curl_setopt($ch, CURLOPT_TIMEOUT, 600);
  curl_setopt($ch, CURLOPT_FAILONERROR, true);
  curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36');

  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Expect:']);
  curl_setopt($ch, CURLOPT_FORBID_REUSE, true);

  $success = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if (!$success) {
    log_error("download_large_file failed for {$url}: " . curl_error($ch) . " (HTTP {$http_code})", "download");
  }
  curl_close($ch);
  fclose($fp);

  if (!$success || $http_code >= 400) {
    unlink($dest);
    return false;
  }

  return true;
}

/**
 * Extracts a ZIP archive to the given directory.
 * @param string $archive_path
 * @param string $dest_dir
 * @return bool
 */
function extract_zip($archive_path, $dest_dir)
{
  $zip = new ZipArchive();
  $result = $zip->open($archive_path);
  if ($result !== true) {
    return false;
  }
  $zip->extractTo($dest_dir);
  $zip->close();
  return true;
}

/**
 * Recursively finds all files with the given extension in a directory.
 * @param string $dir
 * @param string $extension File extension without dot (e.g. 'bin')
 * @return string[] Sorted absolute paths
 */
function find_files_by_extension_recursive($dir, $extension)
{
  $results = [];
  if (!is_dir($dir))
    return $results;

  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
  );

  foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === strtolower($extension)) {
      $results[] = $file->getPathname();
    }
  }

  sort($results);
  return $results;
}

/**
 * Recursively deletes a directory and all its contents.
 * @param string $dir
 * @return void
 */
function delete_directory_recursive($dir)
{
  if (!is_dir($dir))
    return;

  $items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );

  foreach ($items as $item) {
    if ($item->isDir()) {
      rmdir($item->getPathname());
    } else {
      unlink($item->getPathname());
    }
  }

  rmdir($dir);
}

/**
 * Parses the English.txt dialog content and returns a lowercase-key => value map.
 * Keys are lowercased for case-insensitive matching.
 * Values are the map/chapter display names (single-line only).
 * @param string $content The English.txt file content as a string
 * @return array<string, string> Associative array of lowercase dialog key => display name
 */
function parse_english_txt($content)
{
  if ($content === false || $content === '')
    return [];

  $lines = preg_split('/\r?\n/', $content);
  $dialog_map = [];

  for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];

    // Skip empty lines and comments
    if (trim($line) === '' || str_starts_with(trim($line), '#'))
      continue;

    // Look for key=value on this line
    $eq_pos = strpos($line, '=');
    if ($eq_pos === false)
      continue;

    $key = strtolower(trim(substr($line, 0, $eq_pos)));
    $value = trim(substr($line, $eq_pos + 1));

    // If value is empty, check the next line (value may be on the next line)
    if ($value === '' && $i + 1 < count($lines)) {
      $next_line = $lines[$i + 1];
      // If the next line contains '=', it's a new key — value is genuinely empty
      if (strpos($next_line, '=') === false) {
        $value = trim($next_line);
      }
    }

    if ($value !== '') {
      $dialog_map[$key] = $value;
    }
  }

  return $dialog_map;
}

/**
 * Converts a .bin file path (relative to Maps/) into a dialog key.
 * Removes the .bin extension, replaces '/' and '-' with '_', lowercases.
 * Example: "MoonRuins/1-moonruins.bin" => "moonruins_1_moonruins"
 * @param string $relative_path Path relative to the Maps folder
 * @return string Lowercase dialog key
 */
function path_to_dialog_key($relative_path)
{
  $key = preg_replace('/\.bin$/i', '', $relative_path);
  $key = str_replace(['/', '-', ' '], '_', $key);
  return strtolower($key);
}

/**
 * POSTs raw .bin data to maddie480's bin-to-json conversion service.
 * @param string $bin_data Raw binary content of the .bin file
 * @return string|false JSON response string, or false on failure
 */
function post_bin_to_json($bin_data)
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, 'https://maddie480.ovh/celeste/bin-to-json');
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $bin_data);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream']);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
  curl_setopt($ch, CURLOPT_TIMEOUT, 120);
  curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36');

  $response = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($response === false || $http_code >= 400) {
    return false;
  }

  return $response;
}

/**
 * Cleans up the temp folder by deleting the oldest subdirectories
 * until the total size is below the given limit.
 * @param string $base_dir The temp/campaign_data base directory
 * @param int $max_bytes Maximum allowed total size in bytes
 * @return void
 */
function cleanup_temp_folder($base_dir, $max_bytes)
{
  if (!is_dir($base_dir))
    return;

  // Collect subdirectories with their total size and modification time
  $folders = [];
  $total_size = 0;

  foreach (scandir($base_dir) as $entry) {
    if ($entry === '.' || $entry === '..')
      continue;
    $path = "{$base_dir}/{$entry}";
    if (!is_dir($path))
      continue;

    $size = get_directory_size($path);
    $mtime = filemtime($path);
    $folders[] = ['path' => $path, 'size' => $size, 'mtime' => $mtime];
    $total_size += $size;
  }

  if ($total_size <= $max_bytes)
    return;

  // Sort by modification time ascending (oldest first)
  usort($folders, fn($a, $b) => $a['mtime'] <=> $b['mtime']);

  foreach ($folders as $folder) {
    if ($total_size <= $max_bytes)
      break;
    $total_size -= $folder['size'];
    delete_directory_recursive($folder['path']);
  }
}

/**
 * Calculates the total size of all files in a directory recursively.
 * @param string $dir
 * @return int Total size in bytes
 */
function get_directory_size($dir)
{
  $size = 0;
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
  );
  foreach ($iterator as $file) {
    if ($file->isFile()) {
      $size += $file->getSize();
    }
  }
  return $size;
}

/**
 * Deletes temporary cached data for a GameBanana item.
 * @param string $dir_key Directory key from gamebanana_dir_key()
 * @return void
 */
function delete_temp_cache($dir_key)
{
  $temp_cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data_temp/{$dir_key}";
  if (is_dir($temp_cache_dir)) {
    delete_directory_recursive($temp_cache_dir);
  }
}
#endregion

#region Process Campaign
/**
 * Processes the data for a single campaign.
 * Downloads mod files from GameBanana, extracts archives, finds English.txt and .bin map files,
 * caches them, and converts .bin files to JSON via maddie480's bin-to-json service.
 * @param resource $DB
 * @param int $id Campaign ID
 * @param bool $regenerate If true, forces re-download of ZIP files even if they already exist
 * @return array Result with 'id', 'success', and optionally 'error' or processing details
 */
function process_campaign($DB, $id, $regenerate = false)
{
  global $MAX_TEMP_MODS_FOLDER_SIZE;

  /** @var Campaign|null $campaign */
  $campaign = Campaign::get_by_id($DB, $id, 0, false);
  if ($campaign === null) {
    return ['id' => $id, 'success' => false, 'error' => "Campaign not found"];
  }

  $gb_info = $campaign->get_gamebanana_info();
  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data/{$id}";
  if ($gb_info === null) {
    $error = "No valid GameBanana URL";
    write_error_index($cache_dir, $error);
    return ['id' => $id, 'success' => false, 'error' => $error];
  }

  $mod_id = $gb_info['id'];
  $item_type = $gb_info['item_type'];
  $dir_key = gamebanana_dir_key($gb_info['category'], $mod_id);

  // Temp dir uses GameBanana dir key so it's shared with process-gb-campaign.php
  $temp_dir = GB_ROOT_LOCAL . "/temp/campaign_data/{$dir_key}";

  // Download and scan mod files (shared pipeline)
  $scan = download_and_scan_mod($mod_id, $item_type, $temp_dir, $cache_dir, $regenerate);
  if (!$scan['success']) {
    return ['id' => $id, 'success' => false, 'error' => $scan['error']];
  }

  $bin_files = $scan['bin_files'];
  $map_bins = $scan['map_bins'];

  // Fetch all maps in the campaign from the database
  $campaign->fetch_maps($DB);
  $unmatched_maps = [];

  // Match bin files to database map IDs.
  // Priority 1: map.bin column in DB (explicit bin path stored on map)
  // Priority 2: name matching via English.txt dialog names
  $matched_bins = []; // index => map_id

  if ($campaign->maps !== null) {
    // Build bin path => map ID lookup from maps that already have a bin assigned
    $path_mapping = [];
    $campaign_map_ids = [];
    foreach ($campaign->maps as $map) {
      $campaign_map_ids[$map->id] = true;
      if ($map->bin !== null) {
        $path_mapping[$map->bin] = $map->id;
      }
    }

    // First pass: match by existing map.bin column in DB
    foreach ($map_bins as $i => $entry) {
      $rel_path = $entry['path'];
      if (isset($path_mapping[$rel_path])) {
        $matched_bins[$i] = $path_mapping[$rel_path];
      }
    }

    // Collect already-matched map IDs to avoid double-matching
    $used_map_ids = array_flip($matched_bins);

    // Build name lookup for remaining unmatched entries
    $name_to_index = [];
    foreach ($map_bins as $i => $entry) {
      if (!isset($matched_bins[$i]) && isset($entry['name'])) {
        $name_to_index[strtolower(trim($entry['name']))] = $i;
      }
    }

    // Second pass: match by name for maps not yet matched via DB bin column
    // Sort so non-archived maps are matched first (preferred over archived duplicates)
    $maps_sorted = $campaign->maps;
    usort($maps_sorted, fn($a, $b) => $a->is_archived <=> $b->is_archived);
    foreach ($maps_sorted as $map) {
      if (isset($used_map_ids[$map->id])) {
        continue;
      }
      $map_name_lower = strtolower(trim($map->name));
      if (isset($name_to_index[$map_name_lower])) {
        $idx = $name_to_index[$map_name_lower];
        $matched_bins[$idx] = $map->id;
        $used_map_ids[$map->id] = true;
      } else {
        $unmatched_maps[] = ['id' => $map->id, 'name' => $map->name];
      }
    }

    // Write bin paths to database for all matched maps
    foreach ($matched_bins as $bin_idx => $map_id) {
      $bin_path = $map_bins[$bin_idx]['path'];
      pg_query_params_or_die(
        $DB,
        "UPDATE map SET bin = $1 WHERE id = $2",
        [$bin_path, $map_id],
        "Failed to update map bin path"
      );
    }
  }

  $matched_count = count($matched_bins);

  // All file keys are hashes based on bin path
  $file_keys = [];
  foreach ($map_bins as $bin_idx => $entry) {
    $file_keys[$bin_idx] = substr(md5($entry['path']), 0, 12);
  }

  // Delete previously indexed files before writing new ones
  delete_old_indexed_files($cache_dir);

  // Convert ALL .bin files to JSON and write to cache
  $conversion_errors = convert_bins_to_json($map_bins, $bin_files, $cache_dir, $file_keys);

  // Write index.json with metadata (entries only contain path and name)
  write_index_json($cache_dir, $map_bins, $conversion_errors);

  // Clean up temp folder if total size exceeds the limit
  cleanup_temp_folder(GB_ROOT_LOCAL . '/temp/campaign_data', $MAX_TEMP_MODS_FOLDER_SIZE);

  $result = [
    'id' => $id,
    'success' => true,
    'name' => $campaign->name,
    'mod_id' => $mod_id,
    'english_txt_found' => $scan['english_txt_found'],
    'bin_count' => count($bin_files),
    'matched_maps' => $matched_count,
  ];

  if (count($conversion_errors) > 0) {
    $result['conversion_errors'] = $conversion_errors;
  }

  // Collect unmatched bins (bins not matched to any database map)
  $unmatched_bins = [];
  foreach ($map_bins as $i => $entry) {
    if (!isset($matched_bins[$i])) {
      $unmatched_bins[] = $entry;
    }
  }
  if (count($unmatched_bins) > 0) {
    $result['unmatched_bins'] = $unmatched_bins;
  }
  if (count($unmatched_maps) > 0) {
    $result['unmatched_maps'] = $unmatched_maps;
  }

  return $result;
}
#endregion
