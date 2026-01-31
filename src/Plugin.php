<?php
// Copyright by AcmaTvirus

namespace Acma\AutoSaveExternalImages;

/**
 * Class Plugin
 * @package Acma\AutoSaveExternalImages
 */
class Plugin
{
    /**
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * @return Plugin
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Plugin constructor.
     */
    private function __construct()
    {
        // Services and controllers will be initialized here
    }

    /**
     * Run the plugin logic
     */
    public function run()
    {
        $update_controller = new \Acma\AutoSaveExternalImages\Controllers\UpdateController();
        $update_controller->register();

        add_action('plugins_loaded', [$this, 'init']);
        add_filter('plugin_action_links_' . plugin_basename(dirname(__DIR__, 1) . '/auto-save-external-images.php'), [$this, 'add_action_links']);
    }

    /**
     * Initialize hooks
     */
    public function init()
    {
        // Future refactoring
    }

    /**
     * Add Settings and Check Update links
     */
    public function add_action_links($links)
    {
        $settings_url = admin_url('options-general.php?page=auto-save-external-images');
        $update_url = wp_nonce_url(admin_url('update-core.php?force-check=1'), 'upgrade-core');

        array_unshift(
            $links,
            '<a href="' . $settings_url . '">Settings</a>',
            '<a href="' . $update_url . '">Check Update</a>'
        );

        return $links;
    }
}
