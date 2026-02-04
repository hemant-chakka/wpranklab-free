<?php
$steps = [1=>'welcome',2=>'site-basics',3=>'scan-config',4=>'email-setup',5=>'finish'];
$current = max(1, min($step, count($steps)));
?>
<div class="wprl-setup-wrapper">
  <div class="wprl-setup-progress">Step <?php echo $current; ?> of 5</div>
  <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
    <?php wp_nonce_field('wprl_setup_step_' . $current); ?>
    <input type="hidden" name="action" value="wprl_setup_save">
    <input type="hidden" name="wprl_step" value="<?php echo esc_attr($current); ?>">
    <?php require WPRL_PATH . 'includes/setup-wizard/views/step-' . $steps[$current] . '.php'; ?>
    <?php $btn = ($current===5) ? 'Finish Setup' : 'Continue'; ?>
    <p><button class="button button-primary"><?php echo esc_html($btn); ?></button></p>
  </form>
</div>
