<?php
// Copyright by AcmaTvirus

namespace Acma\AutoSaveExternalImages\Controllers;

use Acma\AutoSaveExternalImages\Services\UpdateService;

/**
 * Class UpdateController
 */
class UpdateController
{
    private $update_service;

    public function __construct()
    {
        $this->update_service = new UpdateService();
    }

    public function register()
    {
        add_filter('site_transient_update_plugins', [$this->update_service, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
    }

    public function plugin_info($res, $action, $args)
    {
        if ($action !== 'plugin_information') return $res;
        if ($args->slug !== 'auto-save-external-images') return $res;

        return $res;
    }
}
