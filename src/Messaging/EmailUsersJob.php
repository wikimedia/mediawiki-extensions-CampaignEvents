<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Messaging;

use InvalidArgumentException;
use MailAddress;
use MediaWiki\JobQueue\Job;
use UserMailer;

class EmailUsersJob extends Job {

	private MailAddress $to;
	private string $subject;
	private string $message;
	private ?MailAddress $replyTo;
	private MailAddress $from;

	/**
	 * @param string $command
	 * @param array $params
	 * @phpcs:ignore Generic.Files.LineLength
	 * @phan-param array{to:array{0:string,1:?string,2:?string},subject:string,message:string,from:array{0:string,1:?string,2:?string},replyTo:?array{0:string,1:?string,2:?string}} $params
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
		$this->to = new MailAddress( ...$params['to'] );
		$this->from = new MailAddress( ...$params['from'] );
		$this->replyTo = $params['replyTo'] ? new MailAddress( ...$params['replyTo'] ) : null;
	}

	public function run(): bool {
		$opts = $this->replyTo !== null
			? [ 'replyTo' => $this->replyTo ]
			: [];
		$status = UserMailer::send(
			$this->to,
			$this->from,
			$this->subject,
			$this->message,
			$opts
		);
		return $status->isGood();
	}
}
