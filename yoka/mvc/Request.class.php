<?php
namespace yoka\mvc;

use \Exception;

class Request implements \Iterator{
	private static $_instance;
	/**
	 * 允许修改传入参数
	 */
	public $allowModify = false;
	/**
	 * 自动进行add_slashes
	 */
	public static $FLAG_MAGIC_QUOTES = true;
	/**
	 * for Iterator
	 */
	private $position = 0; 			//for iterator
	private $entity = [];			//for iterator
	
	
	private function __construct() {
		$this->position = 0;		//for iterator
		foreach($_POST as $k=>$v){
			$this->entity[] = ['key'=>$k, 'val'=>$v];
		}
		foreach($_GET as $k=>$v){
			$this->entity[] = ['key'=>$k, 'val'=>$v];
		}
	}
	
	/**
	 * 单例
	 * @return \yoka\mvc\Request
	 */
	public static function getInstance() {
		if (!self::$_instance)
			self::$_instance = new self();
		return self::$_instance;
	}

	public function get($index = '', $default='') {
        if($index === '' || $default === '')
            return $_GET;
		return isset($_GET[$index])? $_GET[$index]:$default;
	}

	public function post($index, $default='') {
		if($index == '' || $default == '')
			return $_POST;
		return isset($_POST[$index])? $_POST[$index]:$default;
	}
	
	/**
	 * 返回magic_quotes处理后的传入
	 * @param unknown $index
	 * @param string $default
	 */
	public function getRequest($index, $default='') {
		$re = isset($_REQUEST[$index])? $_REQUEST[$index]:$default;
		
		if(get_magic_quotes_runtime() == false)return $this->_addslashesRecursive($re);
		else $re;
		
	}
	
	public function cookie($index) {
		return isset($_COOKIE[$index])? $_COOKIE[$index]:'';
	}
	
	/**
	 * 修改值
	 * @param array $array
	 */
	public function set(array $array){
		$this->allowModify = true;
		foreach ($array as $k=>$v) $this->$k = $v;
		$this->allowModify = false;
	}
	/**
	 * 别名函数
	 * @param array $array
	 */
	public function ModifyGet(array $array) {
		$this->set($array);
	}
	
	
	/**
	 * 用于判断参数是否传入
	 * (注意：不同于is_null() —— !isset() 和 null 都返回true)
	 * @param string $key
	 */
	public function isNull($key){
		if($key === '' || $key === '') return true;
		if(isset($_GET[$key]))return false;
		elseif(isset($_POST[$key]))return false;
		else return true;
	}
	public function notNull($key){
		if($this->isNull($key))return false;
		else return true;
	}
	
	/**
	 * 获取非magic_quotes状态的数据
	 * Enter description here ...
	 * @param unknown_type $key
	 * @param unknown_type $default
	 */
	public function getUnMagic($key, $default=''){
		$data = $this->getRequest($key, $default);
		if(get_magic_quotes_runtime() == false && defined('YEPF_VERSION'))return $data;	//注：引入YEPF时会自动加magci_quotes
		else return $this->_stripslashesRecursive($data);
	}
	public function getNoMagic($key, $default=''){
		return $this->getUnMagic($key, $default);
	}
	public function unMagic($key, $default=''){
		return $this->getUnMagic($key, $default);
	}
	public function noMagic($key, $default=''){
		return $this->getUnMagic($key, $default);
	}
	public function _stripslashesRecursive($data){
		if(is_array($data)){
			foreach($data as $key=> $val){
				$re[$key] = self::_stripslashesRecursive($val);
			}
			return $re;
		}else return stripslashes($data);
	}
	public function _addslashesRecursive($data){
		if(is_array($data)){
			foreach($data as $key=> $val){
				$re[$key] = self::_addslashesRecursive($val);
			}
			return $re;
		}else return addslashes($data);
	}
	
	public function __get($key) {
		if(self::$FLAG_MAGIC_QUOTES === false)return $this->getUnMagic($key);
		return $this->getRequest($key);
	}

	public function __set($k, $v) {
		if ($this->allowModify)
			$this->$k = $v;
		else
			throw new Exception('不允许修改!');
		
	}
	
	/* Iterator 方法 */
	public function current (){
		return $this->entity[$this->position]['val'];
	}
	public function key (){
		return $this->entity[$this->position]['key'];
	}
	public function next (){
		++$this->position;
	}
	public function rewind (){
		$this->position = 0;
	}
	public function valid (){
		return isset($this->entity[$this->position]);
	}
}
