<?php
if ( ! defined('WP_UNINSTALL_PLUGIN') ) { exit; }
delete_option('wprl_setup_complete');
delete_option('wprl_site_type');
delete_option('wprl_language');
delete_option('wprl_enable_ai');
delete_option('wprl_scan_posts');
delete_option('wprl_scan_pages');
delete_option('wprl_report_email');
delete_option('wprl_weekly_reports_enabled');
