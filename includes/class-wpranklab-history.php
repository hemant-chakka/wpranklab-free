<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles history snapshots and weekly emails.
 */
class WPRankLab_History {

    /**
     * Singleton.
     *
     * @var WPRankLab_History|null
     */
    protected static $instance = null;

    /**
     * Get instance.
     *
     * @return WPRankLab_History
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Init hooks.
     */
    public function init() {
        // Ensure history table exists.
        $this->maybe_create_table();

        // Hook weekly report event (already scheduled by activator).
        add_action( 'wpranklab_weekly_report', array( $this, 'handle_weekly_event' ) );
        
        add_action( 'init', array( $this, 'ensure_weekly_event' ) );
        
    }

    /**
     * Create history table if it does not exist.
     */
    protected function maybe_create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wpranklab_history';

        // Check if table exists.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( $exists === $table_name ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "
        CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            snapshot_date DATE NOT NULL,
            avg_score FLOAT NULL,
            scanned_count INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY snapshot_date (snapshot_date)
        ) {$charset_collate};
        ";

        dbDelta( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    /**
     * Handle weekly cron: record snapshot + send email.
     */
    public function handle_weekly_event() {
        
        // Prevent duplicate weekly sends if WP-Cron triggers twice.
        if ( get_transient( 'wpranklab_weekly_report_lock' ) ) {
            return;
        }
        set_transient( 'wpranklab_weekly_report_lock', 1, 15 * MINUTE_IN_SECONDS );
        
        $snapshot = $this->record_snapshot();
        
        // Email (should already exist)
        $this->send_weekly_email( $snapshot );
        
        // Webhook (you already have this method)
        $this->send_webhook();
    }
    
    

    /**
     * Record a history snapshot for the current site state.
     *
     * @return array Snapshot data.
     */
    public function record_snapshot() {
        global $wpdb;

        $history_table = $wpdb->prefix . 'wpranklab_history';

        // Get all posts/pages with a visibility score.
        $post_types = apply_filters(
            'wpranklab_analyzer_post_types',
            array( 'post', 'page' )
        );

        $meta_key = '_wpranklab_visibility_score';

        if ( empty( $post_types ) || ! is_array( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        $placeholders = implode(
            ', ',
            array_fill( 0, count( $post_types ), '%s' )
        );

        $sql = $wpdb->prepare(
            "
            SELECT pm.meta_value AS score
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
              AND p.post_type IN ($placeholders)
              AND p.post_status = 'publish'
            ",
            array_merge( array( $meta_key ), $post_types )
        );

        $rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $scores = array();
        if ( $rows ) {
            foreach ( $rows as $row ) {
                if ( is_numeric( $row ) ) {
                    $scores[] = (float) $row;
                }
            }
        }

        $scanned_count = count( $scores );
        $avg_score     = $scanned_count > 0 ? array_sum( $scores ) / $scanned_count : null;

        $today = current_time( 'Y-m-d' );

        $snapshot = array(
            'snapshot_date' => $today,
            'avg_score'     => $avg_score,
            'scanned_count' => $scanned_count,
        );

        // Insert into history table.
        $wpdb->insert(
            $history_table,
            array(
                'snapshot_date' => $today,
                'avg_score'     => $avg_score,
                'scanned_count' => $scanned_count,
            ),
            array( '%s', '%f', '%d' )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return $snapshot;
    }

    /**
     * Get last N snapshots.
     *
     * @param int $limit
     *
     * @return array
     */
    public function get_recent_snapshots( $limit = null ) {
        global $wpdb;

        $history_table = $wpdb->prefix . 'wpranklab_history';

        $sql = "SELECT snapshot_date, avg_score, scanned_count
             FROM {$history_table}
             ORDER BY snapshot_date DESC";

        // Apply LIMIT only when explicitly requested (> 0). null/0 means unlimited.
        if ( is_int( $limit ) && $limit > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql .= $wpdb->prepare( ' LIMIT %d', $limit );
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return $rows ? $rows : array();
    }

    /**
     * Send weekly email based on latest snapshot.
     *
     * @param array $snapshot
     */
    public function send_weekly_email( $snapshot ) {
        $settings = get_option( 'wpranklab_settings', array() );
        if ( empty( $settings['weekly_email'] ) ) {
            return;
        }
        
        
        // If there is no data yet, do not send.
        if ( empty( $snapshot ) ) {
            return;
        }
        
        $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        if ( empty( $settings['weekly_email'] ) ) {
            return;
        }
        

        $is_pro  = function_exists( 'wpranklab_is_pro_active' ) && wpranklab_is_pro_active();
        $site    = get_bloginfo( 'name' );
        $to      = get_option( 'wprl_report_email' );
        if ( empty( $to ) ) { $to = get_option( 'admin_email' ); }
        $subject = sprintf(
            /* translators: %s: site name. */
            __( 'Your Weekly AI Visibility Update — %s', 'wpranklab' ),
            $site
        );

        $avg_score     = is_null( $snapshot['avg_score'] ) ? __( 'N/A', 'wpranklab' ) : round( $snapshot['avg_score'], 1 );
        $scanned_count = (int) $snapshot['scanned_count'];
        $date          = $snapshot['snapshot_date'];

        // Compare with previous snapshot to determine up/down.
        $trend_arrow = '';
        $trend_label = __( 'No previous data', 'wpranklab' );

        $recent = $this->get_recent_snapshots( 2 );
        if ( count( $recent ) >= 2 ) {
            $current = $recent[0];
            $prev    = $recent[1];

            if ( ! is_null( $current['avg_score'] ) && ! is_null( $prev['avg_score'] ) ) {
                if ( $current['avg_score'] > $prev['avg_score'] ) {
                    $trend_arrow = '↑';
                    $trend_label = __( 'Visibility improved since last week.', 'wpranklab' );
                } elseif ( $current['avg_score'] < $prev['avg_score'] ) {
                    $trend_arrow = '↓';
                    $trend_label = __( 'Visibility decreased since last week.', 'wpranklab' );
                } else {
                    $trend_arrow = '→';
                    $trend_label = __( 'Visibility is stable compared to last week.', 'wpranklab' );
                }
            }
        }

        

if ( ! $is_pro ) {
    // Free email: HTML template (Figma-aligned).
    $domain       = wp_parse_url( home_url(), PHP_URL_HOST );
    $greeting     = sprintf(
        /* translators: %s: domain */
        __( 'Hi there, here are your weekly stats on %s', 'wpranklab' ),
        $domain
    );

    $visibility    = is_numeric( $avg_score ) ? round( (float) $avg_score ) . '%' : $avg_score;
    $site_rank     = '—';
    $ai_visits     = '—';
    $crawl_visits  = '—';

    $upgrade_url = apply_filters( 'wpranklab_upgrade_url', 'https://wpranklab.com/' );

    $body = $this->build_free_weekly_email_html(
        array(
            'logo_text'      => 'WPRANKLAB',
            'greeting'       => $greeting,
            'visibility'     => $visibility,
            'trend_arrow'    => $trend_arrow,
            'site_rank'      => $site_rank,
            'ai_visits'      => $ai_visits,
            'crawler_visits' => $crawl_visits,
            'upgrade_url'    => $upgrade_url,
        )
    );
} else {
    // Pro email: keep plain text for now.
    $body  = '';
    $body .= sprintf( __( "Date: %s\n", 'wpranklab' ), $date );
    $body .= sprintf( __( "AI Visibility Score: %s %s\n", 'wpranklab' ), $avg_score, $trend_arrow );
    $body .= sprintf( __( "Scanned items: %d\n\n", 'wpranklab' ), $scanned_count );
    $body .= $trend_label . "\n\n";
    $body .= __( "In future versions, this email will also include:\n- Citation rank\n- AI / crawler visits\n- Detailed week summary\n- Top recommendations for next week\n", 'wpranklab' );
    $body .= "\n";
    $body .= __( 'Open your full AI Visibility report in WordPress:', 'wpranklab' ) . "\n";
    $body .= admin_url( 'admin.php?page=wpranklab' ) . "\n";
}


/**
         * Filter the email before sending.
         *
         * @param array  $email {'to','subject','body','headers'}
         * @param array  $snapshot
         * @param bool   $is_pro
         */
        $email = apply_filters(
            'wpranklab_weekly_email',
            array(
                'to'      => $to,
                'subject' => $subject,
                'body'    => $body,
                'headers' => array(
                    'Content-Type: ' . ( $is_pro ? 'text/plain' : 'text/html' ) . '; charset=UTF-8',
                    'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
                ),
            ),
            $snapshot,
            $is_pro
        );

        if ( ! empty( $email['to'] ) && ! empty( $email['subject'] ) && ! empty( $email['body'] ) ) {
            $headers = ! empty( $email['headers'] ) ? (array) $email['headers'] : array(
                'Content-Type: ' . ( $is_pro ? 'text/plain' : 'text/html' ) . '; charset=UTF-8',
                'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
            );

			// For FREE HTML emails, embed the logo so it renders in Gmail/Outlook even when
			// the WordPress site is not publicly reachable (e.g., local dev domains).
			// This avoids broken-image icons caused by remote image blocking.
			$embedded_logo_cid  = 'wpranklab_logo';
			$embedded_logo_path = defined( 'WPRANKLAB_PLUGIN_DIR' ) ? WPRANKLAB_PLUGIN_DIR . 'assets/img/email-logo.png' : '';
			$did_embed_logo     = false;

			$phpmailer_cb = null;
			if ( ! $is_pro && ! empty( $embedded_logo_path ) && file_exists( $embedded_logo_path ) ) {
				$phpmailer_cb = function ( $phpmailer ) use ( $embedded_logo_path, $embedded_logo_cid, &$did_embed_logo ) {
					try {
						// Embed as an inline attachment and reference with src="cid:..." in the HTML.
						$phpmailer->addEmbeddedImage( $embedded_logo_path, $embedded_logo_cid, basename( $embedded_logo_path ) );
						$did_embed_logo = true;
					} catch ( \Exception $e ) {
						// If embedding fails, fall back to normal sending.
						$did_embed_logo = false;
					}
				};
				add_action( 'phpmailer_init', $phpmailer_cb );

				// If we're embedding, swap any remote logo URL to the CID reference.
				if ( ! empty( $email['body'] ) ) {
					$email['body'] = str_replace( 'src="' . esc_url( WPRANKLAB_PLUGIN_URL . 'assets/img/email-logo.png' ) . '"', 'src="cid:' . $embedded_logo_cid . '"', $email['body'] );
					$email['body'] = str_replace( "src='" . esc_url( WPRANKLAB_PLUGIN_URL . 'assets/img/email-logo.png' ) . "'", "src='cid:" . $embedded_logo_cid . "'", $email['body'] );
				}
			}

			wp_mail( $email['to'], $email['subject'], $email['body'], $headers );

			if ( $phpmailer_cb ) {
				remove_action( 'phpmailer_init', $phpmailer_cb );
			}
        }
    }



    /**
     * Build the FREE weekly email HTML (Figma-aligned, email-client safe).
     *
     * @param array $data
     * @return string
     */
    protected function build_free_weekly_email_html( $data ) {
        $logo_text      = isset( $data['logo_text'] ) ? (string) $data['logo_text'] : 'WPRANKLAB';
        $greeting       = isset( $data['greeting'] ) ? (string) $data['greeting'] : '';
        $visibility     = isset( $data['visibility'] ) ? (string) $data['visibility'] : '—';
        $trend_arrow    = isset( $data['trend_arrow'] ) ? (string) $data['trend_arrow'] : '';
        $site_rank      = isset( $data['site_rank'] ) ? (string) $data['site_rank'] : '—';
        $ai_visits      = isset( $data['ai_visits'] ) ? (string) $data['ai_visits'] : '—';
        $crawler_visits = isset( $data['crawler_visits'] ) ? (string) $data['crawler_visits'] : '—';
        $upgrade_url    = isset( $data['upgrade_url'] ) ? (string) $data['upgrade_url'] : 'https://wpranklab.com/';

        // Basic sanitization for HTML email.
        $logo_text   = esc_html( $logo_text );
        $greeting    = esc_html( $greeting );
        $visibility  = esc_html( $visibility );
        $trend_arrow = esc_html( $trend_arrow );
        $site_rank   = esc_html( $site_rank );
        $ai_visits   = esc_html( $ai_visits );
        $crawler_visits = esc_html( $crawler_visits );
        $upgrade_url = esc_url( $upgrade_url );

        // Simple icons (email-safe). Use emoji for maximum compatibility.
        $icon_up   = '👍';
        $icon_down = '👎';

        // Decide icon for visibility based on arrow.
        $vis_icon = ( '↓' === $trend_arrow ) ? $icon_down : $icon_up;

        // Colors from palette.
        $orange = '#FEB201';
        $teal   = '#19AEAD';
        $card_bg = '#F5FBFF';
        $text   = '#0B0F14';
        $muted  = '#5B6470';
        $border = '#E6E9EF';

        // Email container width.
        $w = 640;

        $html = '';
        $html .= '<!doctype html><html><head><meta charset="utf-8"></head>';
        $html .= '<body style="margin:0;padding:0;background:#ffffff;font-family:Inter,Arial,sans-serif;color:' . $text . ';">';

        // Outer wrapper.
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff;">';
        $html .= '<tr><td align="center" style="padding:24px 12px;">';

        // Brand.
        $html .= '<table role="presentation" width="' . (int) $w . '" cellspacing="0" cellpadding="0" border="0" style="width:' . (int) $w . 'px;max-width:100%;">';
        $html .= '<tr><td align="center" style="padding:8px 0 10px 0;">';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">';
$html .= '<tr>';
$html .= '<td valign="middle" style="padding-right:10px;">';
		// Use a CID reference for maximum email-client compatibility.
		// The actual image is embedded during sending via the phpmailer_init hook.
		$html .= '<img src="cid:wpranklab_logo" width="48" height="48" alt="WPRankLab" style="display:block;border:0;outline:none;text-decoration:none;">';
$html .= '</td>';
		// Wordmark should match brand orange (per Figma frame).
		$html .= '<td valign="middle" style="font-size:28px;font-weight:900;letter-spacing:1px;color:' . $orange . ';">' . $logo_text . '</td>';
$html .= '</tr></table>';
        if ( $greeting ) {
            $html .= '<div style="font-size:13px;color:' . $muted . ';margin-top:6px;">' . $greeting . '</div>';
        }
        $html .= '</td></tr>';
        $html .= '</table>';

        // Orange stats panel.
        $html .= '<table role="presentation" width="' . (int) $w . '" cellspacing="0" cellpadding="0" border="0" style="width:' . (int) $w . 'px;max-width:100%;background:' . $orange . ';border-radius:0px;">';
        $html .= '<tr><td style="padding:34px 24px 28px 24px;">';

        // Title.
        $html .= '<div style="text-align:center;font-size:44px;line-height:1.05;font-weight:900;color:#ffffff;margin-bottom:22px;">';
        $html .= 'Your <span style="font-weight:700;">weekly</span> stats';
        $html .= '</div>';

        // 2x2 cards table.
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">';

        // Row 1
        $html .= '<tr>';

        // Visibility Score
        $html .= '<td width="50%" style="padding:10px;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $card_bg . ';border-radius:14px;border:1px solid ' . $border . ';">';
        $html .= '<tr><td align="center" style="padding:18px 10px 16px 10px;">';
        $html .= '<div style="font-size:26px;line-height:1;">' . $vis_icon . '</div>';
        $html .= '<div style="font-size:34px;line-height:1.1;font-weight:900;margin-top:6px;">' . $visibility . '</div>';
        if ( ! empty( $trend_arrow ) ) {
            $html .= '<div style="font-size:12px;color:' . $muted . ';margin-top:4px;">Trend: ' . $trend_arrow . '</div>';
        }
        $html .= '<div style="font-size:15px;font-weight:700;margin-top:4px;">Visibility Score</div>';
        $html .= '</td></tr></table>';
        $html .= '</td>';

        // Site Rank
        $html .= '<td width="50%" style="padding:10px;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $card_bg . ';border-radius:14px;border:1px solid ' . $border . ';">';
        $html .= '<tr><td align="center" style="padding:18px 10px 16px 10px;">';
        $html .= '<div style="font-size:26px;line-height:1;">' . $icon_down . '</div>';
        $html .= '<div style="font-size:34px;line-height:1.1;font-weight:900;margin-top:6px;">' . $site_rank . '</div>';
        $html .= '<div style="font-size:15px;font-weight:700;margin-top:4px;">Site Rank</div>';
        $html .= '</td></tr></table>';
        $html .= '</td>';

        $html .= '</tr>';

        // Row 2
        $html .= '<tr>';

        // AI Visits
        $html .= '<td width="50%" style="padding:10px;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $card_bg . ';border-radius:14px;border:1px solid ' . $border . ';">';
        $html .= '<tr><td align="center" style="padding:18px 10px 16px 10px;">';
        $html .= '<div style="font-size:26px;line-height:1;">' . $icon_up . '</div>';
        $html .= '<div style="font-size:34px;line-height:1.1;font-weight:900;margin-top:6px;">' . $ai_visits . '</div>';
        $html .= '<div style="font-size:15px;font-weight:700;margin-top:4px;">AI Visits</div>';
        $html .= '</td></tr></table>';
        $html .= '</td>';

        // Crawler Visits
        $html .= '<td width="50%" style="padding:10px;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $card_bg . ';border-radius:14px;border:1px solid ' . $border . ';">';
        $html .= '<tr><td align="center" style="padding:18px 10px 16px 10px;">';
        $html .= '<div style="font-size:26px;line-height:1;">' . $icon_down . '</div>';
        $html .= '<div style="font-size:34px;line-height:1.1;font-weight:900;margin-top:6px;">' . $crawler_visits . '</div>';
        $html .= '<div style="font-size:15px;font-weight:700;margin-top:4px;">Crawler Visits</div>';
        $html .= '</td></tr></table>';
        $html .= '</td>';

        $html .= '</tr>';
        $html .= '</table>';

        // CTA
        $html .= '<div style="text-align:center;margin-top:18px;font-size:12px;color:' . $text . ';">Want to improve your site rank?</div>';
        $html .= '<div style="text-align:center;margin-top:10px;">';
        $html .= '<a href="' . $upgrade_url . '" style="display:inline-block;background:' . $teal . ';color:#ffffff;text-decoration:none;font-weight:800;padding:12px 26px;border-radius:4px;font-size:14px;">Buy PRO License</a>';
        $html .= '</div>';

        $html .= '</td></tr></table>';

        // Footer spacing.
        $html .= '<table role="presentation" width="' . (int) $w . '" cellspacing="0" cellpadding="0" border="0" style="width:' . (int) $w . 'px;max-width:100%;">';
        $html .= '<tr><td style="padding:14px 0 0 0;text-align:center;font-size:11px;color:' . $muted . ';">';
        $html .= esc_html__( 'You are receiving this email because weekly reports are enabled in WPRankLab settings.', 'wpranklab' );
        $html .= '</td></tr></table>';

        $html .= '</td></tr></table>';
        $html .= '</body></html>';

        return $html;
    }
    /**
     * Ensure weekly cron event is scheduled.
     */
    public function ensure_weekly_event() {
        if ( ! wp_next_scheduled( 'wpranklab_weekly_report' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'wpranklab_weekly_report' );
        }
    }
    
    
    public function send_webhook( $snapshot = array() ) {
        
        $settings = get_option( 'wpranklab_settings', array() );
        
        if ( empty( $settings['webhook_enabled'] ) || empty( $settings['webhook_url'] ) ) {
            return;
        }
        
        $payload = array(
            'event'          => 'weekly_report',
            'site_url'       => site_url(),
            'site_name'      => get_bloginfo( 'name' ),
            'snapshot_date'  => isset( $snapshot['snapshot_date'] ) ? $snapshot['snapshot_date'] : current_time( 'mysql' ),
            'avg_score'      => isset( $snapshot['avg_score'] ) ? $snapshot['avg_score'] : null,
            'scanned_count'  => isset( $snapshot['scanned_count'] ) ? (int) $snapshot['scanned_count'] : 0,
            'plugin_version' => defined( 'WPRANKLAB_VERSION' ) ? WPRANKLAB_VERSION : '',
            'timestamp'      => time(),
        );
        
        
        $response = wp_remote_post( $settings['webhook_url'], array(
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );
        
        $settings['webhook_last_sent'] = current_time( 'mysql' );
        
        if ( is_wp_error( $response ) ) {
            $settings['webhook_last_code']  = 0;
            $settings['webhook_last_error'] = $response->get_error_message();
        } else {
            $settings['webhook_last_code']  = (int) wp_remote_retrieve_response_code( $response );
            $settings['webhook_last_error'] = '';
        }
        
        update_option( 'wpranklab_settings', $settings );
    }
    
    
    
}
