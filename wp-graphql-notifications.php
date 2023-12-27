<?php
/**
 * Plugin Name: WPGraphQL Notifications Example
 * Description: Example plugin showing how to use custom database tables with WPGraphQL
 * Version: 1.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

register_activation_hook( __FILE__, 'wpgraphql_notifications_example_create_notifications_table' );

function wpgraphql_notifications_example_create_notifications_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'notifications';

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id mediumint(9) NOT NULL,
        message text NOT NULL,
        date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

// Uncomment below if you want to input dummy data in the notifications table.
// re-comment or remove once you're done.

//add_action( 'init', function() {
//
//	global $wpdb;
//	$table_name = $wpdb->prefix . 'notifications';
//
//	// Sample data to be inserted
//	$notifications = [
//		['user_id' => 1, 'message' => 'Lorem ipsum dolor sit amet'],
//		['user_id' => 1, 'message' => 'Consectetur adipiscing elit'],
//		['user_id' => 1, 'message' => 'Sed do eiusmod tempor incididunt']
//	];
//
//	// Inserting the data
//	foreach ($notifications as $notification) {
//		$wpdb->insert(
//			$table_name,
//			array(
//				'user_id' => $notification['user_id'],
//				'message' => $notification['message'],
//				'date'    => current_time('mysql') // WordPress function for current date-time
//			),
//			array(
//				'%d', // user_id is an integer
//				'%s', // message is a string
//				'%s'  // date is a string (formatted date)
//			)
//		);
//	}
//
//} );

add_action( 'graphql_register_types', 'wpgraphql_notifications_example_register_types' );

function wpgraphql_notifications_example_register_types() {

	// Register the GraphQL Object Type to the Schema
	register_graphql_object_type( 'Notification', [
		// Be sure to replace your-text-domain for i18n of your plugin
		'description' => __( 'Notification messages for a user', 'your-text-domain' ),
		// By implementing the "Node" interface the Notification Object Type will automaticaly have an "id" field.
		// By implementing the "DatabaseIdentifier" interface, the Notification Object Type will automatically have a "databaseId" field
		'interfaces' => [ 'Node', 'DatabaseIdentifier' ],
		// The fields that can be queried for on the Notification type
		'fields' => [
			'id' => [
				'resolve' => function( $source ) {
					return base64_encode( 'notification:' . $source->id );
				}
			],
			'userDatabaseId' => [
				'type' => 'Int',
				'description' => __( 'The databaseId of the user the message belongs to', 'your-text-domain' ),
			],
			'message' => [
				'type' => 'String',
				'description' => __( 'The notification message', 'your-text-domain' ),
			],
			'date' => [
				'type' => 'String',
				'description' => __( 'The date the message was created', 'your-text-domain' ),
			],
		]
	] );

	register_graphql_connection([
		// The GraphQL Type that will have a field added to it to query a connection from
		'fromType' => 'RootQuery',
		// The GraphQL Type the connection will return Nodes of. This type MUST implement the "Node" interface
		'toType' => 'Notification',
		// The field name to represent the connection on the "from" Type
		'fromFieldName' => 'notifications',
		// How to resolve the connection. For now we will return null, but will visit this below.
		'resolve' => function( $root, $args, $context, $info ) {
			// we will revisit this shortly
			$resolver = new NotificationConnectionResolver( $root, $args, $context, $info );
			return $resolver->get_connection();
		}
	]);

	register_graphql_connection([
		'fromType' => 'Notification',
		'toType' => 'User',
		'fromFieldName' => 'user',
		'oneToOne' => true,
		'resolve' => function( $root, $args, $context, $info ) {
			$resolver = new \WPGraphQL\Data\Connection\UserConnectionResolver( $root, $args, $context, $info );
			$resolver->set_query_arg( 'include', $root->user_id );
			return $resolver->one_to_one()->get_connection();
		}
	]);

	register_graphql_connection([
		'fromType' => 'User',
		'toType' => 'Notification',
		'fromFieldName' => 'notifications',
		'resolve' => function( $root, $args, $context, $info ) {
			$resolver = new NotificationConnectionResolver( $root, $args, $context, $info );
		    $resolver->set_query_arg( 'user_id', $root->databaseId );
	       return $resolver->get_connection();
        }
	]);

}

add_action( 'graphql_init', function() {

	/**
	 * Class NotificationLoader
	 *
	 * This is a custom loader that extends the WPGraphQL Abstract Data Loader.
	 */
	class NotificationLoader extends \WPGraphQL\Data\Loader\AbstractDataLoader {

		/**
		 * Given an array of one or more keys (ids) load the corresponding notifications
		 *
		 * @param array $keys Array of keys to identify nodes by
		 *
		 * @return array|null
		 */
		public function loadKeys( array $keys ): ?array {
			if ( empty( $keys ) ) {
				return [];
			}

			global $wpdb;

			// Prepare a SQL query to select rows that match the given IDs
			$table_name = $wpdb->prefix . 'notifications';
			$ids        = implode( ', ', $keys );
			$query      = $wpdb->prepare( "SELECT * FROM $table_name WHERE id IN ($ids) ORDER BY id ASC", $ids );
			$results    = $wpdb->get_results($query);

			if ( empty( $results ) ) {
				return null;
			}

			// Convert the array of notifications to an associative array keyed by their IDs
			$notificationsById = [];
			foreach ( $results as $result ) {
				// ensure the notification is returned with the Notification __typename
				$result->__typename = 'Notification';
				$notificationsById[ $result->id ] = $result;
			}

			// Create an ordered array based on the ordered IDs
			$orderedNotifications = [];
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $notificationsById ) ) {
					$orderedNotifications[ $key ] = $notificationsById[ $key ];
				}
			}

			return $orderedNotifications;

		}
	}

	// Add the notifications loader to be used under the hood by WPGraphQL when loading nodes
	add_filter( 'graphql_data_loaders', function( $loaders, $context ) {
		$loaders['notification'] = new NotificationLoader( $context );
		return $loaders;
	}, 10, 2 );

	// Filter so nodes that have a __typename will return that typename
	add_filter( 'graphql_resolve_node_type', function( $type, $node ) {
		return $node->__typename ?? $type;
	}, 10, 2 );

});

