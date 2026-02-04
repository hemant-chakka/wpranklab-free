<?php
if ( ! defined('ABSPATH') ) exit;

class WPRL_Setup_Wizard {

  public function init() {
    add_action('admin_menu', [$this, 'register_page']);
    add_action('admin_init', [$this, 'maybe_redirect_to_wizard']);
    add_action('admin_post_wprl_setup_save', [$this, 'handle_save']);
  }

  public function register_page() {
    add_submenu_page(
      null,
      'WPRankLab Setup',
      'WPRankLab Setup',
      'manage_options',
      'wprl-setup-wizard',
      [$this, 'render']
    );
  }

  public function maybe_redirect_to_wizard() {
    if ( (int) get_option('wprl_setup_complete', 0) === 1 ) {
      return;
    }

    if ( is_admin()
      && current_user_can('manage_options')
      && get_transient('wprl_do_setup_redirect')
    ) {
      delete_transient('wprl_do_setup_redirect');
      wp_safe_redirect( admin_url('admin.php?page=wprl-setup-wizard') );
      exit;
    }
  }

  public function render() {
    $step = isset($_GET['step']) ? absint($_GET['step']) : 1;
    require WPRL_PATH . 'includes/setup-wizard/views/wizard-wrapper.php';
  }

  public function handle_save() {
    $step = absint($_POST['wprl_step'] ?? 0);
    check_admin_referer('wprl_setup_step_' . $step);

    // STEP 2
    if ( $step === 2 ) {
      update_option('wprl_site_type', sanitize_text_field($_POST['wprl_site_type'] ?? ''));
      update_option('wprl_language', sanitize_text_field($_POST['wprl_language'] ?? ''));
      update_option('wprl_enable_ai', isset($_POST['wprl_enable_ai']) ? 1 : 0 );
    }

    // STEP 3
    if ( $step === 3 ) {
      update_option('wprl_scan_posts', isset($_POST['wprl_scan_posts']) ? 1 : 0 );
      update_option('wprl_scan_pages', isset($_POST['wprl_scan_pages']) ? 1 : 0 );
    }

    // STEP 4
    if ( $step === 4 ) {
      $email = isset($_POST['wprl_report_email']) ? sanitize_email($_POST['wprl_report_email']) : '';
      update_option('wprl_report_email', $email);
      update_option('wprl_weekly_reports_enabled', isset($_POST['wprl_weekly_reports_enabled']) ? 1 : 0 );
    }

    // STEP 5 — FINAL
    if ( $step === 5 ) {
      update_option('wprl_setup_complete', 1);

      if ( isset($_POST['wprl_run_first_scan']) ) {
        $post_types = [];
        if ( (int) get_option('wprl_scan_posts', 1) === 1 ) { $post_types[] = 'post'; }
        if ( (int) get_option('wprl_scan_pages', 1) === 1 ) { $post_types[] = 'page'; }
        if ( empty($post_types) ) { $post_types = ['post','page']; }

        if ( class_exists('WPRankLab_Batch_Scan') ) {
          $bs = WPRankLab_Batch_Scan::get_instance();
          if ( $bs && method_exists($bs, 'start_scan') ) {
            $bs->start_scan($post_types);
          }
        }
      }

      wp_safe_redirect( admin_url('admin.php?page=wpranklab') );
      exit;
    }

    // Default: go to next step
    wp_safe_redirect( admin_url('admin.php?page=wprl-setup-wizard&step=' . ($step + 1)) );
    exit;
  }
}
