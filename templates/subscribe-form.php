<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="aiem-subscribe-form" id="aiem-subscribe-<?php echo esc_attr( $list_id ); ?>">
	<?php if ( ! empty( $form_title ) ) : ?>
	<h3 class="aiem-form-title"><?php echo esc_html( $form_title ); ?></h3>
	<?php endif; ?>
	<?php if ( ! empty( $form_description ) ) : ?>
	<p class="aiem-form-description"><?php echo esc_html( $form_description ); ?></p>
	<?php endif; ?>
	<form class="aiem-form"
	      data-list-id="<?php echo esc_attr( $list_id ); ?>"
	      data-form-id="<?php echo esc_attr( $form_id ); ?>">
		<?php if ( $show_first_name || $show_last_name ) : ?>
		<div class="aiem-field-row">
			<?php if ( $show_first_name ) : ?>
			<input type="text" name="first_name" placeholder="<?php echo esc_attr( $first_name_ph ); ?>" class="aiem-input" />
			<?php endif; ?>
			<?php if ( $show_last_name ) : ?>
			<input type="text" name="last_name" placeholder="<?php echo esc_attr( $last_name_ph ); ?>" class="aiem-input" />
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<div class="aiem-hp-field" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;">
			<label>Leave this field empty<input type="text" name="aiem_hp" tabindex="-1" autocomplete="off" value="" /></label>
		</div>
		<div class="aiem-field-row">
			<input type="email" name="email" placeholder="Email address" class="aiem-input aiem-email" required />
			<button type="submit" class="aiem-btn"<?php
			$btn_style = '';
			if ( ! empty( $button_color ) )      $btn_style .= 'background:' . esc_attr( $button_color ) . ';';
			if ( ! empty( $button_text_color ) ) $btn_style .= 'color:' . esc_attr( $button_text_color ) . ';';
			if ( $btn_style ) echo ' style="' . $btn_style . '"';
		?>><?php echo esc_html( $button_text ); ?></button>
		</div>
		<?php if ( $show_gdpr ) : ?>
		<div class="aiem-gdpr-row">
			<label class="aiem-gdpr-label">
				<input type="checkbox" name="gdpr_consent" value="1" required />
				<span><?php echo wp_kses_post( $gdpr_text ); ?></span>
			</label>
		</div>
		<?php endif; ?>
		<div class="aiem-form-message" style="display:none;"></div>
	</form>
</div>
