<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

$account = get_user_data();
check_role($account, $HELPER);

$id = $_REQUEST['id'] ?? null;
if ($id === null) {
  die_json(400, "Missing 'id' parameter");
}

$regenerate = ($_REQUEST['regenerate'] ?? 'false') === 'true';

$id_list = array_map('trim', explode(',', $id));
$results = [];

foreach ($id_list as $id) {
  if (!is_numeric($id)) {
    $results[] = ['id' => $id, 'success' => false, 'error' => "Invalid campaign ID"];
    continue;
  }
  $results[] = process_campaign($DB, intval($id), $regenerate);
}

api_write($results);


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

  $mod_id = $campaign->get_gamebanana_mod_id();
  if ($mod_id === null) {
    return ['id' => $id, 'success' => false, 'error' => "No valid GameBanana URL (url: " . ($campaign->url ?? 'NULL') . ")"];
  }

  $temp_dir = GB_ROOT_LOCAL . "/temp/campaign_data/{$id}";
  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data/{$id}";

  // If regenerate is requested, wipe the temp dir (downloaded ZIPs) to force re-download
  if ($regenerate && is_dir($temp_dir))
    delete_directory_recursive($temp_dir);
  if (!is_dir($temp_dir))
    mkdir($temp_dir, 0755, true);

  // Fetch file list from GameBanana API
  $api_url = "https://gamebanana.com/apiv11/Mod/{$mod_id}?_csvProperties=_aFiles";
  $api_response = fetch_data($api_url);
  if ($api_response === false || $api_response === '') {
    delete_directory_recursive($temp_dir);
    return ['id' => $id, 'success' => false, 'error' => "Failed to fetch mod info from GameBanana"];
  }

  $mod_data = json_decode($api_response, true);
  if (!isset($mod_data['_aFiles']) || count($mod_data['_aFiles']) === 0) {
    delete_directory_recursive($temp_dir);
    return ['id' => $id, 'success' => false, 'error' => "No files found on GameBanana for mod {$mod_id}"];
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
      return ['id' => $id, 'success' => false, 'error' => "Failed to download: {$filename}"];
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

  // Read mapping.json from cache dir before wiping (maps bin paths to database map IDs)
  $path_mapping = [];
  $mapping_path = "{$cache_dir}/mapping.json";
  if (file_exists($mapping_path)) {
    $mapping_content = file_get_contents($mapping_path);
    if ($mapping_content !== false) {
      $path_mapping = json_decode($mapping_content, true) ?? [];
    }
  }

  // Fetch all maps in the campaign from the database
  $campaign->fetch_maps($DB);
  $unmatched_maps = [];

  // Match bin files to database map IDs.
  // Priority 1: mapping.json (explicit bin path => map ID mapping)
  // Priority 2: name matching via English.txt dialog names
  $matched_bins = []; // index => map_id

  if ($campaign->maps !== null) {
    // Build a set of map IDs in this campaign for validation
    $campaign_map_ids = [];
    foreach ($campaign->maps as $map) {
      $campaign_map_ids[$map->id] = true;
    }

    // First pass: apply mapping.json matches
    foreach ($map_bins as $i => $entry) {
      $rel_path = $entry['path'];
      if (isset($path_mapping[$rel_path]) && isset($campaign_map_ids[$path_mapping[$rel_path]])) {
        $matched_bins[$i] = intval($path_mapping[$rel_path]);
        $map_bins[$i]['map_id'] = $matched_bins[$i];
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

    // Second pass: match by name for maps not yet matched via mapping.json
    foreach ($campaign->maps as $map) {
      if (isset($used_map_ids[$map->id])) {
        continue;
      }
      $map_name_lower = strtolower(trim($map->name));
      if (isset($name_to_index[$map_name_lower])) {
        $idx = $name_to_index[$map_name_lower];
        $matched_bins[$idx] = $map->id;
        $map_bins[$idx]['map_id'] = $map->id;
      } else {
        $unmatched_maps[] = ['id' => $map->id, 'name' => $map->name];
      }
    }
  }

  $matched_count = count($matched_bins);

  // Generate hashes for unmatched bins and store in index
  foreach ($map_bins as $i => &$entry) {
    if (!isset($matched_bins[$i])) {
      $entry['hash'] = substr(md5($entry['path']), 0, 12);
    }
  }
  unset($entry);

  // Delete previously indexed files (matched by map_id or hash) before writing new ones.
  // This preserves manually uploaded files for archived maps that are not in the index.
  if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
  } else {
    $existing_index_path = "{$cache_dir}/index.json";
    if (file_exists($existing_index_path)) {
      $existing_index = json_decode(file_get_contents($existing_index_path), true);
      if (is_array($existing_index)) {
        foreach ($existing_index as $entry) {
          $old_file = null;
          if (isset($entry['map_id'])) {
            $old_file = "{$cache_dir}/{$entry['map_id']}.json";
          } elseif (isset($entry['hash'])) {
            $old_file = "{$cache_dir}/{$entry['hash']}.json";
          }
          if ($old_file !== null && file_exists($old_file)) {
            unlink($old_file);
          }
        }
      }
    }
  }

  // Convert ALL .bin files to JSON via maddie480's service and write immediately.
  // Matched bins use map_id as filename, unmatched bins use their hash.
  // Group by zip file to avoid reopening the same archive for each entry.
  $conversion_errors = [];
  $bins_by_zip = [];
  foreach ($map_bins as $bin_idx => $entry) {
    $zip_path = $bin_files[$bin_idx]['zip_path'];
    if (isset($matched_bins[$bin_idx])) {
      $file_key = strval($matched_bins[$bin_idx]);
    } else {
      $file_key = $entry['hash'];
    }
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

  // For bins with conversion errors, replace hash with conversion_error flag in index
  $conversion_error_set = array_flip($conversion_errors);
  foreach ($map_bins as $i => &$entry) {
    if (isset($conversion_error_set[$i]) && isset($entry['hash'])) {
      unset($entry['hash']);
      $entry['conversion_error'] = true;
    }
  }
  unset($entry);

  // Save index.json with map_id info
  file_put_contents(
    "{$cache_dir}/index.json",
    json_encode($map_bins, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
  );

  // Clean up temp folder if total size exceeds the limit
  cleanup_temp_folder(GB_ROOT_LOCAL . '/temp/campaign_data', $MAX_TEMP_MODS_FOLDER_SIZE);

  $result = [
    'id' => $id,
    'success' => true,
    'name' => $campaign->name,
    'mod_id' => $mod_id,
    'english_txt_found' => $english_txt_info !== null,
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

#region Helper Functions
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

  $success = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
  // Remove .bin extension
  $key = preg_replace('/\.bin$/i', '', $relative_path);
  // Replace / and - with _
  $key = str_replace(['/', '-'], '_', $key);
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
#endregion