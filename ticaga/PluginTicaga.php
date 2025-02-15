<?php
/**
 * Ticaga ClientExec plugin
 *
 * @link https://ticaga.com/ Ticaga
 */

require_once 'modules/admin/models/SnapinPlugin.php';

class PluginTicaga extends SnapinPlugin
{
    public init()
    {
        /* 
         * Each snapin should have an init() function, 
         * which will call internal functions to determine what the snapin does. 
         */
    }

    public $listeners = [
        ["Client-Create", "Client-Update", "Client-PasswordChange"]
    ];

    /*
     * Client-Create
     * When a client is created on ClientExec, create the user on Ticaga.
     */
    public function Client-Create($e)
    {
        return false;
    }

    /*
     * Client-Update
     * When a client is updated on ClientExec, update the user on Ticaga.
     */
    public function Client-Update($e)
    {
        return false;
    }

    /*
     * Client-PasswordChange
     * When a client password is changed on ClientExec, send a reset password to the user on Ticaga.
     */
    public function Client-PasswordChange($e)
    {
        return false;
    }
}