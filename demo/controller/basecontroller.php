<?php
/**
 * @author	jimmy.dong@gmail.com 2012.3.14
 * 
 */

namespace controller;
use yoka\mvc\Controller;

class BaseController extends Controller {
	protected $response;
	
	public function __construct($request, &$response) {
		parent::__construct($request, $response);
	}
	public function before($_controller, $_action){
	}
	
	public function after($_controller, $_action){
	}
}
