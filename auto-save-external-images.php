<?php
/**
 * Plugin Name: Auto Save External Images
 * Plugin URI:  #
 * Description: Tự động tải ảnh từ link ngoài về host khi lưu bài viết hoặc sản phẩm.
 * Version:     1.0.0
 * Author:      AcmaTvirus
 * Author URI:  #
 * License:     GPL2
 * Text Domain: auto-save-external-images
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Auto_Save_External_Images
{

    public function __construct()
    {
        add_action('save_post', array($this, 'process_images_on_save'), 10, 3);
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_asei_process_bulk', array($this, 'ajax_process_bulk'));
        add_action('wp_ajax_asei_scan_broken_links', array($this, 'ajax_scan_broken_links'));
        add_action('wp_ajax_asei_seo_marking', array($this, 'ajax_seo_marking'));
        add_action('wp_ajax_asei_check_content', array($this, 'ajax_check_content'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Media Library Enhancements
        add_filter('manage_media_columns', array($this, 'add_media_product_column'));
        add_action('manage_media_custom_column', array($this, 'render_media_product_column'), 10, 2);
        add_action('restrict_manage_posts', array($this, 'add_media_type_filter'));
        add_action('pre_get_posts', array($this, 'filter_media_by_parent_type'));
        
        // GitHub Update Checker
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_updates'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
    }

    public function add_media_product_column($columns)
    {
        $columns['asei_product'] = 'Sản phẩm/Bài viết';
        return $columns;
    }

    public function render_media_product_column($column_name, $id)
    {
        if ($column_name !== 'asei_product') return;
        $attachment = get_post($id);
        if ($attachment->post_parent) {
            $parent_title = get_the_title($attachment->post_parent);
            $parent_link = get_edit_post_link($attachment->post_parent);
            $post_type = get_post_type($attachment->post_parent);
            $type_label = ($post_type === 'product') ? '📦' : '📝';
            echo '<a href="' . $parent_link . '"><strong>' . $type_label . ' ' . $parent_title . '</strong></a>';
        } else {
            echo '<span style="color:#94a3b8; font-style:italic;">Chưa liên kết</span>';
        }
    }

    public function add_media_type_filter()
    {
        $screen = get_current_screen();
        if ('upload' !== $screen->id) return;

        $current = isset($_GET['asei_filter']) ? $_GET['asei_filter'] : '';
        ?>
        <select name="asei_filter">
            <option value=""><?php _e('Tất cả nguồn ảnh', 'asei'); ?></option>
            <option value="product" <?php selected($current, 'product'); ?>><?php _e('🖼️ Chỉ ảnh Sản phẩm', 'asei'); ?></option>
            <option value="post" <?php selected($current, 'post'); ?>><?php _e('📰 Chỉ ảnh Bài viết', 'asei'); ?></option>
            <option value="unattached" <?php selected($current, 'unattached'); ?>><?php _e('❓ Ảnh chưa liên kết', 'asei'); ?></option>
        </select>
        <?php
    }

    // Filter logic for Media Library
    public function filter_media_by_parent_type($query)
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'attachment') return;

        $filter = isset($_GET['asei_filter']) ? $_GET['asei_filter'] : '';
        if (empty($filter)) return;

        global $wpdb;
        if ($filter === 'unattached') {
            $query->set('post_parent', 0);
        } else {
            $post_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE post_type = %s",
                $filter
            ));
            if (!empty($post_ids)) {
                $query->set('post_parent__in', $post_ids);
            } else {
                $query->set('post_parent__in', array(0)); // Show nothing
            }
        }
    }

    public function enqueue_admin_assets($hook)
    {
        if ('tools_page_auto-save-external-images' !== $hook) {
            return;
        }
        // Enqueue Google Fonts
        wp_enqueue_style('asei-google-fonts', 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap', array(), null);
    }

    public function add_admin_menu()
    {
        add_management_page(
            'Auto Save External Images',
            'Savelink Images',
            'manage_options',
            'auto-save-external-images',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page()
    {
        ?>
        <style>
            .asei-page-wrapper {
                --p-primary: #6366f1;
                --p-primary-dark: #4f46e5;
                --p-success: #10b981;
                --p-warning: #f59e0b;
                --p-danger: #ef4444;
                --p-bg: #fdfdff;
                --p-card-bg: #ffffff;
                --p-text: #0f172a;
                --p-text-light: #64748b;
                --p-border: #f1f5f9;

                font-family: 'Plus Jakarta Sans', sans-serif !important;
                margin: 20px 20px 0 0;
                color: var(--p-text);
            }

            .asei-main-container {
                max-width: 1000px;
                margin: 0 auto;
            }

            .asei-dashboard {
                background: var(--p-card-bg);
                border-radius: 32px;
                box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.06), 0 0 1px rgba(0, 0, 0, 0.1);
                border: 1px solid var(--p-border);
                overflow: hidden;
                position: relative;
            }

            .asei-dashboard::before {
                content: "";
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 8px;
                background: linear-gradient(90deg, #6366f1, #a855f7, #ec4899);
            }

            .asei-inner {
                padding: 40px 60px 60px;
            }

            .asei-header-section {
                text-align: center;
                margin-bottom: 40px;
            }

            .asei-header-section h1 {
                font-size: 48px !important;
                font-weight: 800 !important;
                margin-bottom: 12px !important;
                letter-spacing: -0.04em !important;
                background: linear-gradient(135deg, #1e293b 0%, #475569 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                line-height: 1.1 !important;
            }

            /* Tabs */
            .asei-tabs {
                display: flex;
                justify-content: center;
                gap: 12px;
                margin-bottom: 40px;
                background: #f1f5f9;
                padding: 6px;
                border-radius: 16px;
                width: fit-content;
                margin-left: auto;
                margin-right: auto;
            }

            .asei-tab-btn {
                padding: 12px 24px;
                border-radius: 12px;
                font-weight: 700;
                font-size: 15px;
                cursor: pointer;
                transition: all 0.3s ease;
                border: none;
                background: transparent;
                color: var(--p-text-light);
            }

            .asei-tab-btn.active {
                background: white;
                color: var(--p-primary);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            }

            .asei-tab-content {
                display: none;
            }

            .asei-tab-content.active {
                display: block;
            }

            /* UI States */
            .asei-view-setup {
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding: 20px 0;
            }

            .asei-view-running {
                display: none;
            }

            .asei-illus {
                margin-bottom: 30px;
                width: 100px;
                height: 100px;
                background: #f5f3ff;
                border-radius: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--p-primary);
                transform: rotate(-5deg);
                box-shadow: 0 15px 30px -5px rgba(99, 102, 241, 0.2);
            }

            .asei-start-button {
                background: var(--p-primary) !important;
                color: white !important;
                border: none !important;
                padding: 18px 40px !important;
                font-size: 17px !important;
                font-weight: 700 !important;
                border-radius: 18px !important;
                cursor: pointer !important;
                transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
                box-shadow: 0 15px 25px -5px rgba(99, 102, 241, 0.3) !important;
                display: flex !important;
                align-items: center !important;
                gap: 12px !important;
            }

            .asei-start-button:hover {
                transform: translateY(-4px) scale(1.02) !important;
                box-shadow: 0 20px 30px -10px rgba(99, 102, 241, 0.4) !important;
                background: var(--p-primary-dark) !important;
            }

            /* Stats */
            .asei-stats-container {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
                margin-bottom: 40px;
            }

            .asei-stat-card {
                padding: 24px;
                background: #f8fafc;
                border: 1px solid var(--p-border);
                border-radius: 20px;
                text-align: center;
            }

            .asei-stat-card .label {
                display: block;
                font-size: 12px;
                font-weight: 700;
                color: var(--p-text-light);
                text-transform: uppercase;
                letter-spacing: 0.1em;
                margin-bottom: 8px;
            }

            .asei-stat-card .value {
                font-size: 32px;
                font-weight: 800;
                color: var(--p-text);
            }

            /* Progress Area */
            .asei-progress-box {
                background: #f8fafc;
                padding: 30px;
                border-radius: 20px;
                margin-bottom: 30px;
            }

            .asei-progress-track {
                height: 16px;
                background: #e2e8f0;
                border-radius: 8px;
                overflow: hidden;
                margin-bottom: 15px;
            }

            .asei-progress-bar {
                height: 100%;
                width: 0%;
                background: linear-gradient(90deg, #6366f1 0%, #a855f7 100%);
                transition: width 0.8s ease;
            }

            .asei-status-labels {
                display: flex;
                justify-content: space-between;
                font-weight: 700;
                font-size: 14px;
            }

            /* Broken Links Table */
            .asei-broken-table-wrapper {
                margin-top: 30px;
                background: #ffffff;
                border-radius: 20px;
                border: 1px solid var(--p-border);
                overflow: hidden;
            }

            .asei-broken-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
            }

            .asei-broken-table th {
                background: #f8fafc;
                padding: 16px;
                font-weight: 700;
                font-size: 13px;
                color: var(--p-text-light);
                text-transform: uppercase;
            }

            .asei-broken-table td {
                padding: 16px;
                border-top: 1px solid var(--p-border);
                font-size: 14px;
            }

            .asei-badge-error {
                background: #fee2e2;
                color: #ef4444;
                padding: 4px 10px;
                border-radius: 6px;
                font-weight: 700;
                font-size: 12px;
            }

            .asei-badge-fixed {
                background: #e0f2fe;
                color: #0284c7;
                padding: 4px 10px;
                border-radius: 6px;
                font-weight: 700;
                font-size: 12px;
            }

            /* Console Log */
            .asei-console-wrapper {
                background: #0f172a;
                border-radius: 20px;
                padding: 24px;
                box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
            }

            #asei-log-entries, #asei-scanner-log, #asei-seo-log {
                height: 250px;
                overflow-y: auto;
                font-family: 'JetBrains Mono', monospace;
                font-size: 13px;
                color: #cbd5e1;
            }

            .con-line {
                margin-bottom: 8px;
                display: flex;
                gap: 12px;
            }

            .con-badge {
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
            }

            .bg-success { background: rgba(16, 185, 129, 0.2); color: #10b981; }
            .bg-info { background: rgba(99, 102, 241, 0.2); color: #6366f1; }
            .bg-error { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        </style>

        <div class="asei-page-wrapper">
            <div class="asei-main-container">
                <div class="asei-dashboard">
                    <div class="asei-inner">
                        <div class="asei-header-section">
                            <h1>Savelink Images Pro</h1>
                            <p>Tối ưu hóa hình ảnh cho website của bạn</p>
                        </div>

                        <div class="asei-tabs">
                            <button class="asei-tab-btn active" data-tab="tab-savelink">📦 Tải ảnh link ngoài</button>
                            <button class="asei-tab-btn" data-tab="tab-scanner">🔍 Quét link ảnh lỗi</button>
                            <button class="asei-tab-btn" data-tab="tab-seo">🏷️ Đánh dấu SEO</button>
                            <button class="asei-tab-btn" data-tab="tab-content-check">📝 Kiểm tra Nội dung</button>
                        </div>

                        <!-- TAB: SAVELINK -->
                        <div id="tab-savelink" class="asei-tab-content active">
                            <div id="asei-view-setup" class="asei-view-setup">
                                <div class="asei-illus">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                                        <circle cx="8.5" cy="8.5" r="1.5" />
                                        <polyline points="21 15 16 10 5 21" />
                                    </svg>
                                </div>
                                <p style="font-size: 18px; font-weight: 600; margin-bottom: 30px; max-width: 500px;">
                                    Tự động tải tất cả hình ảnh từ link ngoài về host nội bộ chỉ với một cú click.
                                </p>
                                <button id="asei-run-btn" class="asei-start-button">
                                    <span>Kích hoạt hệ thống</span>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                        <line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" />
                                    </svg>
                                </button>
                            </div>

                            <div id="asei-view-running" class="asei-view-running">
                                <div class="asei-stats-container">
                                    <div class="asei-stat-card">
                                        <span class="label">Tổng kho</span>
                                        <span id="val-total" class="value">0</span>
                                    </div>
                                    <div class="asei-stat-card">
                                        <span class="label">Đang quét</span>
                                        <span id="val-scanned" class="value">0</span>
                                    </div>
                                    <div class="asei-stat-card" style="background: #ecfdf5;">
                                        <span class="label" style="color: #059669;">Thành công</span>
                                        <span id="val-updated" class="value" style="color: #047857;">0</span>
                                    </div>
                                </div>
                                <div class="asei-progress-box">
                                    <div class="asei-progress-track"><div id="asei-fill" class="asei-progress-bar"></div></div>
                                    <div class="asei-status-labels">
                                        <span id="asei-task-text">Sẵn sàng...</span>
                                        <span id="asei-task-pct">0%</span>
                                    </div>
                                </div>
                                <div class="asei-console-wrapper">
                                    <div id="asei-log-entries"></div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: SCANNER -->
                        <div id="tab-scanner" class="asei-tab-content">
                            <div id="asei-scanner-setup" class="asei-view-setup">
                                <div class="asei-illus" style="background: #fff7ed; color: #f97316;">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" />
                                    </svg>
                                </div>
                                <p style="font-size: 18px; font-weight: 600; margin-bottom: 30px; max-width: 500px;">
                                    Tìm kiếm và liệt kê các hình ảnh bị lỗi (404, Timeout...) trong bài viết và sản phẩm.
                                </p>
                                <button id="asei-scan-btn" class="asei-start-button" style="background: #f97316 !important; box-shadow: 0 15px 25px -5px rgba(249, 115, 22, 0.3) !important;">
                                    <span>Bắt đầu quét lỗi</span>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                        <line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" />
                                    </svg>
                                </button>
                            </div>

                            <div id="asei-scanner-running" class="asei-view-running">
                                <div class="asei-stats-container">
                                    <div class="asei-stat-card">
                                        <span class="label">Tổng bài</span>
                                        <span id="scan-total" class="value">0</span>
                                    </div>
                                    <div class="asei-stat-card">
                                        <span class="label">Đã kiểm tra</span>
                                        <span id="scan-processed" class="value">0</span>
                                    </div>
                                    <div class="asei-stat-card" style="background: #f0f9ff; border-color: #bae6fd;">
                                        <span class="label" style="color: #0284c7;">Đã sửa</span>
                                        <span id="scan-fixed" class="value" style="color: #0369a1;">0</span>
                                    </div>
                                    <div class="asei-stat-card" style="background: #fef2f2;">
                                        <span class="label" style="color: #dc2626;">Ảnh lỗi</span>
                                        <span id="scan-broken" class="value" style="color: #b91c1c;">0</span>
                                    </div>
                                </div>
                                <div class="asei-progress-box">
                                    <div class="asei-progress-track"><div id="scan-fill" class="asei-progress-bar" style="background: linear-gradient(90deg, #f97316, #ef4444);"></div></div>
                                    <div class="asei-status-labels">
                                        <span id="scan-task-text">Đang chuẩn bị...</span>
                                        <span id="scan-task-pct">0%</span>
                                    </div>
                                </div>
                                
                                <div class="asei-broken-table-wrapper" id="broken-links-container" style="display:none;">
                                    <table class="asei-broken-table">
                                        <thead>
                                            <tr>
                                                <th>Bài viết</th>
                                                <th>Link ảnh</th>
                                                <th>Lỗi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="broken-links-list"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: SEO MARKING -->
                        <div id="tab-seo" class="asei-tab-content">
                            <div id="asei-seo-setup" class="asei-view-setup">
                                <div class="asei-illus" style="background: #ecfeff; color: #0891b2;">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
                                        <line x1="7" y1="7" x2="7.01" y2="7" />
                                    </svg>
                                </div>
                                <p style="font-size: 18px; font-weight: 600; margin-bottom: 30px; max-width: 500px;">
                                    Tự động đặt ALT, Tiêu đề cho tất cả hình ảnh trong sản phẩm theo tên sản phẩm tương ứng.
                                </p>
                                <button id="asei-seo-btn" class="asei-start-button" style="background: #0891b2 !important; box-shadow: 0 15px 25px -5px rgba(8, 145, 178, 0.3) !important;">
                                    <span>Bắt đầu đánh dấu SEO</span>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                        <line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" />
                                    </svg>
                                </button>
                            </div>

                            <div id="asei-seo-running" class="asei-view-running">
                                <div class="asei-stats-container">
                                    <div class="asei-stat-card">
                                        <span class="label">Tổng bài</span>
                                        <span id="seo-total" class="value">0</span>
                                    </div>
                                    <div class="asei-stat-card">
                                        <span class="label">Đã xử lý</span>
                                        <span id="seo-processed" class="value">0</span>
                                    </div>
                                    <div class="asei-stat-card" style="background: #ecfeff; border-color: #cffafe;">
                                        <span class="label" style="color: #0891b2;">Ảnh đã đánh dấu</span>
                                        <span id="seo-marked" class="value" style="color: #0e7490;">0</span>
                                    </div>
                                </div>
                                <div class="asei-progress-box">
                                    <div class="asei-progress-track"><div id="seo-fill" class="asei-progress-bar" style="background: linear-gradient(90deg, #0891b2, #06b6d4);"></div></div>
                                    <div class="asei-status-labels">
                                        <span id="seo-task-text">Đang chuẩn bị...</span>
                                        <span id="seo-task-pct">0%</span>
                                    </div>
                                </div>
                                <div class="asei-console-wrapper">
                                    <div id="asei-seo-log"></div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: CONTENT CHECKER -->
                        <div id="tab-content-check" class="asei-tab-content">
                            <div id="asei-content-setup" class="asei-view-setup">
                                <div class="asei-illus" style="background: #fdf2f8; color: #db2777;">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                        <polyline points="10 9 9 9 8 9"></polyline>
                                    </svg>
                                </div>
                                <p style="font-size: 18px; font-weight: 600; margin-bottom: 30px; max-width: 500px;">
                                    Phát hiện các thẻ HTML lạ không chuẩn SEO (như div, span, style...) trong nội dung bài viết và sản phẩm.
                                </p>
                                <button id="asei-content-btn" class="asei-start-button" style="background: #db2777 !important; box-shadow: 0 15px 25px -5px rgba(219, 39, 119, 0.3) !important;">
                                    <span>Bắt đầu kiểm tra nội dung</span>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                        <line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" />
                                    </svg>
                                </button>
                            </div>

                            <div id="asei-content-running" class="asei-view-running">
                                <div class="asei-stats-container">
                                    <div class="asei-stat-card">
                                        <span class="label">Tổng bài</span>
                                        <span id="content-total" class="value">0</span>
                                    </div>
                                    <div class="asei-stat-card">
                                        <span class="label">Đã kiểm tra</span>
                                        <span id="content-processed" class="value">0</span>
                                    </div>
                                    <div class="asei-stat-card" style="background: #fff1f2;">
                                        <span class="label" style="color: #e11d48;">Bài viết có thẻ lạ</span>
                                        <span id="content-bad" class="value" style="color: #be123c;">0</span>
                                    </div>
                                </div>
                                <div class="asei-progress-box">
                                    <div class="asei-progress-track"><div id="content-fill" class="asei-progress-bar" style="background: linear-gradient(90deg, #db2777, #ec4899);"></div></div>
                                    <div class="asei-status-labels">
                                        <span id="content-task-text">Đang chuẩn bị...</span>
                                        <span id="content-task-pct">0%</span>
                                    </div>
                                </div>
                                
                                <div class="asei-broken-table-wrapper" id="content-issues-container" style="display:none;">
                                    <table class="asei-broken-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 30%;">Bài viết</th>
                                                <th>Thẻ lạ phát hiện</th>
                                            </tr>
                                        </thead>
                                        <tbody id="content-issues-list"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                // Tab switching
                $('.asei-tab-btn').on('click', function() {
                    $('.asei-tab-btn').removeClass('active');
                    $(this).addClass('active');
                    $('.asei-tab-content').removeClass('active');
                    $('#' + $(this).data('tab')).addClass('active');
                });

                // --- Savelink Logic ---
                let current = 0;
                let updated = 0;

                $('#asei-run-btn').on('click', function () {
                    $('#asei-view-setup').fadeOut(300, function () {
                        $('#asei-view-running').fadeIn(400);
                        startWorker();
                    });
                });

                function startWorker() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'asei_process_bulk',
                            nonce: '<?php echo wp_create_nonce("asei_bulk_nonce"); ?>',
                            offset: current
                        },
                        success: function (res) {
                            if (res.success) {
                                if (res.data.finished) {
                                    $('#asei-task-text').html('<span style="color: #10b981">✨ HOÀN TẤT!</span>');
                                    $('#asei-task-pct').text('100%');
                                    $('#asei-fill').css('width', '100%');
                                    return;
                                }
                                let d = res.data;
                                current += d.processed;
                                updated += d.updated_chunk;
                                let pct = Math.round((current / d.total) * 100);
                                $('#asei-fill').css('width', pct + '%');
                                $('#asei-task-pct').text(pct + '%');
                                $('#asei-task-text').text('Đang xử lý: ' + current + ' / ' + d.total);
                                $('#val-total').text(d.total);
                                $('#val-scanned').text(current);
                                $('#val-updated').text(updated);
                                if (d.logs) {
                                    d.logs.forEach(l => {
                                        let tag = l.msg.includes(']') ? l.msg.split(']')[0].replace('[', '') : 'LOG';
                                        let msg = l.msg.includes(']') ? l.msg.split('] ')[1] : l.msg;
                                        log('#asei-log-entries', tag, msg, l.type.split('-')[1]);
                                    });
                                }
                                startWorker();
                            }
                        }
                    });
                }

                // --- Scanner Logic ---
                let scan_offset = 0;
                let broken_count = 0;
                let fixed_count = 0;

                $('#asei-scan-btn').on('click', function () {
                    $('#asei-scanner-setup').fadeOut(300, function () {
                        $('#asei-scanner-running').fadeIn(400);
                        startScanner();
                    });
                });

                function startScanner() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'asei_scan_broken_links',
                            nonce: '<?php echo wp_create_nonce("asei_bulk_nonce"); ?>',
                            offset: scan_offset
                        },
                        success: function (res) {
                            if (res.success) {
                                if (res.data.finished) {
                                    $('#scan-task-text').html('<span style="color: #10b981">✨ QUÉT HOÀN TẤT!</span>');
                                    $('#scan-task-pct').text('100%');
                                    $('#scan-fill').css('width', '100%');
                                    return;
                                }
                                let d = res.data;
                                scan_offset += d.processed;
                                let pct = Math.round((scan_offset / d.total) * 100);
                                $('#scan-fill').css('width', pct + '%');
                                $('#scan-task-pct').text(pct + '%');
                                $('#scan-task-text').text('Đang quét: ' + scan_offset + ' / ' + d.total);
                                $('#scan-total').text(d.total);
                                $('#scan-processed').text(scan_offset);

                                if (d.broken_links && d.broken_links.length > 0) {
                                    $('#broken-links-container').show();
                                    d.broken_links.forEach(item => {
                                        let badgeClass = item.status === 'fixed' ? 'asei-badge-fixed' : 'asei-badge-error';
                                        if (item.status === 'fixed') {
                                            fixed_count++;
                                            $('#scan-fixed').text(fixed_count);
                                        } else {
                                            broken_count++;
                                            $('#scan-broken').text(broken_count);
                                        }
                                        
                                        let row = `
                                            <tr>
                                                <td><strong>#${item.post_id}</strong><br><small>${item.title}</small></td>
                                                <td><div style="max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${item.url}"><a href="${item.url.split(' ➔ ')[0]}" target="_blank">${item.url}</a></div></td>
                                                <td><span class="${badgeClass}">${item.code}</span></td>
                                            </tr>
                                        `;
                                        $('#broken-links-list').append(row);
                                    });
                                }
                                startScanner();
                            }
                        }
                    });
                }

                // --- SEO Logic ---
                let seo_offset = 0;
                let seo_marked_count = 0;

                $('#asei-seo-btn').on('click', function () {
                    $('#asei-seo-setup').fadeOut(300, function () {
                        $('#asei-seo-running').fadeIn(400);
                        startSeoMarking();
                    });
                });

                function startSeoMarking() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'asei_seo_marking',
                            nonce: '<?php echo wp_create_nonce("asei_bulk_nonce"); ?>',
                            offset: seo_offset
                        },
                        success: function (res) {
                            if (res.success) {
                                if (res.data.finished) {
                                    $('#seo-task-text').html('<span style="color: #10b981">✨ ĐÁNH DẤU HOÀN TẤT!</span>');
                                    $('#seo-task-pct').text('100%');
                                    $('#seo-fill').css('width', '100%');
                                    return;
                                }
                                let d = res.data;
                                seo_offset += d.processed;
                                seo_marked_count += d.marked_count;
                                let pct = Math.round((seo_offset / d.total) * 100);
                                $('#seo-fill').css('width', pct + '%');
                                $('#seo-task-pct').text(pct + '%');
                                $('#seo-task-text').text('Đang xử lý: ' + seo_offset + ' / ' + d.total);
                                $('#seo-total').text(d.total);
                                $('#seo-processed').text(seo_offset);
                                $('#seo-marked').text(seo_marked_count);

                                if (d.logs) {
                                    d.logs.forEach(l => {
                                        let tag = l.msg.includes(']') ? l.msg.split(']')[0].replace('[', '') : 'SEO';
                                        let msg = l.msg.includes('] ') ? l.msg.split('] ')[1] : (l.msg.includes(']') ? l.msg.split(']')[1] : l.msg);
                                        log('#asei-seo-log', tag, msg, l.type.split('-')[1]);
                                    });
                                }
                                startSeoMarking();
                            }
                        }
                    });
                }

                // --- Content Checker Logic ---
                let content_offset = 0;
                let bad_content_count = 0;

                $('#asei-content-btn').on('click', function () {
                    $('#asei-content-setup').fadeOut(300, function () {
                        $('#asei-content-running').fadeIn(400);
                        startContentChecker();
                    });
                });

                function startContentChecker() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'asei_check_content',
                            nonce: '<?php echo wp_create_nonce("asei_bulk_nonce"); ?>',
                            offset: content_offset
                        },
                        success: function (res) {
                            if (res.success) {
                                if (res.data.finished) {
                                    $('#content-task-text').html('<span style="color: #10b981">✨ KIỂM TRA HOÀN TẤT!</span>');
                                    $('#content-task-pct').text('100%');
                                    $('#content-fill').css('width', '100%');
                                    return;
                                }
                                let d = res.data;
                                content_offset += d.processed;
                                let pct = Math.round((content_offset / d.total) * 100);
                                $('#content-fill').css('width', pct + '%');
                                $('#content-task-pct').text(pct + '%');
                                $('#content-task-text').text('Đang kiểm tra: ' + content_offset + ' / ' + d.total);
                                $('#content-total').text(d.total);
                                $('#content-processed').text(content_offset);

                                if (d.issues && d.issues.length > 0) {
                                    $('#content-issues-container').show();
                                    d.issues.forEach(item => {
                                        bad_content_count++;
                                        $('#content-bad').text(bad_content_count);
                                        
                                        let tagsHtml = item.strange_tags.map(tag => `<code style="background:#fff1f2; color:#be123c; padding:2px 6px; border-radius:4px; margin-right:5px; margin-bottom:5px; display:inline-block;">&lt;${tag}&gt;</code>`).join('');
                                        
                                        let row = `
                                            <tr>
                                                <td>
                                                    <strong>#${item.post_id}</strong><br>
                                                    <small>${item.title}</small><br>
                                                    <a href="${item.edit_link}" target="_blank" style="font-size:11px;">Chỉnh sửa ↗</a>
                                                </td>
                                                <td>${tagsHtml}</td>
                                            </tr>
                                        `;
                                        $('#content-issues-list').append(row);
                                    });
                                }
                                startContentChecker();
                            }
                        }
                    });
                }

                function log(container, tag, msg, type) {
                    const $con = $(container);
                    const line = `
                        <div class="con-line">
                            <span class="con-badge bg-${type}">${tag}</span>
                            <span class="con-msg">${msg}</span>
                        </div>
                    `;
                    $con.prepend(line);
                }
            });
        </script>
        <?php
    }

    public function ajax_check_content()
    {
        check_ajax_referer('asei_bulk_nonce', 'nonce');
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $posts_per_page = 10;

        $args = array(
            'post_type' => array('post', 'product'),
            'post_status' => 'publish',
            'posts_per_page' => $posts_per_page,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'DESC'
        );

        $query = new WP_Query($args);
        $total_posts = (int)wp_count_posts('post')->publish + (int)wp_count_posts('product')->publish;

        $allowed_tags = array(
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 
            'p', 'strong', 'b', 'em', 'i', 'blockquote', 
            'ul', 'ol', 'li', 
            'img', 'figure', 'figcaption', 'iframe', 
            'a', 'article', 'section',
            'br' // Exception
        );

        $issues = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $content = get_the_content();
                
                // Find all tags
                preg_match_all('/<([a-z1-6]+)/i', $content, $matches);
                if (!empty($matches[1])) {
                    $tags = array_unique(array_map('strtolower', $matches[1]));
                    $strange_tags = array_diff($tags, $allowed_tags);
                    
                    if (!empty($strange_tags)) {
                        $issues[] = array(
                            'post_id' => get_the_ID(),
                            'title' => get_the_title(),
                            'edit_link' => get_edit_post_link(get_the_ID()),
                            'strange_tags' => array_values($strange_tags)
                        );
                    }
                }
            }
            wp_reset_postdata();

            wp_send_json_success(array(
                'processed' => $query->post_count,
                'total' => $total_posts,
                'finished' => false,
                'issues' => $issues
            ));
        } else {
            wp_send_json_success(array('finished' => true));
        }
    }

    public function ajax_scan_broken_links()
    {
        check_ajax_referer('asei_bulk_nonce', 'nonce');
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $posts_per_page = 5;

        $args = array(
            'post_type' => array('post', 'product'),
            'post_status' => 'publish',
            'posts_per_page' => $posts_per_page,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'DESC'
        );

        $query = new WP_Query($args);
        $total_posts = (int)wp_count_posts('post')->publish + (int)wp_count_posts('product')->publish;

        $broken_links = array();
        $home_url_host = parse_url(home_url(), PHP_URL_HOST);

        if ($query->have_posts()) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');

            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $content = get_the_content();
                $original_content = $content;
                $post_updated = false;
                
                preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);
                if (!empty($matches[1])) {
                    $urls = array_unique($matches[1]);
                    foreach ($urls as $url) {
                        if (strpos($url, 'data:image') === 0) continue;

                        // Identify if URL is internal
                        $is_internal = false;
                        $full_url = $url;

                        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
                            $is_internal = true;
                            $full_url = home_url($url);
                        } else {
                            $url_host = parse_url($url, PHP_URL_HOST);
                            if ($url_host === $home_url_host) {
                                $is_internal = true;
                            }
                        }

                        // Check if it's already in standard WP uploads
                        $is_standard_wp = (strpos($url, 'wp-content/uploads') !== false);

                        // Ping the URL
                        $response = wp_remote_head($full_url, array('timeout' => 5, 'sslverify' => false));
                        $code = wp_remote_retrieve_response_code($response);

                        // LOGIC: Internal but non-standard (e.g. /public/media/...)
                        if ($is_internal && !$is_standard_wp) {
                            if ($code == 200) {
                                // Image exists but not in Media Library - Let's Fix It!
                                $new_url = media_sideload_image($full_url, $post_id, get_the_title(), 'src');
                                if (!is_wp_error($new_url) && !empty($new_url)) {
                                    $content = str_replace($url, $new_url, $content);
                                    $post_updated = true;
                                    $broken_links[] = array(
                                        'post_id' => $post_id,
                                        'title' => get_the_title(),
                                        'url' => $url . ' ➔ ' . basename($new_url),
                                        'code' => 'ĐÃ SỬA',
                                        'status' => 'fixed'
                                    );
                                    continue; // Move to next image
                                }
                            }
                        }

                        // Normal broken link check
                        if ($code === '' || $code >= 400 || is_wp_error($response)) {
                            $error_msg = is_wp_error($response) ? $response->get_error_message() : $code;
                            $broken_links[] = array(
                                'post_id' => $post_id,
                                'title' => get_the_title(),
                                'url' => $url,
                                'code' => $error_msg ? $error_msg : '404',
                                'status' => 'error'
                            );
                        }
                    }
                }

                if ($post_updated) {
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $content
                    ));
                }
            }
            wp_reset_postdata();

            wp_send_json_success(array(
                'processed' => $query->post_count,
                'total' => $total_posts,
                'finished' => false,
                'broken_links' => $broken_links
            ));
        } else {
            wp_send_json_success(array('finished' => true));
        }
    }

    public function ajax_seo_marking()
    {
        check_ajax_referer('asei_bulk_nonce', 'nonce');
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $posts_per_page = 5;

        $args = array(
            'post_type' => array('post', 'product'),
            'post_status' => 'publish',
            'posts_per_page' => $posts_per_page,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'DESC'
        );

        $query = new WP_Query($args);
        $total_posts = (int)wp_count_posts('post')->publish + (int)wp_count_posts('product')->publish;

        $logs = array();
        $marked_count = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $post_title = get_the_title();
                $content = get_the_content();
                $original_content = $content;
                
                preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);
                if (!empty($matches[1])) {
                    $urls = array_unique($matches[1]);
                    $count = 1;
                    foreach ($urls as $url) {
                        $label = $post_title;
                        if (count($urls) > 1) {
                            $label .= ' ' . $count;
                        }

                        // Update Alt/Title in content
                        $pattern = '/<img([^>]+)src=["\']' . preg_quote($url, '/') . '["\']([^>]*)>/i';
                        
                        // Check if already has alt/title
                        if (preg_match($pattern, $content, $img_matches)) {
                            $img_tag = $img_matches[0];
                            $new_img_tag = $img_tag;
                            
                            // Remove existing alt/title to avoid duplicates
                            $new_img_tag = preg_replace('/alt=["\'][^"\']*["\']/i', '', $new_img_tag);
                            $new_img_tag = preg_replace('/title=["\'][^"\']*["\']/i', '', $new_img_tag);
                            
                            // Add new alt/title
                            $new_img_tag = str_replace('<img', '<img alt="' . esc_attr($label) . '" title="' . esc_attr($label) . '"', $new_img_tag);
                            
                            $content = str_replace($img_tag, $new_img_tag, $content);
                        }

                        // Try to find attachment ID by URL
                        $attachment_id = attachment_url_to_postid($url);
                        if ($attachment_id) {
                            // Update Alt/Title
                            update_post_meta($attachment_id, '_wp_attachment_image_alt', $label);
                            
                            $update_data = array(
                                'ID' => $attachment_id,
                                'post_title' => $label,
                                'post_excerpt' => $label,
                            );

                            // Point 2: Ensure data link (post_parent)
                            $current_attachment = get_post($attachment_id);
                            if (empty($current_attachment->post_parent)) {
                                $update_data['post_parent'] = $post_id;
                            }

                            wp_update_post($update_data);
                        }
                        
                        $marked_count++;
                        $count++;
                    }
                }

                if ($content !== $original_content) {
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $content
                    ));
                    $logs[] = array('type' => 'log-success', 'msg' => '[#' . $post_id . '] ' . $post_title . ' - Đã cập nhật nhãn SEO.');
                } else {
                    $logs[] = array('type' => 'log-info', 'msg' => '[#' . $post_id . '] ' . $post_title . ' - Không có thay đổi.');
                }
            }
            wp_reset_postdata();

            wp_send_json_success(array(
                'processed' => $query->post_count,
                'total' => $total_posts,
                'finished' => false,
                'marked_count' => $marked_count,
                'logs' => $logs
            ));
        } else {
            wp_send_json_success(array('finished' => true));
        }
    }

    public function ajax_process_bulk()
    {
        check_ajax_referer('asei_bulk_nonce', 'nonce');

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $posts_per_page = 3; // Keep it low for performance

        $args = array(
            'post_type' => array('post', 'product'),
            'post_status' => 'publish',
            'posts_per_page' => $posts_per_page,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'DESC'
        );

        $query = new WP_Query($args);
        $total_posts = wp_count_posts('post')->publish + wp_count_posts('product')->publish;

        $logs = array();
        $updated_chunk = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $content = get_the_content();

                $updated_content = $this->sideload_external_images($content, $post_id);

                if ($content !== $updated_content) {
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $updated_content,
                    ));
                    $logs[] = array('type' => 'log-success', 'msg' => '[#' . $post_id . '] ' . get_the_title() . ' - Đã tải ảnh xong.');
                    $updated_chunk++;
                } else {
                    $logs[] = array('type' => 'log-info', 'msg' => '[#' . $post_id . '] ' . get_the_title() . ' - Không có ảnh ngoài.');
                }
            }
            wp_reset_postdata();

            wp_send_json_success(array(
                'processed' => $query->post_count,
                'total' => $total_posts,
                'finished' => false,
                'logs' => $logs,
                'updated_chunk' => $updated_chunk
            ));
        } else {
            wp_send_json_success(array('finished' => true));
        }
    }

    /**
     * Process images in post content when a post is saved.
     */
    public function process_images_on_save($post_id, $post, $update)
    {
        // Skip revisions and autosaves.
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        // Only process for products and posts.
        $allowed_post_types = array('post', 'product');
        if (!in_array($post->post_type, $allowed_post_types)) {
            return;
        }

        // Avoid infinite loop.
        remove_action('save_post', array($this, 'process_images_on_save'));

        $content = $post->post_content;
        $updated_content = $this->sideload_external_images($content, $post_id);

        if ($content !== $updated_content) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $updated_content,
            ));
        }

        // Re-add the action.
        add_action('save_post', array($this, 'process_images_on_save'), 10, 3);
    }

    /**
     * Find and sideload external images in the content.
     */
    private function sideload_external_images($content, $post_id)
    {
        if (empty($content)) {
            return $content;
        }

        // Match all img tags and their src attributes.
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);

        if (empty($matches[1])) {
            return $content;
        }

        $external_urls = array_unique($matches[1]);
        $home_url = parse_url(home_url(), PHP_URL_HOST);

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $post_title = get_the_title($post_id);
        $count = 1;
        $total_ext = count($external_urls);

        foreach ($external_urls as $url) {
            // Check if URL is definitely external.
            $url_host = parse_url($url, PHP_URL_HOST);

            if (!$url_host || $url_host === $home_url || strpos($url, 'data:image') === 0) {
                continue;
            }

            $label = $post_title;
            if ($total_ext > 1) {
                $label .= ' ' . $count;
            }

            // Attempt to sideload the image with SEO label
            $attachment_id = media_sideload_image($url, $post_id, $label, 'id');

            if (!is_wp_error($attachment_id)) {
                // Update ALT and Title for Media Library
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $label);
                wp_update_post(array(
                    'ID' => $attachment_id,
                    'post_title' => $label,
                    'post_excerpt' => $label,
                ));

                $new_url = wp_get_attachment_url($attachment_id);
                if ($new_url) {
                    // Replace the old URL with the new local URL and add ALT/TITLE
                    $pattern = '/<img([^>]+)src=["\']' . preg_quote($url, '/') . '["\']([^>]*)>/i';
                    $replacement = '<img$1src="' . $new_url . '" alt="' . esc_attr($label) . '" title="' . esc_attr($label) . '"$2>';
                    $content = preg_replace($pattern, $replacement, $content);
                }
            }
            $count++;
        }

        return $content;
    }

    /**
     * Check for updates from GitHub
     */
    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $repo_user = 'AcmaTvirus'; 
        $repo_name = 'auto-save-external-images';
        $url = "https://api.github.com/repos/$repo_user/$repo_name/releases/latest";
        
        $args = array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Plugin-Update-Checker'
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
            return $transient;
        }

        $release = json_decode(wp_remote_retrieve_body($response));
        $new_version = str_replace('v', '', $release->tag_name);
        $current_version = '1.0.0'; 

        if (version_compare($current_version, $new_version, '<')) {
            $plugin_slug = plugin_basename(__FILE__);
            $package_url = '';
            
            if (!empty($release->assets)) {
                foreach ($release->assets as $asset) {
                    if (strpos($asset->name, '.zip') !== false) {
                        $package_url = $asset->browser_download_url;
                        break;
                    }
                }
            }

            if ($package_url) {
                $obj = new stdClass();
                $obj->slug = 'auto-save-external-images';
                $obj->new_version = $new_version;
                $obj->url = $release->html_url;
                $obj->package = $package_url;
                $transient->response[$plugin_slug] = $obj;
            }
        }

        return $transient;
    }

    /**
     * Provide plugin info for the update popup
     */
    public function plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information') {
            return false;
        }

        if (isset($args->slug) && $args->slug === 'auto-save-external-images') {
            $repo_user = 'AcmaTvirus';
            $repo_name = 'auto-save-external-images';
            $url = "https://api.github.com/repos/$repo_user/$repo_name/releases/latest";
            
            $response = wp_remote_get($url, array(
                'headers' => array('User-Agent' => 'WordPress-Plugin')
            ));

            if (!is_wp_error($response)) {
                $release = json_decode(wp_remote_retrieve_body($response));
                $res = new stdClass();
                $res->name = 'Auto Save External Images';
                $res->slug = 'auto-save-external-images';
                $res->version = str_replace('v', '', $release->tag_name);
                $res->author = 'AcmaTvirus';
                $res->homepage = "https://github.com/$repo_user/$repo_name";
                $res->download_link = '';
                
                if (!empty($release->assets)) {
                    foreach ($release->assets as $asset) {
                        if (strpos($asset->name, '.zip') !== false) {
                            $res->download_link = $asset->browser_download_url;
                            break;
                        }
                    }
                }
                
                $res->sections = array(
                    'description' => $release->body,
                    'changelog' => 'Xem chi tiết trên GitHub.'
                );
                return $res;
            }
        }
        return false;
    }
}

// Initialize the plugin.
new Auto_Save_External_Images();
