<?php
namespace go\modules\community\imapauthenticator\model;

use go\core\jmap\Entity;

class ImapAuthServer extends Entity {
	
	public $id;
	public $imapHostname;
	public $imapPort;
	public $imapEncryption;
	
	public $imapValidateCertificate = true;

	public $removeDomainFromUsername = false;

	public $smtpHostname;
	public $smtpPort;
	public $smtpUsername;
	public $smtpPassword;
	public $smtpUseUserCredentials= false;
	public $smtpValidateCertificate = true;
	public $smtpEncryption;
	
	/**
	 * Users must login with their full e-mail address. The domain part will be used
	 * to lookup this server profile.
	 * 
	 * @var Domain[]
	 */
	public $domains;
	
	/**
	 * New users will be added to these user groups
	 * 
	 * @var Group[]
	 */
	public $groups;
	
	protected static function defineMapping() {
		return parent::defineMapping()
						->addTable('imapauth_server', 's')
						->addRelation("domains", Domain::class, ['id' => "serverId"])
						->addRelation("groups", Group::class, ['id' => "serverId"]);
	}
}
