<h1>Site Setup</h1>
<table class="form-table">
<tr><th>Site Type</th><td>
<select name="wprl_site_type">
<option value="blog">Blog</option>
<option value="business">Business</option>
<option value="ecommerce">E-commerce</option>
</select>
</td></tr>
<tr><th>Primary Language</th><td>
<input type="text" name="wprl_language" value="<?php echo esc_attr(get_option('wprl_language','English')); ?>">
</td></tr>
<tr><th>Enable AI Visibility</th><td>
<input type="checkbox" name="wprl_enable_ai" value="1" <?php checked(get_option('wprl_enable_ai'),1); ?>>
</td></tr>
</table>