add_action( 'graphql_init', function() {

	class NotificationConnectionResolver extends \WPGraphQL\Data\Connection\AbstractConnectionResolver {

		// Tell WPGraphQL which Loader to use. We define the `notification` loader that we registered already.
		public function get_loader_name(): string {
			return 'notification';
		}

		// Get the arguments to pass to the query.
		// We're defaulting to an empty array as we're not supporting pagination/filtering/sorting in this example
		public function get_query_args(): array {
			return $this->args;
		}

		// Determine the query to run. Since we're interacting with a custom database Table, we
		// use $wpdb to execute a query against the table.
		// This is where logic needs to be mapped to account for any arguments the user inputs, such as pagination, filtering, sorting, etc.
		// For this example, we are only executing the most basic query without support for pagination, etc.
		// You could use an ORM to access data or whatever else you like here.
		public function get_query(): array|bool|null {
			global $wpdb;
			$current_user_id = get_current_user_id();

			$user_id = $this->query_args['user_id'] ?? $current_user_id;

			$ids_array = $wpdb->get_results(
				$wpdb->prepare(
					sprintf(
						'SELECT id FROM %1$snotifications WHERE user_id=%2$d LIMIT 10',
						$wpdb->prefix,
						$user_id
					)
				)
			);

			return ! empty( $ids_array ) ? array_values( array_column( $ids_array, 'id' ) ) : [];
		}

		// This determines how to get IDs. In our case, the query itself returns IDs
		// But sometimes queries, such as WP_Query might return an object with IDs as a property (i.e. $wp_query->posts )
		public function get_ids(): array|bool|null {
			return $this->get_query();
		}

		// This allows for validation on the offset. If your data set needs specific data to determine the offset, you can validate that here.
		public function is_valid_offset( $offset ): bool {
			return true;
		}

		// This gives a chance to validate that the Model being resolved is valid.
		// We're skipping this and always saying the data is valid, but this is a good
		// place to add some validation before returning data
		public function is_valid_model( $model ): bool {
			return true;
		}

		// You can implement logic here to determine whether or not to execute.
		// for example, if the data is private you could set to false if the user is not logged in, etc
		public function should_execute(): bool {
			return true;
		}

	}

});
