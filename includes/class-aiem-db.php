<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIEM_DB {

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$logs_sql = "CREATE TABLE {$wpdb->prefix}aiem_logs (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL DEFAULT '',
			subscriber_id bigint(20) UNSIGNED DEFAULT NULL,
			campaign_id bigint(20) UNSIGNED DEFAULT NULL,
			send_id bigint(20) UNSIGNED DEFAULT NULL,
			details text NOT NULL,
			ip_address varchar(45) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) $charset;";

		$workflows_sql = "CREATE TABLE {$wpdb->prefix}aiem_workflows (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL DEFAULT '',
			trigger_type varchar(50) NOT NULL DEFAULT '',
			trigger_config text NOT NULL,
			action_type varchar(50) NOT NULL DEFAULT 'send_email',
			action_campaign_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			action_send_to text NOT NULL,
			action_subject text NOT NULL,
			action_content longtext NOT NULL,
			action_list_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			action_email_styling varchar(50) NOT NULL DEFAULT 'none',
			delay_value int(11) NOT NULL DEFAULT 0,
			delay_unit varchar(20) NOT NULL DEFAULT 'minutes',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY trigger_status (trigger_type, status)
		) $charset;";

		$wf_queue_sql = "CREATE TABLE {$wpdb->prefix}aiem_workflow_queue (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			workflow_id bigint(20) UNSIGNED NOT NULL,
			subscriber_id bigint(20) UNSIGNED NOT NULL,
			post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			context text NOT NULL,
			scheduled_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			processed_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY scheduled_status (scheduled_at, status)
		) $charset;";

		$lists_sql = "CREATE TABLE {$wpdb->prefix}aiem_lists (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL DEFAULT '',
			description text NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset;";

		$subscribers_sql = "CREATE TABLE {$wpdb->prefix}aiem_subscribers (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			list_id bigint(20) UNSIGNED NOT NULL,
			email varchar(200) NOT NULL DEFAULT '',
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'subscribed',
			source varchar(50) NOT NULL DEFAULT 'manual',
			ip_address varchar(45) NOT NULL DEFAULT '',
			subscribed_at datetime DEFAULT NULL,
			unsubscribed_at datetime DEFAULT NULL,
			confirm_key varchar(64) DEFAULT NULL,
			bounce_count int(10) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY list_email (list_id, email),
			KEY list_status (list_id, status)
		) $charset;";

		$campaigns_sql = "CREATE TABLE {$wpdb->prefix}aiem_campaigns (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL DEFAULT '',
			subject varchar(500) NOT NULL DEFAULT '',
			preheader varchar(500) NOT NULL DEFAULT '',
			list_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			segment_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'draft',
			html_content longtext NOT NULL,
			blocks longtext NOT NULL,
			ai_prompt text NOT NULL,
			from_name varchar(200) NOT NULL DEFAULT '',
			from_email varchar(200) NOT NULL DEFAULT '',
			recur_schedule varchar(20) NOT NULL DEFAULT '',
			woo_category_ids text NOT NULL,
			woo_tag_ids text NOT NULL,
			scheduled_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY scheduled_at (scheduled_at)
		) $charset;";

		$segments_sql = "CREATE TABLE {$wpdb->prefix}aiem_segments (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL DEFAULT '',
			list_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			filters text NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset;";

		$sends_sql = "CREATE TABLE {$wpdb->prefix}aiem_sends (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) UNSIGNED NOT NULL,
			subscriber_id bigint(20) UNSIGNED NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			tracking_key varchar(64) NOT NULL DEFAULT '',
			sent_at datetime DEFAULT NULL,
			opened_at datetime DEFAULT NULL,
			open_count int(11) UNSIGNED NOT NULL DEFAULT 0,
			clicked_at datetime DEFAULT NULL,
			bounce_error text DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY campaign_subscriber (campaign_id, subscriber_id),
			UNIQUE KEY tracking_key (tracking_key),
			KEY campaign_status (campaign_id, status)
		) $charset;";

		$clicks_sql = "CREATE TABLE {$wpdb->prefix}aiem_click_events (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			send_id bigint(20) UNSIGNED NOT NULL,
			url text NOT NULL,
			clicked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY send_id (send_id)
		) $charset;";

		$forms_sql = "CREATE TABLE {$wpdb->prefix}aiem_forms (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL DEFAULT '',
			list_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			fields longtext NOT NULL,
			settings longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY list_id (list_id)
		) $charset;";

		$email_templates_sql = "CREATE TABLE {$wpdb->prefix}aiem_email_templates (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL DEFAULT '',
			blocks longtext NOT NULL,
			html_content longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $lists_sql );
		dbDelta( $subscribers_sql );
		dbDelta( $campaigns_sql );
		dbDelta( $sends_sql );
		dbDelta( $clicks_sql );
		dbDelta( $logs_sql );
		dbDelta( $workflows_sql );
		dbDelta( $wf_queue_sql );
		dbDelta( $segments_sql );
		dbDelta( $forms_sql );
		dbDelta( $email_templates_sql );

		update_option( 'aiem_db_version', AIEM_VERSION );
		self::maybe_migrate_queue_table();
	}

	public static function maybe_migrate_queue_table(): void {
		if ( ! isset( $GLOBALS['@pdo'] ) ) {
			return;
		}
		/** @var \PDO $pdo */
		$pdo  = $GLOBALS['@pdo'];
		global $wpdb;
		$table = $wpdb->prefix . 'aiem_workflow_queue';
		$cols  = array_column(
			$pdo->query( "PRAGMA table_info(`{$table}`)" )->fetchAll( \PDO::FETCH_ASSOC ),
			'name'
		);
		foreach ( [ 'post_id' => "INTEGER NOT NULL DEFAULT 0", 'context' => "TEXT NOT NULL DEFAULT '{}'" ] as $col => $def ) {
			if ( ! in_array( $col, $cols, true ) ) {
				$pdo->exec( "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}" );
			}
		}
	}

	// ── Lists ──────────────────────────────────────────────────────────────

	public static function get_lists(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT l.*, COUNT(s.id) AS subscriber_count
			 FROM {$wpdb->prefix}aiem_lists l
			 LEFT JOIN {$wpdb->prefix}aiem_subscribers s ON s.list_id = l.id AND s.status = 'subscribed'
			 GROUP BY l.id
			 ORDER BY l.created_at DESC"
		) ?: [];
	}

	public static function get_list( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiem_lists WHERE id = %d", $id )
		) ?: null;
	}

	public static function create_list( string $name, string $description = '' ): int|false {
		global $wpdb;
		$row = [ 'name' => $name, 'description' => $description ];
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_insert( $wpdb->prefix . 'aiem_lists', $row );
		}
		$result = $wpdb->insert( "{$wpdb->prefix}aiem_lists", $row, [ '%s', '%s' ] );
		return $result ? $wpdb->insert_id : false;
	}

	public static function update_list( int $id, string $name, string $description = '' ): bool {
		global $wpdb;
		$update = [ 'name' => $name, 'description' => $description ];
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_update( $wpdb->prefix . 'aiem_lists', $update, [ 'id' => $id ] );
		}
		return (bool) $wpdb->update(
			"{$wpdb->prefix}aiem_lists",
			$update,
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function delete_list( int $id ): bool {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}aiem_subscribers", [ 'list_id' => $id ], [ '%d' ] );
		return (bool) $wpdb->delete( "{$wpdb->prefix}aiem_lists", [ 'id' => $id ], [ '%d' ] );
	}

	// ── Subscribers ────────────────────────────────────────────────────────

	public static function get_subscribers( int $list_id, int $page = 1, int $per_page = 50, string $status = '' ): array {
		global $wpdb;
		$offset = ( $page - 1 ) * $per_page;
		$where  = $wpdb->prepare( 's.list_id = %d', $list_id );
		if ( $status ) {
			$where .= $wpdb->prepare( ' AND s.status = %s', $status );
		}
		return $wpdb->get_results(
			"SELECT s.*,
			        MAX(sn.opened_at)  AS last_opened_at,
			        MAX(sn.clicked_at) AS last_clicked_at
			 FROM {$wpdb->prefix}aiem_subscribers s
			 LEFT JOIN {$wpdb->prefix}aiem_sends sn ON sn.subscriber_id = s.id
			 WHERE $where
			 GROUP BY s.id
			 ORDER BY s.subscribed_at DESC
			 LIMIT $per_page OFFSET $offset"
		) ?: [];
	}

	public static function count_subscribers( int $list_id, string $status = 'subscribed' ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}aiem_subscribers WHERE list_id = %d AND status = %s",
				$list_id, $status
			)
		);
	}

	public static function get_subscriber_by_email( int $list_id, string $email ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aiem_subscribers WHERE list_id = %d AND email = %s",
				$list_id, $email
			)
		) ?: null;
	}

	public static function get_subscriber( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiem_subscribers WHERE id = %d", $id )
		) ?: null;
	}

	public static function get_subscribed_for_campaign( int $list_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aiem_subscribers WHERE list_id = %d AND status = 'subscribed'",
				$list_id
			)
		) ?: [];
	}

	public static function insert_subscriber( array $data ): int|false {
		global $wpdb;
		$status = in_array( $data['status'] ?? '', [ 'subscribed', 'unconfirmed' ], true )
			? $data['status']
			: 'subscribed';

		$row     = [
			'list_id'       => (int) $data['list_id'],
			'email'         => sanitize_email( $data['email'] ),
			'first_name'    => sanitize_text_field( $data['first_name'] ?? '' ),
			'last_name'     => sanitize_text_field( $data['last_name'] ?? '' ),
			'status'        => $status,
			'source'        => $data['source'] ?? 'manual',
			'ip_address'    => $data['ip_address'] ?? '',
			'subscribed_at' => $status === 'subscribed' ? current_time( 'mysql' ) : null,
		];
		$formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

		if ( ! empty( $data['confirm_key'] ) ) {
			$row['confirm_key'] = $data['confirm_key'];
			$formats[]          = '%s';
		}

		// Names like O'Brien contain apostrophes that break $wpdb->insert on SQLite.
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_insert( $wpdb->prefix . 'aiem_subscribers', $row );
		}

		$wpdb->suppress_errors( true );
		$result = $wpdb->insert( "{$wpdb->prefix}aiem_subscribers", $row, $formats );
		$wpdb->suppress_errors( false );
		return $result ? $wpdb->insert_id : false;
	}

	public static function get_subscriber_by_confirm_key( string $key ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aiem_subscribers WHERE confirm_key = %s AND status = 'unconfirmed'",
				$key
			)
		) ?: null;
	}

	public static function confirm_subscriber( string $key ): ?object {
		global $wpdb;
		$sub = self::get_subscriber_by_confirm_key( $key );
		if ( ! $sub ) {
			return null;
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}aiem_subscribers
				 SET status = 'subscribed', confirm_key = NULL, subscribed_at = %s
				 WHERE id = %d",
				current_time( 'mysql' ), (int) $sub->id
			)
		);
		return $sub;
	}

	public static function confirm_subscriber_by_id( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}aiem_subscribers
				 SET status = 'subscribed', confirm_key = NULL, subscribed_at = %s
				 WHERE id = %d AND status = 'unconfirmed'",
				current_time( 'mysql' ), $id
			)
		);
	}

	public static function unsubscribe_by_tracking_key( string $tracking_key ): bool {
		global $wpdb;
		$send = $wpdb->get_row(
			$wpdb->prepare( "SELECT subscriber_id FROM {$wpdb->prefix}aiem_sends WHERE tracking_key = %s", $tracking_key )
		);
		if ( ! $send ) {
			return false;
		}
		return (bool) $wpdb->update(
			"{$wpdb->prefix}aiem_subscribers",
			[ 'status' => 'unsubscribed', 'unsubscribed_at' => current_time( 'mysql' ) ],
			[ 'id' => (int) $send->subscriber_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function delete_subscriber( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( "{$wpdb->prefix}aiem_subscribers", [ 'id' => $id ], [ '%d' ] );
	}

	public static function bulk_unsubscribe_subscribers( array $ids ): int {
		global $wpdb;
		if ( empty( $ids ) ) {
			return 0;
		}
		$ids_str = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "UPDATE {$wpdb->prefix}aiem_subscribers SET status = 'unsubscribed' WHERE id IN ({$ids_str})" );
		return (int) $wpdb->rows_affected;
	}

	public static function bulk_delete_subscribers( array $ids ): int {
		global $wpdb;
		if ( empty( $ids ) ) {
			return 0;
		}
		$ids_str = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}aiem_subscribers WHERE id IN ({$ids_str})" );
		return (int) $wpdb->rows_affected;
	}

	// ── Campaigns ──────────────────────────────────────────────────────────

	public static function get_campaigns(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT c.*, l.name AS list_name
			 FROM {$wpdb->prefix}aiem_campaigns c
			 LEFT JOIN {$wpdb->prefix}aiem_lists l ON l.id = c.list_id
			 ORDER BY c.created_at DESC"
		) ?: [];
	}

	public static function get_campaign( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiem_campaigns WHERE id = %d", $id )
		) ?: null;
	}

	public static function get_scheduled_due(): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aiem_campaigns WHERE status = 'scheduled' AND scheduled_at <= %s",
				current_time( 'mysql' )
			)
		) ?: [];
	}

	public static function create_campaign( array $data ): int|false {
		global $wpdb;
		$row = [
			'name'         => sanitize_text_field( $data['name'] ?? '' ),
			'subject'      => sanitize_text_field( $data['subject'] ?? '' ),
			'preheader'    => sanitize_text_field( $data['preheader'] ?? '' ),
			'list_id'      => (int) ( $data['list_id'] ?? 0 ),
			'segment_id'   => (int) ( $data['segment_id'] ?? 0 ),
			'status'       => 'draft',
			'html_content'   => $data['html_content'] ?? '',
			'blocks'         => $data['blocks'] ?? '[]',
			'ai_prompt'      => sanitize_textarea_field( $data['ai_prompt'] ?? '' ),
			'from_name'      => sanitize_text_field( $data['from_name'] ?? '' ),
			'from_email'     => sanitize_email( $data['from_email'] ?? '' ),
			'recur_schedule'   => sanitize_text_field( $data['recur_schedule'] ?? '' ),
			'woo_category_ids' => $data['woo_category_ids'] ?? '[]',
			'woo_tag_ids'      => $data['woo_tag_ids'] ?? '[]',
		];

		// SQLite parser fails on single-quoted content via $wpdb->insert; AI email
		// HTML routinely contains apostrophes. Use raw PDO when on SQLite.
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_insert( $wpdb->prefix . 'aiem_campaigns', $row );
		}

		$result = $wpdb->insert(
			"{$wpdb->prefix}aiem_campaigns",
			$row,
			[ '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return $result ? $wpdb->insert_id : false;
	}

	public static function duplicate_campaign( int $id ): int|false {
		$c = self::get_campaign( $id );
		if ( ! $c ) {
			return false;
		}
		return self::create_campaign( [
			'name'         => 'Copy of ' . $c->name,
			'subject'      => $c->subject,
			'preheader'    => $c->preheader,
			'list_id'      => $c->list_id,
			'segment_id'   => $c->segment_id,
			'html_content' => $c->html_content,
			'blocks'       => $c->blocks,
			'ai_prompt'    => $c->ai_prompt,
			'from_name'    => $c->from_name,
			'from_email'   => $c->from_email,
		] );
	}

	public static function update_campaign( int $id, array $data ): bool {
		global $wpdb;
		$allowed = [ 'name', 'subject', 'preheader', 'list_id', 'segment_id', 'status', 'html_content', 'blocks', 'ai_prompt', 'from_name', 'from_email', 'scheduled_at', 'sent_at', 'recur_schedule', 'woo_category_ids', 'woo_tag_ids' ];
		$update  = array_intersect_key( $data, array_flip( $allowed ) );
		if ( empty( $update ) ) {
			return false;
		}
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_update( $wpdb->prefix . 'aiem_campaigns', $update, [ 'id' => $id ] );
		}
		return (bool) $wpdb->update( "{$wpdb->prefix}aiem_campaigns", $update, [ 'id' => $id ] );
	}

	public static function delete_campaign( int $id ): bool {
		global $wpdb;
		$send_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aiem_sends WHERE campaign_id = %d", $id )
		);
		if ( $send_ids ) {
			$in = implode( ',', array_map( 'intval', $send_ids ) );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}aiem_click_events WHERE send_id IN ($in)" );
		}
		$wpdb->delete( "{$wpdb->prefix}aiem_sends", [ 'campaign_id' => $id ], [ '%d' ] );
		return (bool) $wpdb->delete( "{$wpdb->prefix}aiem_campaigns", [ 'id' => $id ], [ '%d' ] );
	}

	public static function clear_sends( int $id ): void {
		global $wpdb;
		$send_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aiem_sends WHERE campaign_id = %d", $id )
		);
		if ( $send_ids ) {
			$in = implode( ',', array_map( 'intval', $send_ids ) );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}aiem_click_events WHERE send_id IN ($in)" );
		}
		$wpdb->delete( "{$wpdb->prefix}aiem_sends", [ 'campaign_id' => $id ], [ '%d' ] );
	}

	public static function schedule_next_recurrence( int $id ): void {
		$campaign = self::get_campaign( $id );
		if ( ! $campaign || ! $campaign->recur_schedule ) {
			return;
		}
		$intervals = [
			'daily'   => '+1 day',
			'weekly'  => '+7 days',
			'monthly' => '+1 month',
		];
		$offset = $intervals[ $campaign->recur_schedule ] ?? null;
		if ( ! $offset ) {
			return;
		}
		$next = date( 'Y-m-d H:i:s', strtotime( $offset, current_time( 'timestamp' ) ) );
		self::clear_sends( $id );
		self::update_campaign( $id, [
			'status'       => 'scheduled',
			'scheduled_at' => $next,
		] );
	}

	// ── Sends ──────────────────────────────────────────────────────────────

	public static function insert_send( int $campaign_id, int $subscriber_id ): int|false {
		global $wpdb;
		$key = bin2hex( random_bytes( 32 ) );
		$wpdb->suppress_errors( true );
		$result = $wpdb->insert(
			"{$wpdb->prefix}aiem_sends",
			[
				'campaign_id'   => $campaign_id,
				'subscriber_id' => $subscriber_id,
				'tracking_key'  => $key,
				'status'        => 'pending',
			],
			[ '%d', '%d', '%s', '%s' ]
		);
		$wpdb->suppress_errors( false );
		return $result ? $wpdb->insert_id : false;
	}

	public static function get_pending_sends( int $campaign_id, int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, sub.email, sub.first_name, sub.last_name
				 FROM {$wpdb->prefix}aiem_sends s
				 JOIN {$wpdb->prefix}aiem_subscribers sub ON sub.id = s.subscriber_id
				 WHERE s.campaign_id = %d AND s.status = 'pending'
				 ORDER BY s.id ASC
				 LIMIT %d",
				$campaign_id, $limit
			)
		) ?: [];
	}

	public static function update_send( int $id, array $data ): bool {
		global $wpdb;
		return (bool) $wpdb->update( "{$wpdb->prefix}aiem_sends", $data, [ 'id' => $id ] );
	}

	public static function get_send_by_tracking_key( string $key ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiem_sends WHERE tracking_key = %s", $key )
		) ?: null;
	}

	public static function get_send( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiem_sends WHERE id = %d", $id )
		) ?: null;
	}

	public static function get_send_by_campaign_subscriber( int $campaign_id, int $subscriber_id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aiem_sends WHERE campaign_id = %d AND subscriber_id = %d",
				$campaign_id, $subscriber_id
			)
		) ?: null;
	}

	public static function mark_send_bounced( int $send_id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}aiem_sends",
			[ 'status' => 'bounced', 'bounce_error' => $error, 'sent_at' => current_time( 'mysql' ) ],
			[ 'id' => $send_id ]
		);
	}

	public static function increment_bounce_count( int $subscriber_id ): int {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}aiem_subscribers SET bounce_count = bounce_count + 1 WHERE id = %d",
				$subscriber_id
			)
		);
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT bounce_count FROM {$wpdb->prefix}aiem_subscribers WHERE id = %d", $subscriber_id )
		);
	}

	public static function bounce_subscriber( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			"{$wpdb->prefix}aiem_subscribers",
			[ 'status' => 'bounced' ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	public static function record_open( string $key ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}aiem_sends
				 SET open_count = open_count + 1,
				     opened_at = CASE WHEN opened_at IS NULL THEN %s ELSE opened_at END
				 WHERE tracking_key = %s",
				current_time( 'mysql' ), $key
			)
		);
	}

	public static function get_click_breakdown( int $campaign_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ce.url, COUNT(*) AS clicks
				 FROM {$wpdb->prefix}aiem_click_events ce
				 JOIN {$wpdb->prefix}aiem_sends s ON s.id = ce.send_id
				 WHERE s.campaign_id = %d
				 GROUP BY ce.url
				 ORDER BY clicks DESC",
				$campaign_id
			)
		) ?: [];
	}

	public static function record_click( string $key, string $url ): void {
		global $wpdb;
		$send = self::get_send_by_tracking_key( $key );
		if ( ! $send ) {
			return;
		}
		$wpdb->insert(
			"{$wpdb->prefix}aiem_click_events",
			[ 'send_id' => (int) $send->id, 'url' => $url ],
			[ '%d', '%s' ]
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}aiem_sends
				 SET clicked_at = CASE WHEN clicked_at IS NULL THEN %s ELSE clicked_at END
				 WHERE tracking_key = %s",
				current_time( 'mysql' ), $key
			)
		);
	}

	// ── Reports ────────────────────────────────────────────────────────────

	public static function get_campaign_stats( int $campaign_id ): object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN s.status = 'sent' THEN 1 ELSE 0 END) AS sent,
					SUM(CASE WHEN s.status = 'failed' THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN s.status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
					SUM(CASE WHEN s.open_count > 0 THEN 1 ELSE 0 END) AS opened,
					SUM(CASE WHEN s.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
					SUM(CASE WHEN sub.status = 'unsubscribed' THEN 1 ELSE 0 END) AS unsubscribed
				 FROM {$wpdb->prefix}aiem_sends s
				 JOIN {$wpdb->prefix}aiem_subscribers sub ON sub.id = s.subscriber_id
				 WHERE s.campaign_id = %d",
				$campaign_id
			)
		);
		return $row ?: (object) [ 'total' => 0, 'sent' => 0, 'failed' => 0, 'bounced' => 0, 'opened' => 0, 'clicked' => 0, 'unsubscribed' => 0 ];
	}

	public static function get_sends_for_campaign( int $campaign_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, sub.email, sub.first_name, sub.last_name
				 FROM {$wpdb->prefix}aiem_sends s
				 JOIN {$wpdb->prefix}aiem_subscribers sub ON sub.id = s.subscriber_id
				 WHERE s.campaign_id = %d
				 ORDER BY s.id ASC",
				$campaign_id
			)
		) ?: [];
	}

	// ── Logs ───────────────────────────────────────────────────────────────

	public static function insert_log( string $event_type, array $data = [] ): void {
		global $wpdb;
		$ip = aiem_client_ip();
		$wpdb->insert(
			"{$wpdb->prefix}aiem_logs",
			[
				'event_type'    => $event_type,
				'subscriber_id' => isset( $data['subscriber_id'] ) ? (int) $data['subscriber_id'] : null,
				'campaign_id'   => isset( $data['campaign_id'] )   ? (int) $data['campaign_id']   : null,
				'send_id'       => isset( $data['send_id'] )       ? (int) $data['send_id']       : null,
				'details'       => wp_json_encode( $data ),
				'ip_address'    => $ip,
			],
			[ '%s', '%d', '%d', '%d', '%s', '%s' ]
		);
	}

	public static function get_logs( string $event_filter = '', int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$offset = ( $page - 1 ) * $per_page;
		$where  = $event_filter ? $wpdb->prepare( 'WHERE event_type = %s', $event_filter ) : '';
		return $wpdb->get_results(
			"SELECT l.*, s.email AS subscriber_email, c.name AS campaign_name
			 FROM {$wpdb->prefix}aiem_logs l
			 LEFT JOIN {$wpdb->prefix}aiem_subscribers s ON s.id = l.subscriber_id
			 LEFT JOIN {$wpdb->prefix}aiem_campaigns c ON c.id = l.campaign_id
			 $where
			 ORDER BY l.created_at DESC
			 LIMIT $per_page OFFSET $offset"
		) ?: [];
	}

	public static function count_logs( string $event_filter = '' ): int {
		global $wpdb;
		if ( $event_filter ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}aiem_logs WHERE event_type = %s", $event_filter )
			);
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aiem_logs" );
	}

	public static function purge_logs( int $days ): int {
		global $wpdb;
		$cutoff = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->prefix}aiem_logs WHERE created_at < %s", $cutoff )
		);
		return (int) $wpdb->rows_affected;
	}

	public static function get_recent_logs( int $limit = 10 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, s.email AS subscriber_email, c.name AS campaign_name
				 FROM {$wpdb->prefix}aiem_logs l
				 LEFT JOIN {$wpdb->prefix}aiem_subscribers s ON s.id = l.subscriber_id
				 LEFT JOIN {$wpdb->prefix}aiem_campaigns c ON c.id = l.campaign_id
				 ORDER BY l.created_at DESC LIMIT %d",
				$limit
			)
		) ?: [];
	}

	// ── Segments ───────────────────────────────────────────────────────────

	public static function get_segments( int $list_id = 0 ): array {
		global $wpdb;
		if ( $list_id ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiem_segments WHERE list_id = %d ORDER BY name ASC", $list_id )
			) ?: [];
		}
		return $wpdb->get_results(
			"SELECT s.*, l.name AS list_name
			 FROM {$wpdb->prefix}aiem_segments s
			 LEFT JOIN {$wpdb->prefix}aiem_lists l ON l.id = s.list_id
			 ORDER BY s.name ASC"
		) ?: [];
	}

	public static function get_segment( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiem_segments WHERE id = %d", $id )
		) ?: null;
	}

	public static function create_segment( array $data ): int|false {
		global $wpdb;
		$row = [
			'name'    => sanitize_text_field( $data['name'] ?? '' ),
			'list_id' => (int) ( $data['list_id'] ?? 0 ),
			'filters' => wp_json_encode( $data['filters'] ?? [] ),
		];
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_insert( $wpdb->prefix . 'aiem_segments', $row );
		}
		$result = $wpdb->insert(
			"{$wpdb->prefix}aiem_segments",
			$row,
			[ '%s', '%d', '%s' ]
		);
		return $result ? $wpdb->insert_id : false;
	}

	public static function update_segment( int $id, array $data ): bool {
		global $wpdb;
		$update = [];
		if ( isset( $data['name'] ) )    $update['name']    = sanitize_text_field( $data['name'] );
		if ( isset( $data['list_id'] ) ) $update['list_id'] = (int) $data['list_id'];
		if ( isset( $data['filters'] ) ) $update['filters'] = wp_json_encode( $data['filters'] );
		if ( empty( $update ) ) return false;
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_update( $wpdb->prefix . 'aiem_segments', $update, [ 'id' => $id ] );
		}
		return (bool) $wpdb->update( "{$wpdb->prefix}aiem_segments", $update, [ 'id' => $id ] );
	}

	public static function delete_segment( int $id ): bool {
		global $wpdb;
		// Clear segment_id from any campaigns using it
		$wpdb->update( "{$wpdb->prefix}aiem_campaigns", [ 'segment_id' => 0 ], [ 'segment_id' => $id ] );
		return (bool) $wpdb->delete( "{$wpdb->prefix}aiem_segments", [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Returns subscribers matching a segment's filters.
	 * Filters JSON: [ {"type":"status","value":"subscribed"}, {"type":"engagement","operator":"opened_any"}, ... ]
	 */
	public static function get_subscribers_for_segment( int $segment_id ): array {
		global $wpdb;

		$segment = self::get_segment( $segment_id );
		if ( ! $segment || ! $segment->list_id ) {
			return [];
		}

		$filters     = json_decode( $segment->filters, true ) ?: [];
		$where_parts = [];

		// Always scope to the segment's list
		$where_parts[] = $wpdb->prepare( 's.list_id = %d', $segment->list_id );

		// Default status = subscribed unless a status filter is present
		$has_status = false;
		foreach ( $filters as $f ) {
			if ( ( $f['type'] ?? '' ) === 'status' ) {
				$has_status = true;
				break;
			}
		}
		if ( ! $has_status ) {
			$where_parts[] = "s.status = 'subscribed'";
		}

		foreach ( $filters as $f ) {
			$type = $f['type'] ?? '';

			switch ( $type ) {
				case 'status':
					$valid = [ 'subscribed', 'unsubscribed', 'unconfirmed', 'bounced' ];
					$val   = $f['value'] ?? '';
					if ( in_array( $val, $valid, true ) ) {
						$where_parts[] = $wpdb->prepare( 's.status = %s', $val );
					}
					break;

				case 'engagement':
					$op = $f['operator'] ?? '';
					switch ( $op ) {
						case 'opened_any':
							$where_parts[] = "s.id IN (SELECT DISTINCT subscriber_id FROM {$wpdb->prefix}aiem_sends WHERE open_count > 0)";
							break;
						case 'never_opened':
							$where_parts[] = "s.id NOT IN (SELECT DISTINCT subscriber_id FROM {$wpdb->prefix}aiem_sends WHERE open_count > 0)";
							break;
						case 'clicked_any':
							$where_parts[] = "s.id IN (SELECT DISTINCT subscriber_id FROM {$wpdb->prefix}aiem_sends WHERE clicked_at IS NOT NULL)";
							break;
						case 'never_clicked':
							$where_parts[] = "s.id NOT IN (SELECT DISTINCT subscriber_id FROM {$wpdb->prefix}aiem_sends WHERE clicked_at IS NOT NULL)";
							break;
					}
					break;

				case 'non_openers_of':
					$cid = (int) ( $f['campaign_id'] ?? 0 );
					if ( $cid ) {
						$where_parts[] = $wpdb->prepare(
							"s.id IN (SELECT subscriber_id FROM {$wpdb->prefix}aiem_sends WHERE campaign_id = %d AND status = 'sent' AND open_count = 0)",
							$cid
						);
					}
					break;

				case 'subscribed_after':
					$val = sanitize_text_field( $f['value'] ?? '' );
					if ( $val ) {
						$where_parts[] = $wpdb->prepare( 's.subscribed_at >= %s', $val . ' 00:00:00' );
					}
					break;

				case 'subscribed_before':
					$val = sanitize_text_field( $f['value'] ?? '' );
					if ( $val ) {
						$where_parts[] = $wpdb->prepare( 's.subscribed_at <= %s', $val . ' 23:59:59' );
					}
					break;
			}
		}

		$sql = "SELECT s.* FROM {$wpdb->prefix}aiem_subscribers s WHERE " . implode( ' AND ', $where_parts );
		return $wpdb->get_results( $sql ) ?: [];
	}

	public static function count_subscribers_for_segment( int $segment_id ): int {
		return count( self::get_subscribers_for_segment( $segment_id ) );
	}

	public static function count_non_openers( int $campaign_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aiem_sends WHERE campaign_id = %d AND status = 'sent' AND open_count = 0",
			$campaign_id
		) );
	}

	// ── Email Templates ───────────────────────────────────────────────────────

	public static function get_email_templates(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}aiem_email_templates ORDER BY created_at DESC"
		) ?: [];
	}

	public static function get_email_template( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiem_email_templates WHERE id = %d", $id )
		) ?: null;
	}

	public static function create_email_template( array $data ): int|false {
		global $wpdb;
		$row = [
			'name'         => sanitize_text_field( $data['name'] ?? '' ),
			'blocks'       => $data['blocks'] ?? '[]',
			'html_content' => $data['html_content'] ?? '',
		];
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_insert( $wpdb->prefix . 'aiem_email_templates', $row );
		}
		$result = $wpdb->insert(
			"{$wpdb->prefix}aiem_email_templates",
			$row,
			[ '%s', '%s', '%s' ]
		);
		return $result ? $wpdb->insert_id : false;
	}

	public static function update_email_template( int $id, array $data ): bool {
		global $wpdb;
		$allowed = [ 'name', 'blocks', 'html_content' ];
		$update  = array_intersect_key( $data, array_flip( $allowed ) );
		if ( empty( $update ) ) return false;
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_update( $wpdb->prefix . 'aiem_email_templates', $update, [ 'id' => $id ] );
		}
		return (bool) $wpdb->update( "{$wpdb->prefix}aiem_email_templates", $update, [ 'id' => $id ] );
	}

	public static function delete_email_template( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( "{$wpdb->prefix}aiem_email_templates", [ 'id' => $id ], [ '%d' ] );
	}

	// ── Forms ─────────────────────────────────────────────────────────────────

	public static function get_forms(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT f.*, l.name AS list_name
			 FROM {$wpdb->prefix}aiem_forms f
			 LEFT JOIN {$wpdb->prefix}aiem_lists l ON l.id = f.list_id
			 ORDER BY f.created_at DESC"
		) ?: [];
	}

	public static function get_form( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiem_forms WHERE id = %d", $id )
		) ?: null;
	}

	public static function create_form( array $data ): int|false {
		global $wpdb;
		$row = [
			'name'     => sanitize_text_field( $data['name'] ?? '' ),
			'list_id'  => (int) ( $data['list_id'] ?? 0 ),
			'fields'   => wp_json_encode( $data['fields'] ?? [] ),
			'settings' => wp_json_encode( $data['settings'] ?? [] ),
		];
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_insert( $wpdb->prefix . 'aiem_forms', $row );
		}
		$result = $wpdb->insert(
			"{$wpdb->prefix}aiem_forms",
			$row,
			[ '%s', '%d', '%s', '%s' ]
		);
		return $result ? $wpdb->insert_id : false;
	}

	public static function update_form( int $id, array $data ): bool {
		global $wpdb;
		$update = [];
		if ( isset( $data['name'] ) )     $update['name']     = sanitize_text_field( $data['name'] );
		if ( isset( $data['list_id'] ) )  $update['list_id']  = (int) $data['list_id'];
		if ( isset( $data['fields'] ) )   $update['fields']   = wp_json_encode( $data['fields'] );
		if ( isset( $data['settings'] ) ) $update['settings'] = wp_json_encode( $data['settings'] );
		if ( empty( $update ) ) return false;
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_update( $wpdb->prefix . 'aiem_forms', $update, [ 'id' => $id ] );
		}
		return (bool) $wpdb->update( "{$wpdb->prefix}aiem_forms", $update, [ 'id' => $id ] );
	}

	public static function delete_form( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( "{$wpdb->prefix}aiem_forms", [ 'id' => $id ], [ '%d' ] );
	}

	// ── Workflows ──────────────────────────────────────────────────────────

	public static function get_workflows(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}aiem_workflows ORDER BY created_at DESC"
		) ?: [];
	}

	public static function get_workflow( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiem_workflows WHERE id = %d", $id )
		) ?: null;
	}

	public static function get_active_workflows_by_trigger( string $trigger_type ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aiem_workflows WHERE trigger_type = %s AND status = 'active'",
				$trigger_type
			)
		) ?: [];
	}

	public static function create_workflow( array $data ): int|false {
		global $wpdb;
		$row = [
			'name'                 => sanitize_text_field( $data['name'] ?? '' ),
			'trigger_type'         => sanitize_text_field( $data['trigger_type'] ?? '' ),
			'trigger_config'       => wp_json_encode( $data['trigger_config'] ?? [] ),
			'action_type'          => 'send_email',
			'action_campaign_id'   => (int) ( $data['action_campaign_id'] ?? 0 ),
			'action_send_to'       => sanitize_text_field( $data['action_send_to'] ?? '{{EMAIL}}' ),
			'action_subject'       => sanitize_text_field( $data['action_subject'] ?? '' ),
			'action_content'       => wp_kses_post( $data['action_content'] ?? '' ),
			'action_list_id'       => (int) ( $data['action_list_id'] ?? 0 ),
			'action_email_styling' => in_array( $data['action_email_styling'] ?? '', [ 'none', 'default' ] ) ? $data['action_email_styling'] : 'none',
			'delay_value'          => max( 0, (int) ( $data['delay_value'] ?? 0 ) ),
			'delay_unit'           => in_array( $data['delay_unit'] ?? '', [ 'minutes', 'hours', 'days' ] ) ? $data['delay_unit'] : 'minutes',
			'status'               => 'active',
		];

		// The SQLite integration's MySQL parser fails on addslashes()-escaped values
		// produced by $wpdb->insert() when content contains single quotes. Use raw
		// PDO binding to bypass the translation layer when running on SQLite.
		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_insert( $wpdb->prefix . 'aiem_workflows', $row );
		}

		$result = $wpdb->insert(
			"{$wpdb->prefix}aiem_workflows",
			$row,
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);
		return $result ? $wpdb->insert_id : false;
	}

	public static function update_workflow( int $id, array $data ): bool {
		global $wpdb;
		$allowed = [ 'name', 'trigger_type', 'trigger_config', 'action_campaign_id', 'action_send_to', 'action_subject', 'action_content', 'action_list_id', 'action_email_styling', 'delay_value', 'delay_unit', 'status' ];
		$update  = array_intersect_key( $data, array_flip( $allowed ) );

		if ( isset( $update['trigger_config'] ) && is_array( $update['trigger_config'] ) ) {
			$update['trigger_config'] = wp_json_encode( $update['trigger_config'] );
		}

		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_update( $wpdb->prefix . 'aiem_workflows', $update, [ 'id' => $id ] );
		}

		return (bool) $wpdb->update( "{$wpdb->prefix}aiem_workflows", $update, [ 'id' => $id ] );
	}

	private static function pdo_insert( string $table, array $row ): int|false {
		/** @var \PDO $pdo */
		$pdo  = $GLOBALS['@pdo'];
		$cols = '`' . implode( '`, `', array_keys( $row ) ) . '`';
		$ph   = implode( ', ', array_fill( 0, count( $row ), '?' ) );
		try {
			$stmt = $pdo->prepare( "INSERT INTO `{$table}` ({$cols}) VALUES ({$ph})" );
			if ( $stmt && $stmt->execute( array_values( $row ) ) ) {
				return (int) $pdo->lastInsertId();
			}
		} catch ( \Exception $e ) {
			return false;
		}
		return false;
	}

	private static function pdo_update( string $table, array $data, array $where ): bool {
		/** @var \PDO $pdo */
		$pdo = $GLOBALS['@pdo'];
		$set = implode( ', ', array_map( fn( $col ) => "`{$col}` = ?", array_keys( $data ) ) );
		$whr = implode( ' AND ', array_map( fn( $col ) => "`{$col}` = ?", array_keys( $where ) ) );
		try {
			$stmt = $pdo->prepare( "UPDATE `{$table}` SET {$set} WHERE {$whr}" );
			return $stmt && $stmt->execute( [ ...array_values( $data ), ...array_values( $where ) ] );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	public static function delete_workflow( int $id ): bool {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}aiem_workflow_queue", [ 'workflow_id' => $id, 'status' => 'pending' ], [ '%d', '%s' ] );
		return (bool) $wpdb->delete( "{$wpdb->prefix}aiem_workflows", [ 'id' => $id ], [ '%d' ] );
	}

	// ── Workflow Queue ─────────────────────────────────────────────────────

	public static function insert_workflow_queue( int $workflow_id, int $subscriber_id, string $scheduled_at, int $post_id = 0, array $context = [] ): int|false {
		$row = [
			'workflow_id'   => $workflow_id,
			'subscriber_id' => $subscriber_id,
			'post_id'       => $post_id,
			'context'       => wp_json_encode( $context ),
			'scheduled_at'  => $scheduled_at,
			'status'        => 'pending',
		];

		global $wpdb;

		if ( isset( $GLOBALS['@pdo'] ) ) {
			return self::pdo_insert( $wpdb->prefix . 'aiem_workflow_queue', $row );
		}

		$result = $wpdb->insert( "{$wpdb->prefix}aiem_workflow_queue", $row, [ '%d', '%d', '%d', '%s', '%s', '%s' ] );
		return $result ? $wpdb->insert_id : false;
	}

	public static function get_due_workflow_queue( int $limit = 20 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aiem_workflow_queue
				 WHERE status = 'pending' AND scheduled_at <= %s
				 ORDER BY scheduled_at ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s' ), $limit
			)
		) ?: [];
	}

	public static function update_workflow_queue_item( int $id, array $data ): bool {
		global $wpdb;
		return (bool) $wpdb->update( "{$wpdb->prefix}aiem_workflow_queue", $data, [ 'id' => $id ] );
	}

	// ── Dashboard ──────────────────────────────────────────────────────────

	public static function get_dashboard_stats(): object {
		global $wpdb;

		$subscribers = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aiem_subscribers WHERE status = 'subscribed'"
		);
		$campaigns_sent = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aiem_campaigns WHERE status = 'sent'"
		);
		$total_sent = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aiem_sends WHERE status = 'sent'"
		);
		$total_opened = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aiem_sends WHERE open_count > 0"
		);
		$total_clicked = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aiem_sends WHERE clicked_at IS NOT NULL"
		);
		$active_workflows = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aiem_workflows WHERE status = 'active'"
		);

		$open_rate  = $total_sent > 0 ? round( $total_opened  / $total_sent * 100, 1 ) : 0;
		$click_rate = $total_sent > 0 ? round( $total_clicked / $total_sent * 100, 1 ) : 0;

		return (object) compact(
			'subscribers', 'campaigns_sent', 'total_sent',
			'total_opened', 'total_clicked', 'active_workflows',
			'open_rate', 'click_rate'
		);
	}

	public static function get_subscriber_growth( int $days = 30 ): array {
		global $wpdb;
		$since = date( 'Y-m-d', strtotime( "-{$days} days" ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(subscribed_at) AS day, COUNT(*) AS count
				 FROM {$wpdb->prefix}aiem_subscribers
				 WHERE subscribed_at >= %s
				 GROUP BY DATE(subscribed_at)
				 ORDER BY day ASC",
				$since
			)
		) ?: [];
	}

	public static function get_top_campaigns( int $limit = 5 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.name, c.id,
					COUNT(s.id) AS total_sent,
					SUM(CASE WHEN s.open_count > 0 THEN 1 ELSE 0 END) AS opens,
					SUM(CASE WHEN s.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicks,
					CASE WHEN COUNT(s.id) > 0
						THEN ROUND(SUM(CASE WHEN s.open_count > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(s.id), 1)
						ELSE 0
					END AS open_rate
				 FROM {$wpdb->prefix}aiem_campaigns c
				 JOIN {$wpdb->prefix}aiem_sends s ON s.campaign_id = c.id
				 WHERE c.status = 'sent'
				 GROUP BY c.id
				 ORDER BY open_rate DESC
				 LIMIT %d",
				$limit
			)
		) ?: [];
	}
}
