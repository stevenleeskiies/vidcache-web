<?php
namespace Vidcache\Client;

use \Exception;
use \LSS\Config;
use \LSS\Tpl;
use \LSS\Url;
use \Vidcache\Admin\Client;
use \Vidcache\Client\Session;

abstract class Session extends \LSS\Session {

	public static function init(){
		self::$config_name		= 'client';
		self::$session_name		= Config::get('client','cookie_prefix').'_session';
		self::$session_table	= 'client_session';
		self::$user_primary_key	= 'contact_id';
		self::$urls_nologin		= array(Url::login());
	}

	public static function setCookie($token,$expires=null){
		if(is_null($expires))
			$expires = time()+Config::get('client','cookie_life');
		return setcookie(
			 Config::get('client','cookie_prefix').'_session'
			,$token
			,$expires
			,Config::get('client','cookie_path')
			,COnfig::get('client','cookie_domain')
		);
	}

	public static function destroyCookie(){
		return self::setCookie(null,0);
	}

	public static function setup(){
		if(session_id() != ''){
			//check for session
			try {
				//check for a cookie and start a session
				if(isset($_COOKIE[self::$session_name])){
					session(self::$session_name,$_COOKIE[self::$session_name]);
				}
				//try to see if we can get verified
				if(Session::checkLogin()){
					//register session
					$token = Session::fetchByToken(Session::getTokenFromSession());
					if(!is_array($token))
						throw new Exception('Token could not be found, please login.');
					$session = array_merge(Client::fetchByContact($token['contact_id']),$token);
					Session::storeSession($session);
					unset($session,$token);
					//set tpl globals (if Tpl is available)
					if(is_callable(array('\LSS\Tpl','_get'))){
						Tpl::_get()->set(array(
							 'client'			=>	Session::get()
							,'client_name'		=>	Session::get('name')
							,'client_loggedin'	=>	true
							,'client_free'		=>	true
							,'client_premium'	=>	false
							,'client_lastlogin'	=>	date(Config::get('account','date.general_format'),Session::get('last_login'))
						));
					}
				} else {
					//allow guests
					//if(server('REQUEST_URI') != Url::login()) redirect(Url::login());
				}
			} catch(Exception $e){
				alert($e->getMessage(),false,true);
				Session::tokenDestroy(Session::getTokenFromSession());
				Session::destroySession();
				Session::destroyCookie();
				if(server('REQUEST_URI') != Url::login()) redirect(Url::login());
			}
		}
	}

}

//overrides the parent vars
Session::init();
