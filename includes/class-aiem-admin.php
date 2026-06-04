<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_Admin {

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_aiem_save_list',       [ $this, 'handle_save_list' ] );
		add_action( 'admin_post_aiem_delete_list',     [ $this, 'handle_delete_list' ] );
		add_action( 'admin_post_aiem_add_subscriber',  [ $this, 'handle_add_subscriber' ] );
		add_action( 'admin_post_aiem_save_settings',   [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_aiem_delete_campaign',    [ $this, 'handle_delete_campaign' ] );
		add_action( 'admin_post_aiem_duplicate_campaign', [ $this, 'handle_duplicate_campaign' ] );
		add_action( 'admin_post_aiem_purge_logs',          [ $this, 'handle_purge_logs' ] );
		add_action( 'admin_post_aiem_bulk_subscribers',    [ $this, 'handle_bulk_subscribers' ] );
		add_action( 'admin_post_aiem_export_subscribers', [ $this, 'handle_export_subscribers' ] );
	}

	public function register_menus(): void {
		add_menu_page(
			'Email Marketing',
			'Email Marketing',
			'manage_options',
			'aiem-dashboard',
			[ $this, 'page_dashboard' ],
			'dashicons-email-alt',
			30
		);

		add_submenu_page( 'aiem-dashboard', 'Dashboard',   'Dashboard',   'manage_options', 'aiem-dashboard',    [ $this, 'page_dashboard' ] );
		add_submenu_page( 'aiem-dashboard', 'Campaigns',   'Campaigns',   'manage_options', 'aiem-campaigns',    [ $this, 'page_campaigns' ] );
		add_submenu_page( 'aiem-dashboard', 'Audience',    'Audience',    'manage_options', 'aiem-audience',     [ $this, 'page_audience' ] );
		add_submenu_page( 'aiem-dashboard', 'Forms',       'Forms',       'manage_options', 'aiem-forms',        [ $this, 'page_forms' ] );
		add_submenu_page( 'aiem-dashboard', 'Workflows',   'Workflows',   'manage_options', 'aiem-workflows',    [ $this, 'page_workflows' ] );
		add_submenu_page( 'aiem-dashboard', 'Reports',     'Reports',     'manage_options', 'aiem-reports',      [ $this, 'page_reports' ] );
		add_submenu_page( 'aiem-dashboard', 'Logs',         'Logs',         'manage_options', 'aiem-logs',          [ $this, 'page_logs' ] );
		add_submenu_page( 'aiem-dashboard', 'Email Editor', 'Email Editor', 'manage_options', 'aiem-email-editor',  [ $this, 'page_email_editor' ] );
		add_submenu_page( 'aiem-dashboard', 'Settings',    'Settings',     'manage_options', 'aiem-settings',      [ $this, 'page_settings' ] );
		add_submenu_page( 'aiem-dashboard', 'Info',        'Info',         'manage_options', 'aiem-info',           [ $this, 'page_info' ] );

		add_submenu_page( null, 'Edit Campaign',       'Edit Campaign',       'manage_options', 'aiem-campaign-edit',       [ $this, 'page_campaign_edit' ] );
		add_submenu_page( null, 'Edit Form',           'Edit Form',           'manage_options', 'aiem-form-edit',           [ $this, 'page_form_edit' ] );
		add_submenu_page( null, 'Edit Email Template', 'Edit Email Template', 'manage_options', 'aiem-email-template-edit', [ $this, 'page_email_template_edit' ] );
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'aiem-' ) === false ) {
			return;
		}

		wp_enqueue_style( 'aiem-admin', AIEM_PLUGIN_URL . 'assets/css/aiem-admin.css', [], AIEM_VERSION );
		wp_enqueue_script( 'aiem-admin', AIEM_PLUGIN_URL . 'assets/js/aiem-admin.js', [ 'jquery' ], AIEM_VERSION, true );
		wp_localize_script( 'aiem-admin', 'aiemAdmin', [
			'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
			'nonce'              => wp_create_nonce( 'aiem_admin_nonce' ),
			'adminEmail'         => get_option( 'admin_email' ),
			'defaultPrompt'         => 'You are an expert email marketing copywriter. Generate ONLY the HTML email body content — no <html>, <body>, or <head> tags. Use inline CSS for all styling. Create compelling, conversion-focused copy. Structure: an attention-grabbing H1 headline, a brief intro paragraph, product highlights (if products provided), and a clear CTA button.',
			'defaultTemplatePrompt' => AIEM_OpenAI::default_template_prompt(),
		] );
	}

	// ── Pages ──────────────────────────────────────────────────────────────

	public function page_dashboard(): void {
		$stats   = AIEM_DB::get_dashboard_stats();
		$growth  = AIEM_DB::get_subscriber_growth( 30 );
		$top     = AIEM_DB::get_top_campaigns( 5 );
		$recent  = AIEM_DB::get_recent_logs( 10 );

		// Build growth data: fill in gaps for last 30 days
		$growth_by_day = [];
		foreach ( $growth as $row ) {
			$growth_by_day[ $row->day ] = (int) $row->count;
		}
		$growth_labels = [];
		$growth_values = [];
		for ( $i = 29; $i >= 0; $i-- ) {
			$day             = date( 'Y-m-d', strtotime( "-{$i} days" ) );
			$growth_labels[] = date( 'M j', strtotime( $day ) );
			$growth_values[] = $growth_by_day[ $day ] ?? 0;
		}
		$max_growth = max( array_merge( $growth_values, [ 1 ] ) );

		$stat_cards = [
			[ 'label' => 'Active Subscribers', 'value' => number_format( $stats->subscribers ),  'icon' => '👥', 'color' => 'purple' ],
			[ 'label' => 'Campaigns Sent',     'value' => number_format( $stats->campaigns_sent ),'icon' => '📨', 'color' => 'blue' ],
			[ 'label' => 'Total Emails Sent',  'value' => number_format( $stats->total_sent ),   'icon' => '✉️', 'color' => 'teal' ],
			[ 'label' => 'Avg Open Rate',       'value' => $stats->open_rate . '%',               'icon' => '👁', 'color' => 'green' ],
			[ 'label' => 'Total Clicks',        'value' => number_format( $stats->total_clicked ),'icon' => '🖱', 'color' => 'orange' ],
			[ 'label' => 'Active Workflows',    'value' => number_format( $stats->active_workflows ),'icon' => '⚡', 'color' => 'pink' ],
		];
		?>
		<div class="wrap aiem-wrap">
			<h1>Dashboard</h1>

			<div class="aiem-stat-grid">
				<?php foreach ( $stat_cards as $card ) : ?>
				<div class="aiem-stat-card aiem-stat-card--<?php echo esc_attr( $card['color'] ); ?>">
					<div class="aiem-stat-icon"><?php echo $card['icon']; ?></div>
					<div class="aiem-stat-value"><?php echo esc_html( $card['value'] ); ?></div>
					<div class="aiem-stat-label"><?php echo esc_html( $card['label'] ); ?></div>
				</div>
				<?php endforeach; ?>
			</div>

			<div class="aiem-dashboard-grid">
				<div class="aiem-panel aiem-panel--grow">
					<h3>Subscriber Growth — Last 30 Days</h3>
					<div class="aiem-bar-chart">
						<?php foreach ( array_slice( $growth_values, -14 ) as $i => $val ) :
							$pct   = $max_growth > 0 ? round( $val / $max_growth * 100 ) : 0;
							$label = $growth_labels[ count( $growth_values ) - 14 + $i ];
						?>
						<div class="aiem-bar-col">
							<div class="aiem-bar-tip"><?php echo $val; ?></div>
							<div class="aiem-bar" style="height:<?php echo $pct; ?>%"></div>
							<div class="aiem-bar-label"><?php echo esc_html( $label ); ?></div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="aiem-panel">
					<h3>Top Campaigns by Open Rate</h3>
					<?php if ( empty( $top ) ) : ?>
						<p style="color:#9ca3af;">No sent campaigns yet.</p>
					<?php else : ?>
					<table class="aiem-panel-table">
						<thead><tr><th>Campaign</th><th>Sent</th><th>Open %</th></tr></thead>
						<tbody>
						<?php foreach ( $top as $c ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-reports&campaign_id=' . $c->id ) ); ?>"><?php echo esc_html( $c->name ); ?></a></td>
								<td><?php echo number_format( $c->total_sent ); ?></td>
								<td><span class="aiem-badge aiem-status-subscribed"><?php echo esc_html( $c->open_rate ); ?>%</span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="aiem-panel">
				<h3>Recent Activity</h3>
				<?php if ( empty( $recent ) ) : ?>
					<p style="color:#9ca3af;">No activity logged yet.</p>
				<?php else : ?>
				<table class="wp-list-table widefat fixed striped aiem-table">
					<thead><tr><th style="width:130px">Event</th><th>Subscriber</th><th>Campaign</th><th style="width:160px">Time</th></tr></thead>
					<tbody>
					<?php foreach ( $recent as $log ) : ?>
						<tr>
							<td><?php echo $this->event_badge( $log->event_type ); ?></td>
							<td><?php echo esc_html( $log->subscriber_email ?? '—' ); ?></td>
							<td><?php echo esc_html( $log->campaign_name ?? '—' ); ?></td>
							<td style="color:#6b7280;font-size:12px"><?php echo esc_html( $log->created_at ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
				<p style="margin-top:8px"><a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-logs' ) ); ?>">View all logs →</a></p>
			</div>
		</div>
		<?php
	}

	public function page_forms(): void {
		$forms = AIEM_DB::get_forms();
		$lists = AIEM_DB::get_lists();
		?>
		<div class="wrap aiem-wrap">
			<h1>Forms <a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-form-edit' ) ); ?>" class="page-title-action">+ New Form</a></h1>
			<?php $this->show_notice(); ?>

			<?php if ( empty( $forms ) ) : ?>
				<p>No forms yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-form-edit' ) ); ?>">Create your first form</a>.</p>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped aiem-table">
				<thead>
					<tr><th>Name</th><th>List</th><th>Fields</th><th>Shortcode</th><th>Created</th><th>Actions</th></tr>
				</thead>
				<tbody>
				<?php foreach ( $forms as $f ) :
					$fields   = json_decode( $f->fields, true ) ?: [];
					$f_labels = [];
					$f_labels[] = 'Email';
					foreach ( $fields as $field ) {
						$type = $field['type'] ?? '';
						if ( $type === 'first_name' ) $f_labels[] = 'First Name';
						if ( $type === 'last_name' )  $f_labels[] = 'Last Name';
						if ( $type === 'gdpr' )       $f_labels[] = 'GDPR';
					}
					$shortcode = '[aiem_subscribe form_id="' . (int) $f->id . '"]';
				?>
					<tr id="aiem-form-row-<?php echo (int) $f->id; ?>">
						<td><strong><?php echo esc_html( $f->name ); ?></strong></td>
						<td><?php echo esc_html( $f->list_name ?? "List #{$f->list_id}" ); ?></td>
						<td><span style="font-size:12px;color:#6b7280"><?php echo esc_html( implode( ', ', $f_labels ) ); ?></span></td>
						<td>
							<code style="font-size:11px"><?php echo esc_html( $shortcode ); ?></code>
							<button type="button" class="button-link aiem-copy-form-sc" data-shortcode="<?php echo esc_attr( $shortcode ); ?>" style="margin-left:6px;font-size:12px">Copy</button>
						</td>
						<td><?php echo esc_html( date( 'M j, Y', strtotime( $f->created_at ) ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-form-edit&form_id=' . $f->id ) ); ?>">Edit</a>
							&nbsp;|&nbsp;
							<button type="button" class="button-link aiem-danger aiem-form-delete" data-id="<?php echo (int) $f->id; ?>">Delete</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function page_form_edit(): void {
		$form_id = (int) ( $_GET['form_id'] ?? 0 );
		$form    = $form_id ? AIEM_DB::get_form( $form_id ) : null;
		$lists   = AIEM_DB::get_lists();

		$fields   = $form ? ( json_decode( $form->fields, true ) ?: [] ) : [];
		$settings = $form ? ( json_decode( $form->settings, true ) ?: [] ) : [];

		// Parse field config
		$fn_enabled = false; $fn_ph = 'First name';
		$ln_enabled = false; $ln_ph = 'Last name';
		$gdpr_enabled = false; $gdpr_text = 'I agree to receive email updates.';
		foreach ( $fields as $field ) {
			$type = $field['type'] ?? '';
			if ( $type === 'first_name' ) { $fn_enabled = true;   $fn_ph    = $field['placeholder'] ?? $fn_ph; }
			if ( $type === 'last_name' )  { $ln_enabled = true;   $ln_ph    = $field['placeholder'] ?? $ln_ph; }
			if ( $type === 'gdpr' )       { $gdpr_enabled = true; $gdpr_text = $field['text'] ?? $gdpr_text; }
		}

		$btn_text    = $settings['button_text']      ?? 'Subscribe';
		$success_msg = $settings['success_message']  ?? "Thanks! You've been subscribed.";
		$form_title  = $settings['title']            ?? '';
		$form_desc   = $settings['description']      ?? '';
		$btn_color   = $settings['button_color']     ?? '';
		$btn_txt_col = $settings['button_text_color'] ?? '';

		$shortcode = $form_id ? '[aiem_subscribe form_id="' . $form_id . '"]' : '';
		?>
		<div class="wrap aiem-wrap">
			<h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-forms' ) ); ?>" style="font-size:14px;font-weight:400;vertical-align:middle;margin-right:8px">← Forms</a>
				<?php echo $form ? 'Edit Form: ' . esc_html( $form->name ) : 'New Form'; ?>
			</h1>
			<?php $this->show_notice(); ?>

			<div style="max-width:720px">
				<input type="hidden" id="aiem-form-id" value="<?php echo $form_id; ?>" />

				<div class="aiem-panel">
					<h3>Form Details</h3>
					<table class="form-table aiem-form-table">
						<tr>
							<th>Form Name <span class="aiem-required">*</span></th>
							<td><input type="text" id="aiem-form-name" class="regular-text" value="<?php echo esc_attr( $form->name ?? '' ); ?>" placeholder="e.g. Newsletter Signup" /></td>
						</tr>
						<tr>
							<th>Subscriber List <span class="aiem-required">*</span></th>
							<td>
								<select id="aiem-form-list-id" style="min-width:220px">
									<option value="">— Select List —</option>
									<?php foreach ( $lists as $l ) : ?>
										<option value="<?php echo (int) $l->id; ?>" <?php selected( (int) ( $form->list_id ?? 0 ), (int) $l->id ); ?>>
											<?php echo esc_html( $l->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( empty( $lists ) ) : ?>
									<p class="description"><a href="<?php echo esc_url( admin_url('admin.php?page=aiem-audience') ); ?>">Create a list first</a></p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>

				<div class="aiem-panel">
					<h3>Fields</h3>
					<p class="description" style="margin-bottom:16px">Email is always collected. Toggle additional fields below.</p>
					<table class="form-table aiem-form-table">
						<tr>
							<th>Email</th>
							<td><span class="aiem-badge aiem-status-subscribed">Always shown</span> <span class="description">Required — cannot be removed.</span></td>
						</tr>
						<tr>
							<th>First Name</th>
							<td>
								<label>
									<input type="checkbox" id="aiem-field-first-name" <?php checked( $fn_enabled ); ?> />
									Show field
								</label>
								<span class="aiem-field-placeholder-wrap" style="<?php echo $fn_enabled ? '' : 'display:none'; ?>">
									&nbsp;&nbsp;Placeholder:
									<input type="text" id="aiem-fn-placeholder" value="<?php echo esc_attr( $fn_ph ); ?>" class="regular-text" />
								</span>
							</td>
						</tr>
						<tr>
							<th>Last Name</th>
							<td>
								<label>
									<input type="checkbox" id="aiem-field-last-name" <?php checked( $ln_enabled ); ?> />
									Show field
								</label>
								<span class="aiem-field-placeholder-wrap" style="<?php echo $ln_enabled ? '' : 'display:none'; ?>">
									&nbsp;&nbsp;Placeholder:
									<input type="text" id="aiem-ln-placeholder" value="<?php echo esc_attr( $ln_ph ); ?>" class="regular-text" />
								</span>
							</td>
						</tr>
						<tr>
							<th>GDPR Checkbox</th>
							<td>
								<label>
									<input type="checkbox" id="aiem-field-gdpr" <?php checked( $gdpr_enabled ); ?> />
									Show GDPR consent checkbox (required to submit)
								</label>
								<div id="aiem-gdpr-text-wrap" style="<?php echo $gdpr_enabled ? '' : 'display:none'; ?>margin-top:8px">
									<label class="aiem-wf-field-label">Consent text</label>
									<textarea id="aiem-gdpr-text" rows="2" class="large-text"><?php echo esc_textarea( $gdpr_text ); ?></textarea>
								</div>
							</td>
						</tr>
					</table>
				</div>

				<div class="aiem-panel">
					<h3>Settings</h3>
					<table class="form-table aiem-form-table">
						<tr>
							<th>Button Text</th>
							<td><input type="text" id="aiem-form-btn-text" class="regular-text" value="<?php echo esc_attr( $btn_text ); ?>" /></td>
						</tr>
						<tr>
							<th>Success Message</th>
							<td><input type="text" id="aiem-form-success-msg" class="large-text" value="<?php echo esc_attr( $success_msg ); ?>" /></td>
						</tr>
						<tr>
							<th>Form Title <span style="font-weight:400;color:#6b7280">(optional)</span></th>
							<td><input type="text" id="aiem-form-title" class="regular-text" value="<?php echo esc_attr( $form_title ); ?>" placeholder="e.g. Join our newsletter" /></td>
						</tr>
						<tr>
							<th>Description <span style="font-weight:400;color:#6b7280">(optional)</span></th>
							<td><input type="text" id="aiem-form-desc" class="large-text" value="<?php echo esc_attr( $form_desc ); ?>" placeholder="e.g. Get updates delivered to your inbox." /></td>
						</tr>
						<tr>
							<th>Button Background <span style="font-weight:400;color:#6b7280">(optional)</span></th>
							<td>
								<input type="color" id="aiem-form-btn-color" value="<?php echo esc_attr( $btn_color ?: '#7c3aed' ); ?>" />
								<label style="margin-left:8px;font-size:13px">
									<input type="checkbox" id="aiem-form-btn-color-enabled" <?php checked( ! empty( $btn_color ) ); ?> />
									Override default
								</label>
							</td>
						</tr>
						<tr>
							<th>Button Text Color <span style="font-weight:400;color:#6b7280">(optional)</span></th>
							<td>
								<input type="color" id="aiem-form-btn-txt-color" value="<?php echo esc_attr( $btn_txt_col ?: '#ffffff' ); ?>" />
								<label style="margin-left:8px;font-size:13px">
									<input type="checkbox" id="aiem-form-btn-txt-color-enabled" <?php checked( ! empty( $btn_txt_col ) ); ?> />
									Override default
								</label>
							</td>
						</tr>
					</table>
				</div>

				<?php if ( $shortcode ) : ?>
				<div class="aiem-panel" style="background:#f9fafb">
					<strong>Shortcode:</strong>
					<code id="aiem-form-shortcode-display"><?php echo esc_html( $shortcode ); ?></code>
					<button type="button" class="button button-small" id="aiem-form-copy-sc" data-shortcode="<?php echo esc_attr( $shortcode ); ?>" style="margin-left:8px">Copy</button>
				</div>
				<?php endif; ?>

				<div style="margin-top:8px">
					<button type="button" id="aiem-form-save-btn" class="button button-primary">
						<?php echo $form ? 'Update Form' : 'Create Form'; ?>
					</button>
					<span id="aiem-form-save-result" style="margin-left:10px;font-size:13px"></span>
				</div>
			</div>
		</div>
		<?php
	}

	public function page_email_editor(): void {
		$templates = AIEM_DB::get_email_templates();
		?>
		<div class="wrap aiem-wrap">
			<h1>Email Editor <a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-email-template-edit' ) ); ?>" class="page-title-action">+ New Template</a></h1>
			<?php $this->show_notice(); ?>

			<?php if ( empty( $templates ) ) : ?>
				<p>No templates yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-email-template-edit' ) ); ?>">Create your first template</a>.</p>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped aiem-table">
				<thead>
					<tr><th>Name</th><th>Created</th><th>Actions</th></tr>
				</thead>
				<tbody>
				<?php foreach ( $templates as $t ) : ?>
					<tr id="aiem-tpl-row-<?php echo (int) $t->id; ?>">
						<td><strong><?php echo esc_html( $t->name ); ?></strong></td>
						<td><?php echo esc_html( date( 'M j, Y', strtotime( $t->created_at ) ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-email-template-edit&template_id=' . $t->id ) ); ?>">Edit</a>
							&nbsp;|&nbsp;
							<button type="button" class="button-link aiem-danger aiem-tpl-delete" data-id="<?php echo (int) $t->id; ?>">Delete</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function page_email_template_edit(): void {
		$template_id = (int) ( $_GET['template_id'] ?? 0 );
		$template    = $template_id ? AIEM_DB::get_email_template( $template_id ) : null;
		$blocks_json = $template->blocks ?? '[]';
		$html        = $template->html_content ?? '';
		?>
		<div class="wrap aiem-wrap">
			<h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-email-editor' ) ); ?>" style="font-size:14px;font-weight:400;vertical-align:middle;margin-right:8px">← Email Editor</a>
				<?php echo $template ? 'Edit Template: ' . esc_html( $template->name ) : 'New Template'; ?>
			</h1>
			<?php $this->show_notice(); ?>

			<div style="max-width:900px">
				<input type="hidden" id="aiem-template-id" value="<?php echo $template_id; ?>" />

				<div style="margin-bottom:12px;display:flex;align-items:center;gap:12px">
					<input type="text" id="aiem-template-name" class="regular-text" style="font-size:15px;padding:8px 10px;min-width:320px"
					       value="<?php echo esc_attr( $template->name ?? '' ); ?>"
					       placeholder="Template name, e.g. Welcome Email" />
					<button type="button" id="aiem-template-save-btn" class="button button-primary">
						<?php echo $template ? 'Update Template' : 'Save Template'; ?>
					</button>
					<span id="aiem-template-save-result" style="font-size:13px"></span>
				</div>

				<div class="aiem-editor-box">
					<div class="aiem-editor-tabs">
						<button type="button" class="aiem-editor-tab aiem-editor-tab--visual" id="aiem-tab-visual">Visual</button>
						<button type="button" class="aiem-editor-tab aiem-editor-tab--html"   id="aiem-tab-html">HTML</button>
					</div>

					<div id="aiem-visual-editor">
						<div class="aiem-block-palette">
							<span class="aiem-palette-label">Add block:</span>
							<button type="button" class="aiem-palette-btn" data-block="heading">H Heading</button>
							<button type="button" class="aiem-palette-btn" data-block="text">T Text</button>
							<button type="button" class="aiem-palette-btn" data-block="button">⬛ Button</button>
							<button type="button" class="aiem-palette-btn" data-block="image">🖼 Image</button>
							<button type="button" class="aiem-palette-btn" data-block="divider">— Divider</button>
							<button type="button" class="aiem-palette-btn" data-block="spacer">↕ Spacer</button>
						</div>
						<div class="aiem-block-editor-wrap">
							<div class="aiem-block-canvas-wrap">
								<div id="aiem-block-canvas">
									<p class="aiem-canvas-empty">Click a block type above to add it.</p>
								</div>
							</div>
							<div class="aiem-block-props-wrap">
								<div class="aiem-block-props-header">Properties</div>
								<div id="aiem-block-props">
									<p style="color:#9ca3af;font-size:13px">Click a block to edit its properties.</p>
								</div>
							</div>
						</div>
					</div>

					<div id="aiem-html-editor-wrap" style="display:none">
						<p class="description">Edit HTML directly. Merge tags: <code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{email}}</code> <code>{{site_name}}</code> <code>{{unsubscribe_url}}</code></p>
						<textarea id="aiem-html-content" rows="22" class="large-text code aiem-html-editor"><?php echo esc_textarea( $html ); ?></textarea>
					</div>

					<div style="margin-top:10px">
						<button type="button" id="aiem-preview-btn" class="button">Preview</button>
					</div>
					<div id="aiem-preview-container" style="display:none;margin-top:8px">
						<iframe id="aiem-preview-frame" style="width:100%;height:500px;border:1px solid #ddd;border-radius:4px;"></iframe>
					</div>
				</div>

				<input type="hidden" id="aiem-template-blocks-json" value="<?php echo esc_attr( $blocks_json ); ?>" />
			</div>
		</div>
		<?php
	}

	public function page_workflows(): void {
		$workflows = AIEM_DB::get_workflows();
		$campaigns = AIEM_DB::get_campaigns();
		$lists     = AIEM_DB::get_lists();

		$trigger_labels = [
			'subscriber_added'        => 'Subscriber Added',
			'subscriber_unsubscribed' => 'Subscriber Unsubscribed',
			'campaign_sent'           => 'Campaign Sent',
			'post_published'          => 'Post Published',
		];
		$categories = get_categories( [ 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
		?>
		<div class="wrap aiem-wrap">
			<h1>Workflows <button type="button" id="aiem-process-queue-btn" class="button" style="margin-left:12px;font-size:13px;">Process Queue Now</button> <span id="aiem-process-queue-result" style="font-size:13px;margin-left:8px;color:#555;"></span></h1>
			<?php $this->show_notice(); ?>

			<div class="aiem-two-col">
				<div>
					<h3 id="wf-form-heading">Create Workflow</h3>

					<input type="hidden" id="wf-id" value="0" />
					<input type="text" id="wf-name" class="regular-text" placeholder="e.g. Welcome New Subscriber" style="width:100%;margin-bottom:12px;font-size:15px;padding:8px 10px;" />

					<div class="aiem-wf-section">
						<div class="aiem-wf-section-header"><span>Trigger</span></div>
						<div class="aiem-wf-section-body">
							<label class="aiem-wf-field-label">Trigger <span class="aiem-required">*</span></label>
							<select id="wf-trigger" style="min-width:220px">
								<option value="">— Select trigger —</option>
								<?php foreach ( $trigger_labels as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p id="wf-trigger-desc" class="description" style="margin-top:6px"></p>
						</div>
					</div>

					<div class="aiem-wf-section">
						<div class="aiem-wf-section-header"><span>Rules</span></div>
						<div class="aiem-wf-section-body">
							<p id="wf-no-rules" class="description" style="margin:0">Select a trigger first to see available rules.</p>

							<div id="wf-category-wrap" style="display:none">
								<div style="margin-bottom:12px">
									<label class="aiem-wf-field-label">Category <span style="font-weight:400;color:#6b7280">(optional)</span></label>
									<select id="wf-category-id" style="min-width:220px">
										<option value="">All categories</option>
										<?php foreach ( $categories as $cat ) : ?>
											<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description" style="margin-top:4px">Leave blank to fire for every new post.</p>
								</div>
								<div>
									<label class="aiem-wf-field-label">List <span class="aiem-required">*</span></label>
									<select id="wf-action-list-id" style="min-width:220px">
										<option value="">— Select list —</option>
										<?php foreach ( $lists as $lst ) : ?>
											<option value="<?php echo (int) $lst->id; ?>"><?php echo esc_html( $lst->name ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description" style="margin-top:4px">Which subscriber list to notify when a post is published.</p>
								</div>
							</div>

							<div id="wf-filter-campaign-wrap" style="display:none">
								<label class="aiem-wf-field-label">Campaign <span style="font-weight:400;color:#6b7280">(optional)</span></label>
								<select id="wf-filter-campaign-id" style="min-width:220px">
									<option value="0">Any campaign</option>
									<?php foreach ( $campaigns as $c ) : ?>
										<option value="<?php echo (int) $c->id; ?>"><?php echo esc_html( $c->name ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description" style="margin-top:4px">Leave on "Any campaign" to fire for every campaign send.</p>
							</div>
						</div>
					</div>

					<div class="aiem-wf-section">
						<div class="aiem-wf-section-header"><span>Actions</span></div>
						<div class="aiem-wf-section-body">
							<div class="aiem-wf-action-row">
								<div class="aiem-wf-action-label">Email &ndash; Send Email</div>
								<div class="aiem-wf-action-fields">

									<div style="margin-bottom:12px">
										<label class="aiem-wf-field-label">Action <span class="aiem-required">*</span></label>
										<select id="wf-action-type" style="min-width:220px">
											<option value="send_email">Send Email</option>
										</select>
									</div>

									<div style="margin-bottom:12px">
										<label class="aiem-wf-field-label">Send to <span class="aiem-required">*</span></label>
										<input type="text" id="wf-send-to" value="{{EMAIL}}" class="regular-text" style="width:100%" />
										<p class="description" style="margin-top:4px">Use <code>{{EMAIL}}</code> for the subscriber&rsquo;s address, or enter a fixed email address. Multiple addresses separated by commas.</p>
									</div>

									<div style="margin-bottom:12px">
										<label class="aiem-wf-field-label">Email subject <span class="aiem-required">*</span></label>
										<input type="text" id="wf-subject" class="regular-text" style="width:100%" placeholder="e.g. Welcome to {{site_name}}!" />
									</div>

									<div style="margin-bottom:12px">
										<label class="aiem-wf-field-label">Email styling <span class="aiem-required">*</span></label>
										<select id="wf-email-styling" style="min-width:220px">
											<option value="none">None</option>
											<option value="default">Default template</option>
										</select>
										<p class="description" style="margin-top:4px">Select which style to use when formatting the email.</p>
									</div>

									<div style="margin-bottom:12px">
										<label class="aiem-wf-field-label">Email content <span class="aiem-required">*</span></label>
										<textarea id="wf-email-content" rows="8" style="width:100%;font-family:monospace;font-size:12px;line-height:1.5" placeholder="Enter email HTML or plain text…"></textarea>
										<p class="description" id="wf-content-vars" style="margin-top:4px">Variables: <code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{email}}</code> <code>{{site_name}}</code> <code>{{site_url}}</code> <code>{{DATE}}</code></p>
									</div>

									<div>
										<label class="aiem-wf-field-label">Delay</label>
										<div style="display:flex;gap:6px;align-items:center">
											<input type="number" id="wf-delay-value" value="0" min="0" class="small-text" />
											<select id="wf-delay-unit">
												<option value="minutes">Minutes</option>
												<option value="hours">Hours</option>
												<option value="days">Days</option>
											</select>
										</div>
										<p class="description" style="margin-top:4px">0 = send immediately after trigger fires.</p>
									</div>

								</div>
							</div>
						</div>
					</div>

					<div style="margin-top:4px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
						<button type="button" id="wf-save-btn" class="button button-primary">Create Workflow</button>
						<button type="button" id="wf-cancel-btn" class="button" style="display:none">Cancel</button>
						<span id="wf-save-result" style="font-size:13px;"></span>
					</div>
				</div>

				<div>
					<h3>Active Workflows</h3>
					<?php if ( empty( $workflows ) ) : ?>
						<p>No workflows yet. Create one on the left.</p>
					<?php else : ?>
					<table class="wp-list-table widefat fixed striped aiem-table">
						<thead>
							<tr><th>Name</th><th>Trigger</th><th>Subject</th><th>Delay</th><th>Status</th><th>Actions</th></tr>
						</thead>
						<tbody>
						<?php foreach ( $workflows as $wf ) : ?>
							<tr id="wf-row-<?php echo (int) $wf->id; ?>">
								<td><strong><?php echo esc_html( $wf->name ); ?></strong></td>
								<td>
									<?php
									echo esc_html( $trigger_labels[ $wf->trigger_type ] ?? $wf->trigger_type );
									if ( $wf->trigger_type === 'post_published' ) {
										$cfg    = json_decode( $wf->trigger_config ?? '{}', true );
										$cat_id = (int) ( $cfg['category_id'] ?? 0 );
										if ( $cat_id ) {
											$cat = get_category( $cat_id );
											echo ' <span style="color:#6b7280;font-size:12px">(' . esc_html( $cat->name ?? "cat #{$cat_id}" ) . ')</span>';
										} else {
											echo ' <span style="color:#6b7280;font-size:12px">(all categories)</span>';
										}
									} elseif ( $wf->trigger_type === 'campaign_sent' ) {
										$cfg             = json_decode( $wf->trigger_config ?? '{}', true );
										$filter_campaign = (int) ( $cfg['filter_campaign_id'] ?? 0 );
										if ( $filter_campaign ) {
											$fc = AIEM_DB::get_campaign( $filter_campaign );
											echo ' <span style="color:#6b7280;font-size:12px">(' . esc_html( $fc->name ?? "campaign #{$filter_campaign}" ) . ')</span>';
										} else {
											echo ' <span style="color:#6b7280;font-size:12px">(any campaign)</span>';
										}
									}
									?>
								</td>
								<td><?php echo esc_html( $wf->action_subject ?: '—' ); ?></td>
								<td>
									<?php if ( (int) $wf->delay_value > 0 ) : ?>
										<?php echo (int) $wf->delay_value; ?> <?php echo esc_html( $wf->delay_unit ); ?>
									<?php else : ?>
										Immediate
									<?php endif; ?>
								</td>
								<td>
									<span class="aiem-badge aiem-status-<?php echo $wf->status === 'active' ? 'subscribed' : 'unsubscribed'; ?>">
										<?php echo esc_html( $wf->status ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button-link aiem-wf-edit" data-wf="<?php echo esc_attr( wp_json_encode( [
										'id'                   => (int) $wf->id,
										'name'                 => $wf->name,
										'trigger_type'         => $wf->trigger_type,
										'trigger_config'       => json_decode( $wf->trigger_config ?? '{}', true ),
										'action_send_to'       => $wf->action_send_to,
										'action_subject'       => $wf->action_subject,
										'action_content'       => $wf->action_content,
										'action_email_styling' => $wf->action_email_styling,
										'action_list_id'       => (int) $wf->action_list_id,
										'delay_value'          => (int) $wf->delay_value,
										'delay_unit'           => $wf->delay_unit,
									] ) ); ?>">Edit</button>
									&nbsp;|&nbsp;
									<button type="button" class="button-link aiem-wf-toggle" data-id="<?php echo (int) $wf->id; ?>" data-status="<?php echo esc_attr( $wf->status ); ?>">
										<?php echo $wf->status === 'active' ? 'Pause' : 'Activate'; ?>
									</button>
									&nbsp;|&nbsp;
									<button type="button" class="button-link aiem-danger aiem-wf-delete" data-id="<?php echo (int) $wf->id; ?>">Delete</button>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function page_logs(): void {
		$event_filter = sanitize_text_field( $_GET['event_type'] ?? '' );
		$page         = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page     = 50;
		$logs         = AIEM_DB::get_logs( $event_filter, $page, $per_page );
		$total        = AIEM_DB::count_logs( $event_filter );

		$event_types = [
			''                  => 'All Events',
			'subscribe'         => 'Subscribe',
			'confirm'           => 'Confirm',
			'unsubscribe'       => 'Unsubscribe',
			'open'              => 'Open',
			'click'             => 'Click',
			'send'              => 'Send',
			'send_failed'       => 'Send Failed',
			'bounce'            => 'Bounce',
			'workflow_queued'   => 'Workflow Queued',
			'workflow_sent'     => 'Workflow Sent',
			'workflow_failed'   => 'Workflow Failed',
		];
		?>
		<div class="wrap aiem-wrap">
			<h1>Logs</h1>

			<form method="get" style="margin-bottom:16px">
				<input type="hidden" name="page" value="aiem-logs" />
				<select name="event_type" onchange="this.form.submit()">
					<?php foreach ( $event_types as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $event_filter, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span style="margin-left:8px;color:#6b7280;font-size:13px"><?php echo number_format( $total ); ?> entries</span>
			</form>

			<?php if ( empty( $logs ) ) : ?>
				<p>No log entries yet.</p>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped aiem-table">
				<thead>
					<tr>
						<th style="width:140px">Event</th>
						<th>Subscriber</th>
						<th>Campaign</th>
						<th>Details</th>
						<th style="width:60px">IP</th>
						<th style="width:160px">Time</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $logs as $log ) :
					$details = json_decode( $log->details, true );
					unset( $details['subscriber_id'], $details['campaign_id'], $details['send_id'] );
				?>
					<tr>
						<td><?php echo $this->event_badge( $log->event_type ); ?></td>
						<td><?php echo esc_html( $log->subscriber_email ?? '—' ); ?></td>
						<td><?php echo esc_html( $log->campaign_name ?? '—' ); ?></td>
						<td style="font-size:12px;color:#6b7280">
							<?php if ( ! empty( $details ) ) :
								$parts = [];
								foreach ( $details as $k => $v ) {
									if ( is_scalar( $v ) ) {
										$parts[] = esc_html( $k ) . ': ' . esc_html( $v );
									}
								}
								echo implode( ', ', $parts );
							endif; ?>
						</td>
						<td style="font-size:11px;color:#9ca3af"><?php echo esc_html( $log->ip_address ); ?></td>
						<td style="font-size:12px;color:#6b7280"><?php echo esc_html( $log->created_at ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->pagination( $total, $per_page, $page, [ 'page' => 'aiem-logs', 'event_type' => $event_filter ] ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function page_campaigns(): void {
		$campaigns = AIEM_DB::get_campaigns();
		$lists     = AIEM_DB::get_lists();
		?>
		<div class="wrap aiem-wrap">
			<h1>Campaigns <a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-campaign-edit' ) ); ?>" class="page-title-action">+ New Campaign</a></h1>
			<?php $this->show_notice(); ?>

			<?php if ( empty( $campaigns ) ) : ?>
				<p>No campaigns yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-campaign-edit' ) ); ?>">Create your first campaign</a>.</p>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped aiem-table">
				<thead>
					<tr>
						<th>Name</th><th>Subject</th><th>List</th><th>Status</th><th>Scheduled For</th><th>Created</th><th>Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $campaigns as $c ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $c->name ); ?></strong></td>
						<td><?php echo esc_html( $c->subject ); ?></td>
						<td><?php echo esc_html( $c->list_name ?? '—' ); ?></td>
						<td><span class="aiem-badge aiem-status-<?php echo esc_attr( $c->status ); ?>"><?php echo esc_html( $c->status ); ?></span></td>
						<td style="white-space:nowrap;color:#374151;font-size:13px">
							<?php
							if ( $c->status === 'scheduled' && ! empty( $c->scheduled_at ) ) {
								$ts = strtotime( $c->scheduled_at );
								echo esc_html( wp_date( 'M j, Y g:i a', $ts ) );
								if ( $ts < current_time( 'timestamp' ) ) {
									echo ' <span style="color:#ef4444;font-size:11px">(overdue)</span>';
								}
							} else {
								echo '—';
							}
							?>
						</td>
						<td><?php echo esc_html( date( 'M j, Y', strtotime( $c->created_at ) ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-campaign-edit&campaign_id=' . $c->id ) ); ?>">Edit</a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-reports&campaign_id=' . $c->id ) ); ?>">Report</a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aiem_duplicate_campaign&campaign_id=' . $c->id ), 'aiem_duplicate_campaign_' . $c->id ) ); ?>">Duplicate</a>
							<?php if ( $c->status === 'scheduled' ) : ?>
							&nbsp;|&nbsp;
							<a href="#" class="aiem-send-now-link" data-campaign="<?php echo (int) $c->id; ?>">Send Now</a>
							<?php endif; ?>
							<?php if ( in_array( $c->status, [ 'sent', 'sending' ], true ) ) : ?>
							&nbsp;|&nbsp;
							<a href="#" class="aiem-resend-link" data-campaign="<?php echo (int) $c->id; ?>">Re-send</a>
							<?php endif; ?>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aiem_delete_campaign&campaign_id=' . $c->id ), 'aiem_delete_campaign_' . $c->id ) ); ?>"
							   onclick="return confirm('Delete this campaign?');" class="aiem-danger">Delete</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function page_campaign_edit(): void {
		$campaign_id   = (int) ( $_GET['campaign_id'] ?? 0 );
		$campaign      = $campaign_id ? AIEM_DB::get_campaign( $campaign_id ) : null;
		$lists         = AIEM_DB::get_lists();
		$segments      = AIEM_DB::get_segments();
		$templates     = AIEM_DB::get_email_templates();
		$segment_id    = (int) ( $campaign->segment_id ?? 0 );
		$audience_mode = $segment_id ? 'segment' : 'list';
		$woo_active    = function_exists( 'wc_get_products' );
		?>
		<div class="wrap aiem-wrap">
			<h1><?php echo $campaign ? 'Edit Campaign: ' . esc_html( $campaign->name ) : 'New Campaign'; ?></h1>
			<?php $this->show_notice(); ?>

			<div class="aiem-campaign-editor">
				<div class="aiem-campaign-main">
					<table class="form-table aiem-form-table">
						<tr>
							<th>Campaign Name</th>
							<td><input type="text" id="aiem-name" class="regular-text" value="<?php echo esc_attr( $campaign->name ?? '' ); ?>" /></td>
						</tr>
						<tr>
							<th>Subject Line</th>
							<td><input type="text" id="aiem-subject" class="regular-text" value="<?php echo esc_attr( $campaign->subject ?? '' ); ?>" /></td>
						</tr>
						<tr>
							<th>Preview Text</th>
							<td>
								<input type="text" id="aiem-preheader" class="regular-text" value="<?php echo esc_attr( $campaign->preheader ?? '' ); ?>" placeholder="Short summary shown in inbox before opening…" style="width:100%;max-width:500px;" />
								<p class="description">Appears after the subject line in most email clients. Keep under 100 characters.</p>
							</td>
						</tr>
						<tr>
							<th>Audience</th>
							<td>
								<div style="margin-bottom:8px">
									<label style="margin-right:16px;font-weight:normal">
										<input type="radio" name="aiem-audience-mode" value="list" <?php checked( $audience_mode, 'list' ); ?> />
										All subscribers in list
									</label>
									<label style="font-weight:normal">
										<input type="radio" name="aiem-audience-mode" value="segment" <?php checked( $audience_mode, 'segment' ); ?> />
										Segment
									</label>
								</div>
								<div id="aiem-list-wrap" <?php echo $audience_mode === 'segment' ? 'style="display:none"' : ''; ?>>
									<select id="aiem-list-id">
										<option value="">— Select List —</option>
										<?php foreach ( $lists as $l ) : ?>
											<option value="<?php echo (int) $l->id; ?>" <?php selected( (int) ( $campaign->list_id ?? 0 ), (int) $l->id ); ?>>
												<?php echo esc_html( $l->name ); ?> (<?php echo (int) $l->subscriber_count; ?> subscribers)
											</option>
										<?php endforeach; ?>
									</select>
								</div>
								<div id="aiem-segment-wrap" <?php echo $audience_mode === 'list' ? 'style="display:none"' : ''; ?>>
									<select id="aiem-segment-id">
										<option value="">— Select Segment —</option>
										<?php foreach ( $segments as $seg ) : ?>
											<option value="<?php echo (int) $seg->id; ?>" <?php selected( (int) ( $campaign->segment_id ?? 0 ), (int) $seg->id ); ?>>
												<?php echo esc_html( $seg->name ); ?> (<?php echo esc_html( $seg->list_name ?? '' ); ?>)
											</option>
										<?php endforeach; ?>
									</select>
									<?php if ( empty( $segments ) ) : ?>
										<p class="description" style="margin-top:4px"><a href="<?php echo esc_url( admin_url('admin.php?page=aiem-audience') ); ?>">Create a segment first</a></p>
									<?php endif; ?>
								</div>
							</td>
						</tr>
						<tr>
							<th>From Name</th>
							<td><input type="text" id="aiem-from-name" class="regular-text" value="<?php echo esc_attr( $campaign->from_name ?? get_bloginfo( 'name' ) ); ?>" /></td>
						</tr>
						<tr>
							<th>From Email</th>
							<td><input type="email" id="aiem-from-email" class="regular-text" value="<?php echo esc_attr( $campaign->from_email ?? get_option( 'admin_email' ) ); ?>" /></td>
						</tr>
					</table>

					<div class="aiem-ai-box">
						<h3>AI Content Generator</h3>
						<p>Describe what this email should accomplish, and AI will generate the HTML content.</p>
						<textarea id="aiem-ai-prompt" rows="4" class="large-text" placeholder="e.g. Promote our summer sale with 20% off all products. Create urgency. Target existing customers."><?php echo esc_textarea( $campaign->ai_prompt ?? '' ); ?></textarea>
						<?php if ( $woo_active ) :
							$woo_cats        = AIEM_WooCommerce::get_product_categories();
							$woo_tags_list   = AIEM_WooCommerce::get_product_tags();
							$camp_cat_ids    = json_decode( $campaign->woo_category_ids ?? '[]', true ) ?: [];
							$camp_tag_ids    = json_decode( $campaign->woo_tag_ids ?? '[]', true ) ?: [];
							$global_cat_ids  = json_decode( get_option( 'aiem_woo_categories', '[]' ), true ) ?: [];
							$global_tag_ids  = json_decode( get_option( 'aiem_woo_tags', '[]' ), true ) ?: [];
						?>
							<label class="aiem-checkbox">
								<input type="checkbox" id="aiem-use-woo" checked />
								Include WooCommerce products (last <?php echo (int) get_option( 'aiem_product_count', 5 ); ?>)
							</label>
							<div id="aiem-woo-filters" style="margin-top:10px;padding:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;display:flex;gap:24px;flex-wrap:wrap;">
								<?php if ( $woo_cats ) : ?>
								<div>
									<label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">
										Filter by category
										<?php if ( $global_cat_ids ) : ?>
											<span style="font-weight:400;color:#6b7280">(global: <?php
												$names = [];
												foreach ( $woo_cats as $t ) {
													if ( in_array( (int) $t->term_id, array_map( 'intval', $global_cat_ids ), true ) ) $names[] = esc_html( $t->name );
												}
												echo implode( ', ', $names );
											?>)</span>
										<?php endif; ?>
									</label>
									<select id="aiem-woo-category-ids" multiple size="4" style="min-width:180px">
										<?php foreach ( $woo_cats as $term ) : ?>
											<option value="<?php echo (int) $term->term_id; ?>" <?php echo in_array( (int) $term->term_id, array_map( 'intval', $camp_cat_ids ), true ) ? 'selected' : ''; ?>>
												<?php echo esc_html( $term->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p style="font-size:11px;color:#9ca3af;margin:3px 0 0">Override global default. None = use global.</p>
								</div>
								<?php endif; ?>
								<?php if ( $woo_tags_list ) : ?>
								<div>
									<label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">
										Filter by tag
										<?php if ( $global_tag_ids ) : ?>
											<span style="font-weight:400;color:#6b7280">(global: <?php
												$names = [];
												foreach ( $woo_tags_list as $t ) {
													if ( in_array( (int) $t->term_id, array_map( 'intval', $global_tag_ids ), true ) ) $names[] = esc_html( $t->name );
												}
												echo implode( ', ', $names );
											?>)</span>
										<?php endif; ?>
									</label>
									<select id="aiem-woo-tag-ids" multiple size="4" style="min-width:180px">
										<?php foreach ( $woo_tags_list as $term ) : ?>
											<option value="<?php echo (int) $term->term_id; ?>" <?php echo in_array( (int) $term->term_id, array_map( 'intval', $camp_tag_ids ), true ) ? 'selected' : ''; ?>>
												<?php echo esc_html( $term->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p style="font-size:11px;color:#9ca3af;margin:3px 0 0">Override global default. None = use global.</p>
								</div>
								<?php endif; ?>
							</div>
						<?php endif; ?>
						<br />
						<button type="button" id="aiem-generate-btn" class="button button-primary">Generate with AI</button>
						<button type="button" id="aiem-regen-subject-btn" class="button" style="margin-left:8px;" title="Regenerate subject &amp; preview text only — leaves email body untouched">↻ Subject &amp; Preview Only</button>
						<span id="aiem-generate-spinner" class="aiem-spinner" style="display:none;"></span>
						<span id="aiem-generate-status"></span>
					</div>

					<div class="aiem-editor-box">
						<?php if ( ! empty( $templates ) ) : ?>
						<div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;padding-bottom:12px;border-bottom:1px solid #e5e7eb">
							<label style="font-size:13px;font-weight:600;color:#374151;white-space:nowrap">Load from Email Editor:</label>
							<select id="aiem-load-template" style="min-width:200px">
								<option value="">— Select template —</option>
								<?php foreach ( $templates as $t ) : ?>
									<option value="<?php echo (int) $t->id; ?>" data-html="<?php echo esc_attr( $t->html_content ); ?>" <?php selected( $campaign ? (int) $campaign->template_id : 0, (int) $t->id ); ?>>
										<?php echo esc_html( $t->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="button" id="aiem-apply-template" class="button">Load</button>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-email-editor' ) ); ?>" style="font-size:12px;color:#6b7280">Open Email Editor →</a>
						</div>
						<?php else : ?>
						<p style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-email-editor' ) ); ?>">Build a template in Email Editor</a> and load it here.
						</p>
						<?php endif; ?>

						<h3>Email HTML Content</h3>
						<p class="description">Edit the generated HTML, or write your own. Placeholders: <code>{{content}}</code>, <code>{{unsubscribe_url}}</code>, <code>{{site_name}}</code></p>
						<textarea id="aiem-html-content" rows="20" class="large-text code aiem-html-editor"><?php echo esc_textarea( $campaign->html_content ?? '' ); ?></textarea>

						<div style="margin-top:10px">
							<button type="button" id="aiem-preview-btn" class="button">Preview</button>
						</div>
						<div id="aiem-preview-container" style="display:none;margin-top:8px">
							<iframe id="aiem-preview-frame" style="width:100%;height:500px;border:1px solid #ddd;border-radius:4px;"></iframe>
						</div>
					</div>

					<div class="aiem-action-bar">
						<button type="button" id="aiem-save-btn" class="button button-secondary">Save Draft</button>
						<button type="button" id="aiem-test-send-btn" class="button">Send Test Email</button>
						<button type="button" id="aiem-send-btn" class="button button-primary">Send Now</button>
						<span class="aiem-schedule-wrap">
							<input type="datetime-local" id="aiem-scheduled-at" value="<?php echo esc_attr( ! empty( $campaign->scheduled_at ) ? date( 'Y-m-d\TH:i', strtotime( $campaign->scheduled_at ) ) : '' ); ?>" />
							<button type="button" id="aiem-schedule-btn" class="button">Schedule</button>
						</span>
						<span class="aiem-recur-wrap" style="margin-left:12px;">
							<label for="aiem-recur-schedule" style="font-size:13px;color:#374151;margin-right:4px;">Repeat:</label>
							<select id="aiem-recur-schedule">
								<option value="" <?php selected( $campaign->recur_schedule ?? '', '' ); ?>>None</option>
								<option value="daily" <?php selected( $campaign->recur_schedule ?? '', 'daily' ); ?>>Daily</option>
								<option value="weekly" <?php selected( $campaign->recur_schedule ?? '', 'weekly' ); ?>>Weekly</option>
								<option value="monthly" <?php selected( $campaign->recur_schedule ?? '', 'monthly' ); ?>>Monthly</option>
							</select>
						</span>
					</div>
					<div id="aiem-action-result"></div>
				</div>
			</div>

			<input type="hidden" id="aiem-campaign-id" value="<?php echo $campaign_id; ?>" />
		</div>
		<?php
	}

	public function page_audience(): void {
		$list_id = (int) ( $_GET['list_id'] ?? 0 );
		$lists   = AIEM_DB::get_lists();

		if ( $list_id ) {
			// Drill-down: subscribers for a specific list
			$current_list = null;
			foreach ( $lists as $l ) {
				if ( (int) $l->id === $list_id ) {
					$current_list = $l;
					break;
				}
			}
			$page        = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
			$subscribers = AIEM_DB::get_subscribers( $list_id, $page, 50 );
			$total       = AIEM_DB::count_subscribers( $list_id, '' );
			?>
			<div class="wrap aiem-wrap">
				<h1>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-audience' ) ); ?>" style="font-size:14px;font-weight:400;vertical-align:middle;margin-right:8px;">← Lists</a>
					<?php echo esc_html( $current_list->name ?? 'List' ); ?>
					<span style="font-size:14px;font-weight:400;color:#6b7280;margin-left:8px;"><?php echo number_format( $total ); ?> subscribers</span>
				</h1>
				<?php $this->show_notice(); ?>

				<div class="aiem-row">
					<div class="aiem-col aiem-col-actions">
						<div id="aiem-import-box">
							<strong>Import CSV</strong>
							<input type="file" id="aiem-import-file" accept=".csv" />
							<button type="button" id="aiem-import-btn" class="button" data-list-id="<?php echo $list_id; ?>">Import</button>
							<span id="aiem-import-result"></span>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'aiem_export_subscribers' ); ?>
							<input type="hidden" name="action" value="aiem_export_subscribers" />
							<input type="hidden" name="list_id" value="<?php echo $list_id; ?>" />
							<button type="submit" class="button">Export CSV</button>
						</form>

						<div id="aiem-add-subscriber-box">
							<strong>Add Subscriber</strong>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'aiem_add_subscriber' ); ?>
								<input type="hidden" name="action" value="aiem_add_subscriber" />
								<input type="hidden" name="list_id" value="<?php echo $list_id; ?>" />
								<input type="text" name="first_name" placeholder="First name" class="small-text" />
								<input type="text" name="last_name" placeholder="Last name" class="small-text" />
								<input type="email" name="email" placeholder="Email address" class="regular-text" required />
								<button type="submit" class="button">Add</button>
							</form>
						</div>
					</div>
				</div>

				<?php if ( ! empty( $subscribers ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="aiem-bulk-form">
					<?php wp_nonce_field( 'aiem_bulk_subscribers' ); ?>
					<input type="hidden" name="action" value="aiem_bulk_subscribers" />
					<input type="hidden" name="list_id" value="<?php echo $list_id; ?>" />

					<div class="aiem-bulk-bar">
						<select name="bulk_action">
							<option value="">— Bulk action —</option>
							<option value="unsubscribe">Unsubscribe selected</option>
							<option value="delete">Delete selected</option>
						</select>
						<button type="submit" class="button" id="aiem-bulk-apply">Apply</button>
					</div>

					<table class="wp-list-table widefat fixed striped aiem-table">
						<thead>
							<tr>
								<th style="width:32px"><input type="checkbox" id="aiem-select-all" /></th>
								<th>Email</th><th>Name</th><th>Status</th><th>Source</th><th>Subscribed</th><th>Last Open</th><th>Last Click</th><th>Actions</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $subscribers as $s ) : ?>
							<tr>
								<td><input type="checkbox" name="subscriber_ids[]" value="<?php echo (int) $s->id; ?>" class="aiem-sub-cb" /></td>
								<td><?php echo esc_html( $s->email ); ?></td>
								<td><?php echo esc_html( trim( $s->first_name . ' ' . $s->last_name ) ); ?></td>
								<td><span class="aiem-badge aiem-status-<?php echo esc_attr( $s->status ); ?>"><?php echo esc_html( $s->status ); ?></span></td>
								<td><?php echo esc_html( $s->source ); ?></td>
								<td><?php echo esc_html( $s->subscribed_at ? date( 'M j, Y', strtotime( $s->subscribed_at ) ) : '—' ); ?></td>
								<td><?php echo esc_html( $s->last_opened_at ? date( 'M j, Y', strtotime( $s->last_opened_at ) ) : '—' ); ?></td>
								<td><?php echo esc_html( $s->last_clicked_at ? date( 'M j, Y', strtotime( $s->last_clicked_at ) ) : '—' ); ?></td>
								<td>
									<button type="button" class="button-link aiem-danger aiem-delete-subscriber" data-id="<?php echo (int) $s->id; ?>">Delete</button>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</form>
				<?php $this->pagination( $total, 50, $page, [ 'page' => 'aiem-audience', 'list_id' => $list_id ] ); ?>
				<?php else : ?>
					<p>No subscribers on this list yet.</p>
				<?php endif; ?>
			</div>
			<?php
		} else {
			// Lists management view
			$segments = AIEM_DB::get_segments();
			?>
			<div class="wrap aiem-wrap">
				<h1>Audience</h1>
				<?php $this->show_notice(); ?>

				<div class="aiem-two-col">
					<div>
						<h3>Create New List</h3>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'aiem_save_list' ); ?>
							<input type="hidden" name="action" value="aiem_save_list" />
							<table class="form-table">
								<tr><th>Name</th><td><input type="text" name="list_name" class="regular-text" required /></td></tr>
								<tr><th>Description</th><td><textarea name="list_description" rows="3" class="regular-text"></textarea></td></tr>
							</table>
							<p><button type="submit" class="button button-primary">Create List</button></p>
						</form>
					</div>

					<div>
						<h3>Lists</h3>
						<?php if ( empty( $lists ) ) : ?>
							<p>No lists yet. Create one to get started.</p>
						<?php else : ?>
						<table class="wp-list-table widefat fixed striped aiem-table">
							<thead><tr><th>Name</th><th>Subscribers</th><th>Created</th><th>Actions</th></tr></thead>
							<tbody>
							<?php foreach ( $lists as $l ) : ?>
								<tr>
									<td>
										<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-audience&list_id=' . $l->id ) ); ?>"><?php echo esc_html( $l->name ); ?></a></strong>
										<?php if ( $l->description ) : ?>
											<br/><small><?php echo esc_html( $l->description ); ?></small>
										<?php endif; ?>
									</td>
									<td><?php echo (int) $l->subscriber_count; ?></td>
									<td><?php echo esc_html( date( 'M j, Y', strtotime( $l->created_at ) ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiem-audience&list_id=' . $l->id ) ); ?>">View</a>
										&nbsp;|&nbsp;
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aiem_delete_list&list_id=' . $l->id ), 'aiem_delete_list_' . $l->id ) ); ?>"
										   onclick="return confirm('Delete list and ALL subscribers?');" class="aiem-danger">Delete</a>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php endif; ?>
					</div>
				</div>

				<hr style="margin:32px 0;border:none;border-top:1px solid #e5e7eb;" />

				<h2 style="margin-bottom:16px">Segments</h2>
				<div class="aiem-two-col">
					<div>
						<h3>Create Segment</h3>

						<input type="hidden" id="seg-id" value="0" />

						<div class="aiem-panel" style="margin-bottom:0">
							<div style="margin-bottom:12px">
								<label class="aiem-wf-field-label">Segment Name <span class="aiem-required">*</span></label>
								<input type="text" id="seg-name" class="regular-text" style="width:100%" placeholder="e.g. Active Openers" />
							</div>

							<div style="margin-bottom:16px">
								<label class="aiem-wf-field-label">List <span class="aiem-required">*</span></label>
								<select id="seg-list-id" style="min-width:220px">
									<option value="">— Select list —</option>
									<?php foreach ( $lists as $l ) : ?>
										<option value="<?php echo (int) $l->id; ?>"><?php echo esc_html( $l->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>

							<div style="margin-bottom:12px">
								<label class="aiem-wf-field-label">Conditions</label>
								<div id="seg-conditions"></div>
								<button type="button" id="seg-add-condition" class="button" style="margin-top:6px">+ Add Condition</button>
							</div>

							<div style="margin-bottom:16px;padding:10px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;">
								<span id="seg-preview-text" style="color:#6b7280">Set a list and click Preview to count matching subscribers.</span>
								<button type="button" id="seg-preview-btn" class="button button-small" style="margin-left:10px">Preview</button>
							</div>

							<button type="button" id="seg-save-btn" class="button button-primary">Create Segment</button>
							<span id="seg-save-result" style="margin-left:10px;font-size:13px;"></span>
						</div>
					</div>

					<div>
						<h3>Saved Segments</h3>
						<?php if ( empty( $segments ) ) : ?>
							<p>No segments yet. Create one on the left.</p>
						<?php else : ?>
						<table class="wp-list-table widefat fixed striped aiem-table">
							<thead>
								<tr><th>Name</th><th>List</th><th>Conditions</th><th>Actions</th></tr>
							</thead>
							<tbody>
							<?php foreach ( $segments as $seg ) :
								$filters = json_decode( $seg->filters, true ) ?: [];
								$condition_summaries = [];
								$eng_labels = [
									'opened_any'   => 'Has opened any campaign',
									'never_opened' => 'Has never opened',
									'clicked_any'  => 'Has clicked any campaign',
									'never_clicked'=> 'Has never clicked',
								];
								foreach ( $filters as $f ) {
									switch ( $f['type'] ?? '' ) {
										case 'status':
											$condition_summaries[] = 'Status = ' . esc_html( $f['value'] ?? '' );
											break;
										case 'engagement':
											$condition_summaries[] = esc_html( $eng_labels[ $f['operator'] ?? '' ] ?? ( $f['operator'] ?? '' ) );
											break;
										case 'subscribed_after':
											$condition_summaries[] = 'Subscribed after ' . esc_html( $f['value'] ?? '' );
											break;
										case 'subscribed_before':
											$condition_summaries[] = 'Subscribed before ' . esc_html( $f['value'] ?? '' );
											break;
									}
								}
							?>
								<tr id="seg-row-<?php echo (int) $seg->id; ?>">
									<td><strong><?php echo esc_html( $seg->name ); ?></strong></td>
									<td><?php echo esc_html( $seg->list_name ?? "List #{$seg->list_id}" ); ?></td>
									<td>
										<?php if ( empty( $condition_summaries ) ) : ?>
											<span style="color:#9ca3af;font-size:12px">No conditions (all active)</span>
										<?php else : ?>
											<?php echo implode( '<br>', $condition_summaries ); ?>
										<?php endif; ?>
									</td>
									<td>
										<button type="button" class="button-link aiem-danger aiem-seg-delete" data-id="<?php echo (int) $seg->id; ?>">Delete</button>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<script>
			var aiemSegLists = <?php echo wp_json_encode( array_map( fn($l) => ['id' => (int)$l->id, 'name' => $l->name], $lists ) ); ?>;
			</script>
			<?php
		}
	}

	public function page_reports(): void {
		$campaigns   = AIEM_DB::get_campaigns();
		$campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );
		$campaign    = $campaign_id ? AIEM_DB::get_campaign( $campaign_id ) : null;
		$stats       = $campaign_id ? AIEM_DB::get_campaign_stats( $campaign_id ) : null;
		$sends       = $campaign_id ? AIEM_DB::get_sends_for_campaign( $campaign_id ) : [];
		$click_links = $campaign_id ? AIEM_DB::get_click_breakdown( $campaign_id ) : [];
		?>
		<div class="wrap aiem-wrap">
			<h1>Reports</h1>

			<form method="get">
				<input type="hidden" name="page" value="aiem-reports" />
				<select name="campaign_id" onchange="this.form.submit()">
					<option value="">— Select Campaign —</option>
					<?php foreach ( $campaigns as $c ) : ?>
						<option value="<?php echo (int) $c->id; ?>" <?php selected( $campaign_id, (int) $c->id ); ?>>
							<?php echo esc_html( $c->name ); ?> — <?php echo esc_html( $c->status ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</form>

			<?php if ( $campaign && $stats ) : ?>
			<div class="aiem-stats-grid">
				<?php
				$open_rate   = $stats->sent > 0 ? round( $stats->opened / $stats->sent * 100, 1 ) : 0;
				$click_rate  = $stats->sent > 0 ? round( $stats->clicked / $stats->sent * 100, 1 ) : 0;
				$unsub_count = $stats->unsubscribed ?? 0;
				$unsub_rate  = $stats->sent > 0 ? round( $unsub_count / $stats->sent * 100, 1 ) : 0;
				$stat_items = [
					'Total'       => $stats->total,
					'Sent'        => $stats->sent,
					'Failed'      => $stats->failed,
					'Bounced'     => $stats->bounced ?? 0,
					'Opens'       => $stats->opened,
					'Open Rate'   => $open_rate . '%',
					'Clicks'      => $stats->clicked,
					'Click Rate'  => $click_rate . '%',
					'Unsubs'      => $unsub_count,
					'Unsub Rate'  => $unsub_rate . '%',
				];
				foreach ( $stat_items as $label => $value ) : ?>
					<div class="aiem-stat-card">
						<div class="aiem-stat-value"><?php echo esc_html( $value ); ?></div>
						<div class="aiem-stat-label"><?php echo esc_html( $label ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $campaign->status === 'sent' ) :
				$non_opener_count = AIEM_DB::count_non_openers( $campaign_id );
				if ( $non_opener_count > 0 ) : ?>
			<div style="margin:16px 0;">
				<button id="aiem-resend-non-openers" class="button button-primary"
					data-campaign="<?php echo (int) $campaign_id; ?>">
					Resend to non-openers (<?php echo (int) $non_opener_count; ?>)
				</button>
			</div>
			<?php endif; endif; ?>

			<?php if ( ! empty( $sends ) ) : ?>
			<h3>Individual Sends</h3>
			<table class="wp-list-table widefat fixed striped aiem-table">
				<thead>
					<tr><th>Email</th><th>Status</th><th>Sent</th><th>Opens</th><th>Clicked</th></tr>
				</thead>
				<tbody>
				<?php foreach ( $sends as $s ) : ?>
					<tr>
						<td><?php echo esc_html( $s->email ); ?></td>
						<td><span class="aiem-badge aiem-status-<?php echo esc_attr( $s->status ); ?>"><?php echo esc_html( $s->status ); ?></span></td>
						<td><?php echo $s->sent_at ? esc_html( date( 'M j g:ia', strtotime( $s->sent_at ) ) ) : '—'; ?></td>
						<td><?php echo (int) $s->open_count; ?></td>
						<td><?php echo $s->clicked_at ? '<span class="aiem-badge aiem-status-subscribed">Yes</span>' : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php if ( ! empty( $click_links ) ) : ?>
			<h3>Link Clicks</h3>
			<table class="wp-list-table widefat fixed striped aiem-table">
				<thead>
					<tr><th>URL</th><th style="width:80px">Clicks</th></tr>
				</thead>
				<tbody>
				<?php foreach ( $click_links as $link ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $link->url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link->url ); ?></a></td>
						<td><?php echo (int) $link->clicks; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	public function page_settings(): void {
		?>
		<div class="wrap aiem-wrap">
			<h1>Settings</h1>
			<?php $this->show_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'aiem_save_settings' ); ?>
				<input type="hidden" name="action" value="aiem_save_settings" />

				<h2 class="title">OpenAI</h2>
				<table class="form-table">
					<tr>
						<th>API Key</th>
						<td>
							<input type="password" name="aiem_openai_key" value="<?php echo esc_attr( get_option( 'aiem_openai_key', '' ) ); ?>" class="regular-text" autocomplete="off" />
							<p class="description">Your OpenAI API key. Stored securely in WP options.</p>
						</td>
					</tr>
					<tr>
						<th>Model</th>
						<td>
							<select name="aiem_openai_model">
								<?php foreach ( [ 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo' ] as $m ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>" <?php selected( get_option( 'aiem_openai_model', 'gpt-4o' ), $m ); ?>><?php echo esc_html( $m ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th>Max Output Tokens</th>
						<td>
							<input type="number" name="aiem_max_tokens" value="<?php echo (int) get_option( 'aiem_max_tokens', 2500 ); ?>" min="500" max="8000" class="small-text" />
							<p class="description">Default: 2500. When generating from a template, this is raised to 4000 automatically if below that.</p>
						</td>
					</tr>
					<tr>
						<th>System Prompt</th>
						<td>
							<textarea id="aiem-system-prompt" name="aiem_system_prompt" rows="5" class="large-text"><?php echo esc_textarea( get_option( 'aiem_system_prompt', 'You are an expert email marketing copywriter. Generate ONLY the HTML email body content — no <html>, <body>, or <head> tags. Use inline CSS for all styling. Create compelling, conversion-focused copy. Structure: an attention-grabbing H1 headline, a brief intro paragraph, product highlights (if products provided), and a clear CTA button.' ) ); ?></textarea>
							<p class="description">Used when generating without a template. <button type="button" class="button button-small" id="aiem-reset-prompt" style="margin-left:8px;">Reset to Default</button></p>
						</td>
					</tr>
					<tr>
						<th>Template System Prompt</th>
						<td>
							<textarea id="aiem-template-system-prompt" name="aiem_template_system_prompt" rows="8" class="large-text"><?php echo esc_textarea( get_option( 'aiem_template_system_prompt', AIEM_OpenAI::default_template_prompt() ) ); ?></textarea>
							<p class="description">Used when generating with an Email Editor template selected. <button type="button" class="button button-small" id="aiem-reset-template-prompt" style="margin-left:8px;">Reset to Default</button></p>
						</td>
					</tr>
				</table>

				<h2 class="title">Sending Defaults</h2>
				<table class="form-table">
					<tr>
						<th>Default From Name</th>
						<td><input type="text" name="aiem_from_name" value="<?php echo esc_attr( get_option( 'aiem_from_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>Default From Email</th>
						<td><input type="email" name="aiem_from_email" value="<?php echo esc_attr( get_option( 'aiem_from_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>Default List</th>
						<td>
							<select name="aiem_default_list">
								<option value="">— None —</option>
								<?php foreach ( AIEM_DB::get_lists() as $l ) : ?>
									<option value="<?php echo (int) $l->id; ?>" <?php selected( get_option( 'aiem_default_list', 0 ), (int) $l->id ); ?>><?php echo esc_html( $l->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<h2 class="title">Subscriptions</h2>
				<table class="form-table">
					<tr>
						<th>Double Opt-in</th>
						<td>
							<label>
								<input type="checkbox" name="aiem_double_optin" value="1" <?php checked( get_option( 'aiem_double_optin', '0' ), '1' ); ?> />
								Require email confirmation before subscriber is activated
							</label>
							<p class="description">When enabled, new subscribers receive a confirmation email and stay <em>unconfirmed</em> until they click the link. Workflows fire only after confirmation.</p>
						</td>
					</tr>
					<tr>
						<th>Bounce Threshold</th>
						<td>
							<input type="number" name="aiem_bounce_threshold" value="<?php echo (int) get_option( 'aiem_bounce_threshold', 3 ); ?>" min="1" max="20" class="small-text" />
							<p class="description">After this many failed sends to the same address, the subscriber is marked <em>bounced</em> and excluded from future campaigns.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Sending</h2>
				<table class="form-table">
					<tr>
						<th>Batch size</th>
						<td>
							<input type="number" name="aiem_batch_size" value="<?php echo (int) get_option( 'aiem_batch_size', 50 ); ?>" min="1" max="500" class="small-text" />
							<p class="description">Emails sent per batch. Lower = less server load; higher = faster delivery.</p>
						</td>
					</tr>
					<tr>
						<th>Delay between batches</th>
						<td>
							<input type="number" name="aiem_batch_delay" value="<?php echo (int) get_option( 'aiem_batch_delay', 5 ); ?>" min="1" max="300" class="small-text" /> seconds
							<p class="description">Pause between batches. Increase if your host throttles outgoing mail.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">WooCommerce</h2>
				<table class="form-table">
					<tr>
						<th>Products to include</th>
						<td>
							<input type="number" name="aiem_product_count" value="<?php echo (int) get_option( 'aiem_product_count', 5 ); ?>" min="1" max="20" class="small-text" />
							<p class="description">Number of recent WooCommerce products to pull into AI prompts.</p>
						</td>
					</tr>
					<?php if ( function_exists( 'wc_get_products' ) ) :
						$woo_cats     = AIEM_WooCommerce::get_product_categories();
						$woo_tags     = AIEM_WooCommerce::get_product_tags();
						$saved_cats   = json_decode( get_option( 'aiem_woo_categories', '[]' ), true ) ?: [];
						$saved_tags   = json_decode( get_option( 'aiem_woo_tags', '[]' ), true ) ?: [];
					?>
					<tr>
						<th>Default categories</th>
						<td>
							<?php if ( $woo_cats ) : ?>
							<select name="aiem_woo_categories[]" multiple size="5" style="min-width:220px">
								<?php foreach ( $woo_cats as $term ) : ?>
									<option value="<?php echo (int) $term->term_id; ?>" <?php echo in_array( (int) $term->term_id, array_map( 'intval', $saved_cats ), true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( $term->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Hold Ctrl/Cmd to select multiple. Leave all unselected to include all categories.</p>
							<?php else : ?>
							<p class="description">No product categories found.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>Default tags</th>
						<td>
							<?php if ( $woo_tags ) : ?>
							<select name="aiem_woo_tags[]" multiple size="5" style="min-width:220px">
								<?php foreach ( $woo_tags as $term ) : ?>
									<option value="<?php echo (int) $term->term_id; ?>" <?php echo in_array( (int) $term->term_id, array_map( 'intval', $saved_tags ), true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( $term->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Hold Ctrl/Cmd to select multiple. Leave all unselected to include all tags.</p>
							<?php else : ?>
							<p class="description">No product tags found.</p>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>
				</table>

				<p class="submit"><button type="submit" class="button button-primary">Save Settings</button></p>
			</form>

			<hr />
			<h2 class="title">Maintenance</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete all log entries older than the specified number of days?');">
				<?php wp_nonce_field( 'aiem_purge_logs' ); ?>
				<input type="hidden" name="action" value="aiem_purge_logs" />
				<table class="form-table">
					<tr>
						<th>Purge old logs</th>
						<td>
							<input type="number" name="aiem_purge_days" value="30" min="1" max="3650" class="small-text" /> days
							<button type="submit" class="button button-secondary" style="margin-left:8px;">Purge Now</button>
							<p class="description">Delete log entries older than this many days. The logs table grows indefinitely; purge periodically to keep it manageable.</p>
						</td>
					</tr>
				</table>
			</form>
		</div>
		<?php
	}

	// ── POST handlers ──────────────────────────────────────────────────────

	public function handle_save_list(): void {
		check_admin_referer( 'aiem_save_list' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$name = sanitize_text_field( $_POST['list_name'] ?? '' );
		$desc = sanitize_textarea_field( $_POST['list_description'] ?? '' );

		if ( $name ) {
			AIEM_DB::create_list( $name, $desc );
			$this->redirect_with_notice( admin_url( 'admin.php?page=aiem-audience' ), 'List created.' );
		} else {
			$this->redirect_with_notice( admin_url( 'admin.php?page=aiem-audience' ), 'Name is required.', 'error' );
		}
	}

	public function handle_delete_list(): void {
		$list_id = (int) ( $_GET['list_id'] ?? 0 );
		check_admin_referer( 'aiem_delete_list_' . $list_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		AIEM_DB::delete_list( $list_id );
		$this->redirect_with_notice( admin_url( 'admin.php?page=aiem-audience' ), 'List deleted.' );
	}

	public function handle_add_subscriber(): void {
		check_admin_referer( 'aiem_add_subscriber' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$list_id = (int) ( $_POST['list_id'] ?? 0 );
		$email   = sanitize_email( $_POST['email'] ?? '' );
		$back    = admin_url( 'admin.php?page=aiem-audience&list_id=' . $list_id );

		if ( ! is_email( $email ) ) {
			$this->redirect_with_notice( $back, 'Invalid email address.', 'error' );
		}

		$result = AIEM_DB::insert_subscriber( [
			'list_id'    => $list_id,
			'email'      => $email,
			'first_name' => sanitize_text_field( $_POST['first_name'] ?? '' ),
			'last_name'  => sanitize_text_field( $_POST['last_name'] ?? '' ),
			'source'     => 'manual',
		] );

		if ( $result ) {
			$this->redirect_with_notice( $back, 'Subscriber added.' );
		} else {
			$this->redirect_with_notice( $back, 'Email already on this list.', 'error' );
		}
	}

	public function handle_delete_campaign(): void {
		$campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );
		check_admin_referer( 'aiem_delete_campaign_' . $campaign_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		AIEM_DB::delete_campaign( $campaign_id );
		$this->redirect_with_notice( admin_url( 'admin.php?page=aiem-campaigns' ), 'Campaign deleted.' );
	}

	public function handle_duplicate_campaign(): void {
		$campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );
		check_admin_referer( 'aiem_duplicate_campaign_' . $campaign_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$new_id = AIEM_DB::duplicate_campaign( $campaign_id );
		if ( $new_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=aiem-campaign-edit&campaign_id=' . $new_id ) );
		} else {
			$this->redirect_with_notice( admin_url( 'admin.php?page=aiem-campaigns' ), 'Could not duplicate campaign.' );
		}
		exit;
	}

	public function handle_export_subscribers(): void {
		check_admin_referer( 'aiem_export_subscribers' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$list_id = (int) ( $_POST['list_id'] ?? 0 );
		$_GET['list_id'] = $list_id;

		( new AIEM_Ajax() )->export_subscribers();
	}

	public function handle_bulk_subscribers(): void {
		check_admin_referer( 'aiem_bulk_subscribers' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$list_id = (int) ( $_POST['list_id'] ?? 0 );
		$bulk    = sanitize_text_field( $_POST['bulk_action'] ?? '' );
		$ids     = array_map( 'intval', (array) ( $_POST['subscriber_ids'] ?? [] ) );
		$back    = admin_url( 'admin.php?page=aiem-audience&list_id=' . $list_id );

		if ( empty( $ids ) ) {
			$this->redirect_with_notice( $back, 'No subscribers selected.', 'error' );
		}

		if ( $bulk === 'unsubscribe' ) {
			$count = AIEM_DB::bulk_unsubscribe_subscribers( $ids );
			$this->redirect_with_notice( $back, "Unsubscribed {$count} subscriber(s)." );
		} elseif ( $bulk === 'delete' ) {
			$count = AIEM_DB::bulk_delete_subscribers( $ids );
			$this->redirect_with_notice( $back, "Deleted {$count} subscriber(s)." );
		} else {
			$this->redirect_with_notice( $back, 'Unknown action.', 'error' );
		}
	}

	public function handle_purge_logs(): void {
		check_admin_referer( 'aiem_purge_logs' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$days    = max( 1, (int) ( $_POST['aiem_purge_days'] ?? 30 ) );
		$deleted = AIEM_DB::purge_logs( $days );
		$this->redirect_with_notice( admin_url( 'admin.php?page=aiem-settings' ), "Deleted {$deleted} log entries older than {$days} days." );
	}

	public function handle_save_settings(): void {
		check_admin_referer( 'aiem_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$woo_cats_raw = isset( $_POST['aiem_woo_categories'] ) && is_array( $_POST['aiem_woo_categories'] )
			? array_map( 'intval', $_POST['aiem_woo_categories'] )
			: [];
		$woo_tags_raw = isset( $_POST['aiem_woo_tags'] ) && is_array( $_POST['aiem_woo_tags'] )
			? array_map( 'intval', $_POST['aiem_woo_tags'] )
			: [];

		$options = [
			'aiem_openai_key'       => sanitize_text_field( $_POST['aiem_openai_key'] ?? '' ),
			'aiem_openai_model'     => sanitize_text_field( $_POST['aiem_openai_model'] ?? 'gpt-4o' ),
			'aiem_max_tokens'              => max( 500, min( 8000, (int) ( $_POST['aiem_max_tokens'] ?? 2500 ) ) ),
			'aiem_system_prompt'           => sanitize_textarea_field( $_POST['aiem_system_prompt'] ?? '' ),
			'aiem_template_system_prompt'  => sanitize_textarea_field( $_POST['aiem_template_system_prompt'] ?? '' ),
			'aiem_from_name'        => sanitize_text_field( $_POST['aiem_from_name'] ?? '' ),
			'aiem_from_email'       => sanitize_email( $_POST['aiem_from_email'] ?? '' ),
			'aiem_default_list'     => (int) ( $_POST['aiem_default_list'] ?? 0 ),
			'aiem_product_count'    => max( 1, (int) ( $_POST['aiem_product_count'] ?? 5 ) ),
			'aiem_double_optin'     => isset( $_POST['aiem_double_optin'] ) ? '1' : '0',
			'aiem_bounce_threshold' => max( 1, (int) ( $_POST['aiem_bounce_threshold'] ?? 3 ) ),
			'aiem_batch_size'       => max( 1, min( 500, (int) ( $_POST['aiem_batch_size'] ?? 50 ) ) ),
			'aiem_batch_delay'      => max( 1, min( 300, (int) ( $_POST['aiem_batch_delay'] ?? 5 ) ) ),
			'aiem_woo_categories'   => wp_json_encode( $woo_cats_raw ),
			'aiem_woo_tags'         => wp_json_encode( $woo_tags_raw ),
		];

		foreach ( $options as $key => $value ) {
			update_option( $key, $value );
		}

		$this->redirect_with_notice( admin_url( 'admin.php?page=aiem-settings' ), 'Settings saved.' );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private function redirect_with_notice( string $url, string $message, string $type = 'success' ): void {
		set_transient( 'aiem_admin_notice', [ 'message' => $message, 'type' => $type ], 30 );
		wp_safe_redirect( $url );
		exit;
	}

	private function show_notice(): void {
		$notice = get_transient( 'aiem_admin_notice' );
		if ( $notice ) {
			delete_transient( 'aiem_admin_notice' );
			$class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	private function event_badge( string $event_type ): string {
		$map = [
			'subscribe'        => [ 'subscribed',   'Subscribe' ],
			'unsubscribe'      => [ 'unsubscribed',  'Unsubscribe' ],
			'open'             => [ 'scheduled',     'Open' ],
			'click'            => [ 'sending',       'Click' ],
			'send'             => [ 'sent',          'Send' ],
			'send_failed'      => [ 'failed',        'Failed' ],
			'bounce'           => [ 'bounced',       'Bounce' ],
			'confirm'          => [ 'subscribed',    'Confirm' ],
			'workflow_queued'  => [ 'draft',         'WF Queued' ],
			'workflow_sent'    => [ 'sent',          'WF Sent' ],
			'workflow_failed'  => [ 'failed',        'WF Failed' ],
		];
		[ $class, $label ] = $map[ $event_type ] ?? [ 'draft', $event_type ];
		return '<span class="aiem-badge aiem-status-' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	public function page_info(): void {
		?>
		<div class="wrap aiem-wrap">
			<h1>Info &amp; Setup Guide</h1>
			<p>Follow these steps to get AI Email Marketing running on a fresh install.</p>

			<div style="max-width:860px">

				<h2>Step 1 — Configure Sending Defaults</h2>
				<p>Go to <strong>AI Email Marketing → Settings</strong> and fill in:</p>
				<ul style="list-style:disc;margin-left:1.5em">
					<li><strong>Default From Name</strong> — the sender name subscribers will see (e.g. your brand name).</li>
					<li><strong>Default From Email</strong> — must match a domain your host is authorised to send from; mismatches cause spam-folder delivery or rejection.</li>
				</ul>
				<p>WordPress sends mail via <code>wp_mail()</code>. For reliable delivery, install an SMTP plugin (e.g. WP Mail SMTP) and point it at a transactional mail service (SendGrid, Mailgun, Postmark, SES).</p>

				<h2>Step 2 — Add an OpenAI API Key (optional)</h2>
				<p>Under <strong>Settings → OpenAI</strong>, paste your API key. This unlocks AI-generated subject lines, preview text, and email body copy when you create or edit a campaign. Without a key the plugin still works — you just write copy manually.</p>
				<ul style="list-style:disc;margin-left:1.5em">
					<li>Get a key at <strong>platform.openai.com → API keys</strong>.</li>
					<li>Default model is <code>gpt-4o</code>; switch to <code>gpt-4o-mini</code> for lower cost.</li>
				</ul>
				<p><strong>System Prompt vs. Campaign Prompt — what's the difference?</strong></p>
				<p>There are two separate inputs that shape what the AI writes:</p>
				<ul style="list-style:disc;margin-left:1.5em">
					<li><strong>System Prompt (Settings → OpenAI)</strong> — set once, applies to every generation. Think of it as the AI copywriter's standing job description: output format, tone rules, brand voice, and HTML structure requirements. The default instructs the AI to return inline-styled HTML with a headline, intro paragraph, product highlights, and a CTA button. Customise it to match your brand — e.g. add your company name, preferred tone (formal/casual), or colour guidelines.</li>
					<li><strong>AI Content Generator (Campaign edit page)</strong> — the per-campaign creative brief. Describe what <em>this specific email</em> should accomplish: what to promote, any offer or deadline, who the audience is, and the desired tone. Example: <em>"Promote our summer sale — 20% off all shoes. Create urgency around a 48-hour deadline. Friendly, energetic tone."</em> The AI uses your System Prompt as its instructions and your campaign prompt as the brief.</li>
				</ul>
				<p>A well-written System Prompt means you only need a short campaign prompt each time — the standing rules handle the rest.</p>

				<h2>Step 3 — Create a Subscriber List</h2>
				<p>Go to <strong>Audience → Lists</strong> and click <em>Add List</em>. Give it a name (e.g. "Newsletter"). Lists are containers for subscribers; you can have as many as you need and target them individually per campaign.</p>

				<h2>Step 4 — Add a Signup Form</h2>
				<p>Go to <strong>Forms</strong> and click <em>New Form</em>. Configure:</p>
				<ul style="list-style:disc;margin-left:1.5em">
					<li>Which list new subscribers are added to.</li>
					<li>Whether to show a GDPR consent checkbox.</li>
					<li>A custom success message.</li>
				</ul>
				<p>Once saved, copy the shortcode (e.g. <code>[aiem_form id="1"]</code>) and paste it into any page or widget area.</p>

				<h2>Step 5 — Enable Double Opt-in (recommended)</h2>
				<p>Under <strong>Settings → Subscriptions</strong>, tick <em>Double Opt-in</em>. New subscribers receive a confirmation email and stay <em>unconfirmed</em> until they click the link. Workflows and campaigns skip unconfirmed addresses, keeping your list clean and reducing spam complaints.</p>

				<h2>Step 6 — Create Your First Campaign</h2>
				<p>Go to <strong>Campaigns → New Campaign</strong>. Fill in the campaign name, then choose an audience (a list or a segment). From there:</p>
				<ul style="list-style:disc;margin-left:1.5em">
					<li><strong>With AI:</strong> type a brief in the <em>AI Content Generator</em> box and click <em>Generate with AI</em>. The subject line, preview text, and email body will all be filled in automatically. Review and edit before sending. <strong>Tip:</strong> select a template from the <em>Load from Email Editor</em> dropdown first — the AI will fill that template's layout with your content instead of generating free-form HTML, keeping your brand design intact.</li>
					<li><strong>Manually:</strong> type your subject line, preview text, and paste or write HTML directly in the email content area. Use the <em>Email Editor</em> to build a reusable template and load it here.</li>
				</ul>
				<p>When ready, click <em>Send Now</em>, or set a date/time and click <em>Schedule</em>. For recurring campaigns (daily/weekly/monthly), each send cycle will regenerate the subject, preview text, and body from your prompt automatically — so the content stays fresh without any manual work.</p>
				<p>The plugin batches sends in the background — see <strong>Settings → Sending</strong> to tune batch size and delay if your host throttles outbound mail.</p>

				<h2>Step 7 — Set Up Workflows (optional)</h2>
				<p>Workflows let you trigger emails automatically. Go to <strong>Workflows → New Workflow</strong> and choose a trigger:</p>
				<ul style="list-style:disc;margin-left:1.5em">
					<li><strong>New subscriber</strong> — welcome email fires when someone confirms opt-in (or subscribes, if double opt-in is off).</li>
					<li><strong>Post published</strong> — sends a notification email each time you publish a new post.</li>
					<li><strong>WooCommerce purchase</strong> — fires after a completed order (requires WooCommerce).</li>
				</ul>

				<h2>Step 8 — Monitor Delivery</h2>
				<p>Check <strong>Logs</strong> for per-send results and bounce counts. Subscribers that exceed the bounce threshold (default: 3) are automatically suppressed. Use <strong>Reports</strong> for aggregate stats.</p>

				<hr style="margin:2em 0">
				<p><strong>Need to customise email templates?</strong> Go to <strong>Email Editor</strong> to build reusable block-based templates, then load them into any campaign.</p>

			</div>
		</div>
		<?php
	}

	private function pagination( int $total, int $per_page, int $current, array $query_args ): void {
		$pages = ceil( $total / $per_page );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url = add_query_arg( array_merge( $query_args, [ 'paged' => $i ] ), admin_url( 'admin.php' ) );
			echo $i === $current
				? '<span class="current">' . $i . '</span> '
				: '<a href="' . esc_url( $url ) . '">' . $i . '</a> ';
		}
		echo '</div></div>';
	}
}
