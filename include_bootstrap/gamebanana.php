<?php

define('GAMEBANANA_API_VERSION', 11);
define('GAMEBANANA_API_BASE_URL', 'https://gamebanana.com/apiv' . GAMEBANANA_API_VERSION);

/**
 * Builds a GameBanana API v11 URL for a specific item with selected properties.
 *
 * @param string $item_type 'Mod' or 'Wip'
 * @param int|string $item_id GameBanana item ID
 * @param string $csv_properties Comma-separated property names (e.g. '_aFiles', '_sName,_aSubmitter')
 * @return string Full API URL
 */
function gamebanana_api_url($item_type, $item_id, $csv_properties)
{
  return GAMEBANANA_API_BASE_URL . "/{$item_type}/{$item_id}?_csvProperties={$csv_properties}";
}
