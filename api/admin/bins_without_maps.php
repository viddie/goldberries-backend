<?php

require_once('../api_bootstrap.inc.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  die_json(405, 'Method Not Allowed');
}

header('Content-Type: text/html');

$campaign_query = "SELECT * FROM campaign ORDER BY name";
$campaign_result = pg_query_params_or_die($DB, $campaign_query);

$campaigns_with_orphan_bins = [];
$total_orphan_bins = 0;
$total_campaigns_checked = 0;
$total_campaigns_without_index = 0;

while ($row = pg_fetch_assoc($campaign_result)) {
  $campaign = new Campaign();
  $campaign->apply_db_data($row);

  $total_campaigns_checked++;

  $cache_dir = GB_ROOT_LOCAL . "/cache/campaign_data/{$campaign->id}";
  $index = CampaignDataIndex::load($cache_dir);

  if ($index === null) {
    $total_campaigns_without_index++;
    continue;
  }

  if (!$campaign->fetch_maps($DB, false, false, true, true, false)) {
    continue;
  }

  $used_bins = [];
  foreach ($campaign->maps ?? [] as $map) {
    if ($map->bin !== null && $map->bin !== '') {
      $used_bins[$map->bin] = true;
    }
  }

  $orphan_bins = [];
  foreach ($index->data as $entry) {
    if (!isset($entry['path'])) {
      continue;
    }

    $bin_path = $entry['path'];
    if (!isset($used_bins[$bin_path])) {
      $orphan_bins[] = $entry;
    }
  }

  if (count($orphan_bins) > 0) {
    $campaigns_with_orphan_bins[$campaign->id] = [
      'campaign' => $campaign,
      'bins' => $orphan_bins,
    ];
    $total_orphan_bins += count($orphan_bins);
  }
}

echo "<p><b>Found $total_orphan_bins bin file(s) without mapped map.bin across "
  . count($campaigns_with_orphan_bins)
  . " campaign(s).</b><br>";
echo "Checked $total_campaigns_checked campaign(s). Campaigns without index.json: $total_campaigns_without_index.</p>";

foreach ($campaigns_with_orphan_bins as $campaign_data) {
  $campaign = $campaign_data['campaign'];
  $bins = $campaign_data['bins'];

  echo "<h4>Campaign: <a href=\"" . $campaign->get_url() . "\">" . htmlspecialchars($campaign->get_name()) . "</a> (" . count($bins) . ")</h4>";
  echo "<ol>";
  foreach ($bins as $entry) {
    $path = htmlspecialchars($entry['path']);
    $name = isset($entry['name']) ? htmlspecialchars($entry['name']) : null;

    if ($name !== null && $name !== '') {
      echo "<li>$path - <b>$name</b></li>";
    } else {
      echo "<li>$path</li>";
    }
  }
  echo "</ol>";
}
