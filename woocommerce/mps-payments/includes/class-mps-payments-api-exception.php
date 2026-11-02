<?php
/**
 * A call the MPS gateway answered with something other than 2xx.
 *
 * @package MpsPayments
 */

defined( 'ABSPATH' ) || exit;

/**
 * The HTTP status is the code; what MPS said about the request, field by field, comes with it, so a
 * refusal can be shown to the merchant in MPS's own words.
 */
class Mps_Payments_Api_Exception extends Exception {

	/**
	 * The messages of a 400's errors, every field's in turn.
	 *
	 * @var string[]
	 */
	private $errors;

	/**
	 * A refusal.
	 *
	 * @param string   $message What failed, for the log and the settings screen.
	 * @param int      $status  The HTTP status MPS answered.
	 * @param string[] $errors  What MPS said was wrong, when it said.
	 */
	public function __construct( $message, $status, array $errors = array() ) {
		parent::__construct( $message, $status );
		$this->errors = $errors;
	}

	/**
	 * What MPS said was wrong; empty when it said nothing.
	 *
	 * @return string[]
	 */
	public function get_errors() {
		return $this->errors;
	}
}
