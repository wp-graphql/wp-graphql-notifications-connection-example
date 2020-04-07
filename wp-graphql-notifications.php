<?php
/**
 * Plugin Name: WPGraphQL Notifications
 * Author: Jason Bahl, WPGraphQL
 * Description: Example showing how to build custom connections with WPGraphQL and a Custom Database Table
 */

/**
 * Hook into plugin activation to create a new table if needed
 */
register_activation_hook( __FILE__, 'wpgraphql_notifications_create_plugin_db_table');

/**
 * When the plugin is activated, create the table
 * if it doesn't already exist
 */
function wpgraphql_notifications_create_plugin_db_table() {

	global $table_prefix, $wpdb;

	$table_name = 'notifications';
	$wp_table = $wpdb->prefix . $table_name;
	$charset_collate = $wpdb->get_charset_collate();

	/**
	 * If the table doesn't already exist
	 */
	if ( $wpdb->get_var( "show tables like '$wp_table") != $wp_table ) {

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$sql = "
		CREATE TABLE $wp_table (
		  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `highlight` tinyint(4) DEFAULT NULL,
		  `user_id` bigint(20) unsigned NOT NULL,
		  `sender_name` text COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  `additional_senders` int(11) NOT NULL,
		  `sender_avatar` text COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  `target` text COLLATE utf8mb4_unicode_520_ci NOT NULL,
		  `message_id` int(11) NOT NULL,
		  `date` datetime NOT NULL,
		  PRIMARY KEY (`id`)
		) $charset_collate;
		";

		dbDelta( $sql );

	}

}

/**
 * Hook into graphql init to extend GraphQL functionality
 */
add_action( 'graphql_init', function() {

	class NotificationConnectionResolver extends \WPGraphQL\Data\Connection\AbstractConnectionResolver {

		public function get_loader_name() {
			return 'notifications';
		}

		public function get_query_args() {
			return [];
		}

		public function get_query() {
			global $wpdb;
			$current_user_id = get_current_user_id();

			$ids_array = $wpdb->get_results(
				$wpdb->prepare(
					sprintf(
						'SELECT id FROM %1$snotifications WHERE user_id=%2$d LIMIT 10',
						$wpdb->prefix,
						$current_user_id
					)
				)
			);

			$ids = ! empty( $ids_array ) ? array_values(array_column($ids_array, 'id')) : [];
			return $ids;
		}

		public function get_ids() {
			return $this->get_query();
		}

		public function is_valid_offset( $offset ) {
			return true;
		}

		public function is_valid_model( $model ) {
			return true;
		}

		public function should_execute() {
			return true;
		}

	}

	/**
	 * Class NotificationsLoader
	 *
	 * This is a custom loader that extends the WPGraphQL Abstract Data Loader.
	 */
	class NotificationsLoader extends \WPGraphQL\Data\Loader\AbstractDataLoader {

		public function loadKeys( array $keys ) {
			if ( empty( $keys ) || ! is_array( $keys ) ) {
				return [];
			}

			global $wpdb;
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `".$wpdb->prefix."notifications` WHERE `id` in (".implode(',',$keys).")"
				)
			);

			$results_by_id = [];
			foreach ( $results as $result ) {

				$notification = [
					'id' => $result->id,
					'names' => $result->sender_name,
					'avatar' => $result->sender_avatar,
					'target' => $result->target,
					'message' => $result->message_id,
					'highlight' => $result->highlight,
					'date' => $result->date
				];

				$results_by_id[ (int) $result->id ] = $notification;
			}

			$notifications = [];
			foreach ( $keys as $key ) {
				if ( isset( $results_by_id[ $key ] ) ) {
					$notifications[$key] = $results_by_id[ $key ];
				}
			}

			return $notifications;
		}

	}

	add_filter( 'graphql_data_loaders', function( $loaders, $context ) {
		$loaders['notifications'] = new NotificationsLoader( $context );
		return $loaders;
	}, 10, 2 );

} );

/**
 * Hook into GraphQL Schema initialization to register types
 * and fields to the Schema
 */
add_action( 'graphql_register_types', function() {

	# 1: Register the Type
	register_graphql_object_type( 'Notification', [
		'description' => __( 'Social notification system', 'wp-graphql-Notifications' ),
		'fields' => [
			'id' => [
				'type' => 'ID',
				'description' => __( 'Identifier of the notification', 'wp-graphql' ),
			],
			'names' => [
				'type' => 'String',
				'description' => __( 'names of users that matched same criteria', 'wp-graphql-notification' )
			],
			'avatar' => [
				'type' => 'String',
				'description' => __( 'url of avatar image for initial sender', 'wp-graphql-notification' )
			],
			'target' => [
				'type' => 'String',
				'description' => __( 'onclick target link', 'wp-graphql-notification' )
			],
			'message' => [
				'type' => 'Integer',
				'description' => __( 'ID type of notification', 'wp-graphql-notification' )
			],
			'highlight' => [
				'type' => 'Integer',
				'description' => __( 'ID type of notification', 'wp-graphql-notification' )
			],
			'date' => [
				'type' => 'String',
				'description' => __( 'date & time of notifiation', 'wp-graphql-notification' )
			],
		],
	] );

	# 2: Register the connection
	register_graphql_connection( [
		'fromType' => 'RootQuery',
		'toType' => 'Notification',
		'fromFieldName' => 'notifications',
		'resolve' => function( $source, $args, $context, $info ) {
			$resolver = new NotificationConnectionResolver( $source, $args, $context, $info );
			return $resolver->get_connection();
		}
	]);

} );

