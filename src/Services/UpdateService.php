<?php
// Copyright by AcmaTvirus

namespace Acma\AutoSaveExternalImages\Services;

/**
 * Class UpdateService
 */
class UpdateService
{
    private $github_repo = 'AcmaTvirus/auto-save-external-images';
    private $plugin_slug = 'auto-save-external-images';
    private $plugin_file = 'auto-save-external-images.php';

    /**
     * Check for updates from GitHub
     */
    public function check_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->get_remote_data();
        if (!$remote) return $transient;

        $version = $this->get_plugin_version();

        if (version_compare($version, $remote->tag_name, '<')) {
            $obj = new \stdClass();
            $obj->slug = $this->plugin_slug;
            $obj->new_version = $remote->tag_name;
            $obj->url = $remote->html_url;
            $obj->package = $this->get_zip_url($remote);

            $transient->response[$this->plugin_slug . '/' . $this->plugin_file] = $obj;
        }

        return $transient;
    }

    private function get_remote_data()
    {
        $url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        $response = wp_remote_get($url);
        if (is_wp_error($response)) return false;
        return json_decode(wp_remote_retrieve_body($response));
    }

    private function get_plugin_version()
    {
        $path = plugin_dir_path(dirname(__DIR__, 1)) . $this->plugin_file;
        $data = get_plugin_data($path);
        return $data['Version'];
    }

    private function get_zip_url($remote)
    {
        foreach ($remote->assets as $asset) {
            if (strpos($asset->name, '.zip') !== false) {
                return $asset->browser_download_url;
            }
        }
        return $remote->zipball_url;
    }
}
