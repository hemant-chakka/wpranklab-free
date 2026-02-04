<h1>Content Scan Setup</h1>
<p>Select which content types should be included in AI visibility scans.</p>
<table class="form-table">
<tr><th>Post Types</th><td>
<label><input type="checkbox" name="wprl_scan_posts" value="1" <?php checked(get_option('wprl_scan_posts',1),1); ?>> Posts</label><br>
<label><input type="checkbox" name="wprl_scan_pages" value="1" <?php checked(get_option('wprl_scan_pages',1),1); ?>> Pages</label>
</td></tr>
</table>
