<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Automation_Events extends BWFAN_Model {
	static $primary_key = 'ID';

	/**
	 * Insert row
	 *
	 * @param $args
	 *
	 * @return int
	 */
	public static function insert_data( $args ) {
		$event_data = [
			'creation_time'  => current_time( 'mysql', 1 ),
			'execution_time' => current_time( 'timestamp', 1 ) + 30,
			'args'           => wp_json_encode( $args )
		];
		BWFAN_Model_Automation_Events::insert( $event_data );

		return BWFAN_Model_Automation_Events::insert_id();
	}
}
