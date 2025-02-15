<?php
/**
 * Ticaga ClientExec plugin
 *
 * @link https://ticaga.com/ Ticaga
 */

require_once 'modules/admin/models/SnapinPlugin.php';

class PluginTicaga extends SnapinPlugin
{
    public function getVariables()
{
    $variables = [
        'Plugin Name' => [
            'type' => 'hidden',
            'description' => 'Used by CE to show plugin',
            'value' => 'Ticaga'
        ]
    ];

    return $variables;
}
}