<h1>Weekly Email Report</h1>
<p>Configure where WPRankLab should send your weekly AI Visibility update.</p>

<table class="form-table">
  <tr>
    <th><label for="wprl_report_email">Report Email Address</label></th>
    <td>
      <input type="email" class="regular-text" name="wprl_report_email" id="wprl_report_email"
        value="<?php echo esc_attr( get_option('wprl_report_email', get_option('admin_email')) ); ?>">
      <p class="description">This address will receive the weekly AI Visibility email.</p>
    </td>
  </tr>

  <tr>
    <th><label for="wprl_weekly_reports_enabled">Enable Weekly Emails</label></th>
    <td>
      <label>
        <input type="checkbox" name="wprl_weekly_reports_enabled" id="wprl_weekly_reports_enabled" value="1"
          <?php checked( (int) get_option('wprl_weekly_reports_enabled', 1), 1 ); ?>>
        Send weekly AI Visibility report emails.
      </label>
    </td>
  </tr>
</table>
