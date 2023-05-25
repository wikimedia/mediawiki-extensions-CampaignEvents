<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Messaging;

use InvalidArgumentException;
use Job;
use MailAddress;
use UserMailer;

class EmailUsersJob extends Job {

	/** @var MailAddress */
	private MailAddress $to;
	/** @var string */
	private string $subject;
	/** @var string */
	private string $message;
	/** @var MailAddress */
	private MailAddress $replyTo;
	/** @var MailAddress */
	private MailAddress $from;

	/**
	 * @param string $command
	 * @param array $params
	 */
	public function __construct( $command, array $params ) {
		parent::__construct( $command, $params );
		static $required = [ 'to', 'subject', 'message', 'from', 'replyTo' ];
		$missing = implode( ', ', array_diff( $required, array_keys( $params ) ) );
		if ( $missing !== '' ) {
			throw new InvalidArgumentException( "Missing parameter(s) $missing" );
		}
		$this->removeDuplicates = true;
		$this->command = $command;
		$this->subject = $params['subject'];
		$this->message = $params['message'];
		$this->to = $params['to'];
		$this->from = $params['from'];
		$this->replyTo = $params['replyTo'];
	}

	public function run() {
		$status = UserMailer::send(
			$this->to,
			$this->from,
			$this->subject,
			$this->message,
			[ 'replyTo' => $this->replyTo ]
		);
		return $status->isGood();
	}
}
