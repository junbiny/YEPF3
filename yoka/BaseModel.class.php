<?php
/**
 * 实体对象基类
 * @author jimmy.dong@gmail.com
 *
 * 【注意】 使用实体类的约定：
 * 1， 实体对应数据表，主键必须是自增id.否则此基类方法无效 * [可通过pkey自定义主键名称]
 * 2， 子类中update_time 为自动更新timestamp字段，更新时忽略由系统进行更新
 * 3， 子类中定义静态方法 refresh() 用于刷新子类中定制的缓冲
 * 4， 支持SQL兼容及数据库直接操作，但不推荐使用
 * 5， 默认数据为已addslashes处理，否则应添加addslashes标记
 * 6， add/save/update/del 操作失败时返回false， 使用串列写法时请注意！
 * 7， update 默认禁止批量更新。使用$cretia['__FORCE__'] == true可执行批量更新。
 * 8， 手写SQL时不要直接使用表名，使用"%_table_%"。如必须使用表名（如JOIN操作），需在SQL后加上：";#@USE_TABLE_NAME" 标记
 * 9， 可配置默认缓冲。
 *
 * ************************************************** *
 *       重要：复制到其他项目时要修改缓冲配置         *
 * ************************************************** *
 *
 * [更新]
 * 1， 不确定id是否存在的，请使用 geteById() 方法
 * 2， update 更新非本体数据时，需使用 __FORCE__ 参数
 * 3， 高性能开发时请优先使用带缓冲的方法：
 * 			getEntityById()		默认缓冲
 * 			getInstance(true)	参数设定缓冲	
 * 			fetchAllCached()	
 * 4， 默认fetchAll仅获取1000条数据。获取数据较多时，可用fetchAllRaw提高性能(注意返回值不是id作为主键)。
 * 5， 获取大量数据时，应使用 query(xql, true) 方法获取迭代对象进行操作
 * 6,  批量更新较多数据时，应使用 stopAutoRefresh() ... restartAutoRefresh() 屏蔽缓存操作提高效率
 * 7， 增加了字段自动过滤，需在子类中定义 $filter_fields 。注意：仅对 add/save/replace/update 有效
 * 8， 增加snapshop 和 slim 方法。子类中使用 $default_slim 定义默认slim字段
 *
 * [自定义主键]
 * 1， 支持非id作为主键。在子类中定义：static $pkey 。 
 * 2， 原有id为主键的无需任何调整，完全兼容
 * 参见：Pkey.class.php
 *
 */
namespace model;
use yoka\Debug;
use yoka\Cache;
use yoka\Log;
use yoka\DB;
use mdbao\User;

class BaseModel{

	public $db;
	public $entity;
	public static $_EnableBuffer = true;		//防止大数据量处理时内存不足
	public static $_BaseModel_Buffer;			//用于内存缓冲
	public static $_DefaultCacheTime = 3600; 	//默认fetchAllCache缓冲时间，秒
	public static $cacheName = 'default';		//缺省缓冲名称
	public $_stopAutoRefresh = false;			//禁止自动更新，用于批量操作。
	private $ismaster = true; 					//默认使用主库

	// 数据自动过滤机制，在子类设置 $filter_fields 值（需要过滤的字段）
	protected $filter_str = [" ","'","\r","\n","\t",'"', '(', ')', '（', '）', ',', '，', '“', '”'];
	protected $filter_fields = [];

	/**
	 * 实例化
	 * @param int $id 用户ID
	 */
	function __construct($id = null){
		if(\YsConfig::$SLAVE_DB_FIRST === true) $master = false;
		elseif(is_string(\YsConfig::$SLAVE_DB_FIRST)) $master = \YsConfig::$SLAVE_DB_FIRST;
		else $master = true;
		// 保存当前链接使用的是否是主库
		$this->ismaster = $master;
		$this->db = DB::getInstance('default', $this->ismaster);
		if($id){
			$table = static::$table;
			// $pkey = static::$pkey?:'id';
			$pkey = isset(static::$pkey)?static::$pkey:'id';
			$re = $this->db->fetchOne("select * from `{$table}` where %_creteria_%", array($pkey=>$id));
			if($re){
				$this->entity = $re;
			}else{
				$exception = new \Exception("ysdb_{$table} {$id} 实体不存在");
				throw $exception;
			}
		}
		//注意：不传入id时，创建空实体
	}

	/**
	 * 设置从库读取
	 * 注意：
	 * 1，必须在实例化之前执行
	 * 2, 修改后全局影响
	 * 3，建议使用mysql-router
	 * 4，建议使用 fetchAllCached 方法
	 * 5，返回之前设置。
	 * [使用建议]使用前保存设置，使用后恢复原有设置
	 */
	public static function setSlave($flag = true){
		$old_setting = \YsConfig::$SLAVE_DB_FIRST;
		\YsConfig::$SLAVE_DB_FIRST = $flag;
		return $old_setting;
	}

	/**
	 * 强制重新连接数据库
	 * 用于可能运行时间超过mysql超时时间的处理。尤其是守护模式。
	 */
	function db_reconnect(){
		$this->db->reconnect();
	}

	/**
	 * 切换到主库
	 */
	function db_change_master(){
		$this->db = DB::getInstance('default');
	}

	/**
	 * 切换到从库
	 */
	function db_change_slave($name = false){
		$this->db = DB::getInstance('default', $name);
	}

	/**
	 * 切换到统计库(注意：统计专用，会关闭Debug以提高统计速度)
	 */
	function db_change_stat(){
		$this->db = DB::getInstance('default', 'stat');

		//关闭debug提高性能
		\yoka\Debug::stop();
		self::stopAutoRefresh();
		self::$_EnableBuffer = false;
	}
	/**
	 * 设置缓冲实例名称
	 */
	public function setCacheName($name = 'default'){
		self::$cacheName = $name;
	}

	/**
	 * 缓冲获取实例（请留意自动刷新机制）
	 * @param int $id
	 * @param bool $refresh 强制刷新
	 */
	public static function getInstance($id, $refresh = false){
		$table = static::$table;
		$pkey = isset(static::$pkey)?static::$pkey:'id';
		$class = get_called_class();
		$key = "BaseModel_Cache_" . $table . '_' . $id;
		$cache = \yoka\Cache::getInstance(self::$cacheName);
		if(!SiteCacheForceRefresh && !$refresh){
			if(self::$_BaseModel_Buffer[$key]){
				$re = new $class;
				$re->entity = self::$_BaseModel_Buffer[$key];
				return $re;
			}
			$t = $cache->get($key);
			if($t){
				$re = new $class;
				$re->entity = $t;
				if(self::$_EnableBuffer)self::$_BaseModel_Buffer[$key] = $t;
				return $re;
			}
		}
		$re = new $class;
		$entity = $re->db->fetchOne("select * from `{$table}` where %_creteria_%", array($pkey=>$id));
		if($entity){
			$re->entity = $entity;
			if(self::$_EnableBuffer)self::$_BaseModel_Buffer[$key] = $entity;
			$cache->set($key, $re->entity, 3600*4);
			return $re;
		}else{
			return false;
		}
	}

	/**
	 * 获取实例（默认不使用缓冲）
	 * @param int $id
	 * @return self
	 */
	public static function getById($id, $use_cache=false){
		$table = static::$table;
		// $pkey = static::$pkey?:'id';
		$pkey = isset(static::$pkey)?static::$pkey:'id';
		$class = get_called_class();
		$model = new $class();
		if($use_cache){
			$re = self::getEntityById($id);
		}else{
			$re = $model->db->fetchOne("select * from `{$table}` where %_creteria_%", array($pkey=>$id));
		}
		if(!$re) return false;
		else {
			$model->entity = $re;
			return $model;
		}
	}

	/**
	 * 用ID获取Entity（注意：返回为数组，不是对象。默认使用缓冲）
	 *
	 * @param  int $id
	 * @return mixed
	 */
	public static function getEntityById($id, $refresh=false) {
		$obj = self::getInstance($id, $refresh);
		if(!$obj)return false;
		else return $obj->getEntity();
	}


	/**
	 * 取当前对象数据
	 * @return array
	 */
	public function getEntity(){
		return $this->entity;
	}

	/**
	 * 禁止自动执行_refresh，以提高批量处理时的效率
	 */
	public function stopAutoRefresh(){
		$this->_stopAutoRefresh = true;
	}
	/**
	 * 恢复自动执行_refresh
	 */
	public function restartAutoRefresh(){
		$this->_stopAutoRefresh = false;
	}

	/**
	 * 调用子类中的refresh方法【约定：子类中定义static public function refresh()用于刷新自身的特殊缓冲】
	 */
	public function _refresh($class, $id = null){
		//是否被禁止自动更新
		if($this->_stopAutoRefresh) return false;

		if(method_exists($class, 'refresh')){
			//\yoka\Debug::log('BaseModel', $class . '::refresh()');
			$class::refresh($id);
		}
	}

	/**
	 * 添加数据
	 * @param array $arr
	 * @return \model\BaseModel|boolean
	 */
	public function add($arr, $addslashes = false){
		//当前连接非主库，禁止写入
		// modify by bandry 当前链接是否是主库的判断修改
		if(!$this->ismaster){
			\yoka\Debug::flog('BaseModel Error','当前连接非主库，禁止写入');
			\yoka\Debug::log('BaseModel Error','当前连接非主库，禁止写入');
			return false;
		}

		$table = static::$table;
		// $pkey = static::$pkey?:'id';
		$pkey = isset(static::$pkey)?static::$pkey:'id';
		$class = get_called_class();

		//字符自动过滤
		if ($this->filter_fields) {
			foreach ($this->filter_fields as $field) {
				if (isset($arr[$field])) {
					$arr[$field] = str_replace($this->filter_str, '', $arr[$field]);
				}
			}
		}

		if($this->db->insert($table, $arr, $addslashes)){
			$id = $this->db->insertId();
			\yoka\Debug::log('insert-id', $id);
			$this->entity = $arr;
			$this->$pkey = $id;
			$this->_refresh($class, $id); //调用子类中刷新方法
			return $this;
		}else{
			// $this->db->err();
			return false;
		}
	}

	/**
	 * 替换增（不推荐使用。风险：如果字段不全，会导致数据丢失）
	 */
	public function replace($arr, $addslashes, $return_statement = false){
		//当前连接非主库，禁止写入
		// modify by bandry 当前链接是否是主库的判断修改
		if(!$this->ismaster){
			\yoka\Debug::flog('BaseModel Error','当前连接非主库，禁止写入');
			\yoka\Debug::log('BaseModel Error','当前连接非主库，禁止写入');
			return false;
		}

		$table = static::$table;
		$sql = "REPLACE INTO `".$table."` SET " ;

		//字符自动过滤
		if ($this->filter_fields) {
			foreach ($this->filter_fields as $field) {
				if (isset($arr[$field])) {
					$arr[$field] = str_replace($this->filter_str, '', $arr[$field]);
				}
			}
		}

		foreach ($arr as $k => $v)
		{
			if($v === null) $s .= "`{$k}` = NULL,";
			elseif($addslashes) $s .= '`'.$k . "` = '" . addslashes($v) . "',";
			else $s .= '`'.$k . "` = '" . $v . "',";
		}
		$sql .= substr($s, 0, -1);
		return $this->query($sql, $return_statement);
	}


	/**
	 * 删除对象（注意： 原则上应尽量不进行物理删除数据）
	 * 仅删除单个对象，批量删除请使用query
	 * @param string $id
	 * @return boolean
	 */
	public function del($id=null){
		//当前连接非主库，禁止写入
		// modify by bandry 当前链接是否是主库的判断修改
		if(!$this->ismaster){
			\yoka\Debug::flog('BaseModel Error','当前连接非主库，禁止写入');
			\yoka\Debug::log('BaseModel Error','当前连接非主库，禁止写入');
			return false;
		}

		$table = static::$table;
		// $pkey = static::$pkey?:'id';
		$pkey = isset(static::$pkey)?static::$pkey:'id';
		$class = get_called_class();
		$cache = \yoka\Cache::getInstance(self::$cacheName);

		if($id){
			if(! $this->db->delete($table, array($pkey=>$id), true)){
				//操作失败
				return false;
			}
			$this->entity = null;
			$key = "BaseModel_Cache_" . $table . '_' . $id;
			$cache->del($key);
			unset(self::$_BaseModel_Buffer[$key]);
			$this->_refresh($class, $id); //调用子类中刷新方法
			return true;
		}elseif($this->entity[$pkey]){
			if(! $this->db->delete($table, array($pkey=>$this->entity[$pkey]), true)){
				//操作失败
				return false;
			}
			$this->entity[$pkey] = null;
			$key = "BaseModel_Cache_" . $table . '_' . $this->entity[$pkey];
			$cache->del($key);
			unset(self::$_BaseModel_Buffer[$key]);
			$this->_refresh($class, $this->entity[$pkey]); //调用子类中刷新方法
			return true;
		}else{
			$this->db->err('BaseModel Error: delete without ID');
			return false;
		}
	}

	/**
	 * 保存（更新）对象
	 * @return \model\BaseModel
	 */
	public function save($addslashes = false){
		//当前连接非主库，禁止写入
		// modify by bandry 当前链接是否是主库的判断修改
		if (!$this->ismaster) {
			\yoka\Debug::flog('BaseModel Error','当前连接非主库，禁止写入');
			\yoka\Debug::log('BaseModel Error','当前连接非主库，禁止写入');
			return false;
		}

		$table = static::$table;
		// $pkey = static::$pkey?:'id';
		$pkey = isset(static::$pkey)?static::$pkey:'id';
		if(!$this->entity[$pkey])return $this->add($this->entity, $addslashes); //新增
		else return $this->update($this->entity , null, $addslashes); //更新
	}

	/**
	 * 辅助 save() 使用。不建议单独使用。留意 __FORCE__ 用法
	 * @param array $info
	 * @param string $cretia
	 * @return boolean|\model\BaseModel
	 */
	public function update($info, $cretia = null, $addslashes = false){
		//当前连接非主库，禁止写入
		// modify by bandry 当前链接是否是主库的判断修改
		if (!$this->ismaster) {
			\yoka\Debug::log('BaseModel Error','当前连接非主库，禁止写入');
			\yoka\Debug::flog('BaseModel Error','当前连接非主库，禁止写入');
			return false;
		}

		$table = static::$table;
		// $pkey = static::$pkey?:'id';
		$pkey = isset(static::$pkey)?static::$pkey:'id';
		$class = get_called_class();
		$cache = \yoka\Cache::getInstance(self::$cacheName);

		//字符自动过滤
		if ($this->filter_fields) {
			foreach ($this->filter_fields as $field) {
				if (isset($info[$field])) {
					$info[$field] = str_replace($this->filter_str, '', $info[$field]);
				}
			}
		}

		if($cretia){ //条件更新，使用时请谨慎。不指明 __FORCE__ 参数的一律禁用！
			if($cretia['__FORCE__'] == true){	//强制更新
				unset($cretia['__FORCE__']);
				$re = $this->db->update($table, $info, $cretia);
				return $re;
			}else{
				throw new \Exception('错误：不允许修改该对象之外的记录。请参考\$cretia["__FORCE__"]', -1);
				return false;
			}
		}elseif($info[$pkey]){
			//\yoka\Debug::log('update:info', $info);
			/*注意：保护update_time自动变量字段*/
			if(isset($info['update_time']))unset($info['update_time']);

			$re = $this->db->update($table, $info, array($pkey=>$info[$pkey]), true, true, false, 'AND', $addslashes);
			if(! $re){
				//更新操作不成功
				return false;
			}
			$this->fetchOne(array($pkey=>$info[$pkey]));

			$key = "BaseModel_Cache_" . $table . '_' . $info[$pkey];
			$cache->del($key);
			unset(self::$_BaseModel_Buffer[$key]);
			$this->_refresh($class, $info[$pkey]); //调用子类中刷新方法

		}elseif($this->entity[$pkey]){
			/*注意：保护update_time自动变量字段*/
			unset($info['update_time']);
			//\yoka\Debug::log('update:id', $this->entity);
			$re = $this->db->update($table, $info, array($pkey=>$this->entity[$pkey]), true, true, false, 'AND', $addslashes);
			if(! $re){
				//更新操作不成功
				return false;
			}
			$this->fetchOne(array($pkey=>$this->entity[$pkey]));

			$key = "BaseModel_Cache_" . $table . '_' . $this->entity[$pkey];
			$cache->del($key);
			unset(self::$_BaseModel_Buffer[$key]);
			$this->_refresh($class, $this->entity[$pkey]); //调用子类中刷新方法

		}else{
			$this->db->err('BaseModel Error: update without primary key - ' . var_export($info, true));
			return false;
		}
		//\yoka\Debug::log('entity', $this->entity);
		return $this;
	}

	/**
	 * 对字段进行增量操作
	 * @param string $key
	 * @param number $step
	 */
	public function increase($key, $step = 1){
		$table = static::$table;
		// $pkey = static::$pkey?:'id';
		$pkey = isset(static::$pkey)?static::$pkey:'id';
		$class = get_called_class();
		if(!$this->entity[$pkey])return false;
		$step = floatval($step);
		$this->db->query("update `{$table}` set `{$key}` = `{$key}` + {$step} where {$pkey}=" . $this->entity[$pkey]);
		$this->entity = $this->db->fetchOne("select * from `{$table}` where {$pkey}=" . $this->entity[$pkey]);
		
		$cache = \yoka\Cache::getInstance(self::$cacheName);
		$key = "BaseModel_Cache_" . $table . '_' . $this->entity[$pkey];
		$cache->del($key);
		unset(self::$_BaseModel_Buffer[$key]);
		$this->_refresh($class, $this->entity[$pkey]); //调用子类中刷新方法

	}

	/**
	 * 查询单条数据
	 * @param mixed $mix 数组（creteria格式）或 字符串：where条件 或 %_table_% 的全SQL
	 * @param array $assist [order, limit]  eg: ['order'=>'id desc']
	 * @return \model\BaseModel
	 */
	public function fetchOne($mix, $assist = []){
		$table = static::$table;
		if($assist['order'])$order = ' order by '. $assist['order'];
		else $order = '';
		
		if(null == $mix){
			$re = $this->db->fetchOne("select * from `{$table}` {$order} limit 1");
		}elseif(is_array($mix)){ //creteria方式
			$re = $this->db->fetchOne("select * from {$table} where %_creteria_% {$order} limit 1", $mix);
		}elseif (strpos(strtolower(trim($mix)), 'select') === 0) {
			//普通sql方式
			$sql = str_replace('%_table_%', "`{$table}`", $mix);
			$re = $this->db->fetchOne($sql . " {$order} limit 1");
		}else{
			$re = $this->db->fetchOne("select * from {$table} where $mix {$order} limit 1");
		}

		if(!$re)return false;

		$this->entity = $re;
		return $this;
	}
	/**
	 * 根据条件获取记录总数
	 * 注意：只能获取单表记录数
	 * @author bandry
	 * @param $mix $where
	 * @return int 0|total
	 */
	public function count($where = null) {
		$table = static::$table;
		if(null == $where){
			$re = $this->db->fetchOne("select count(1) as cnt from `{$table}` limit 1");
		}elseif(is_array($where)){ //creteria方式
			$re = $this->db->fetchOne("select count(1) as cnt from {$table} where %_creteria_%", $where);
		}else{
			$re = $this->db->fetchOne("select count(1) as cnt from {$table} where $where");
		}
		if(!$re) return 0;
		return $re['cnt'];
	}
	/**
	 * 调试SQL
	 * @param unknown $mix
	 */
	public function sql($mix, $get_retrun = false){
		$table = static::$table;
		if(null == $mix){
			$sql = "select * from `{$table}`";
		}elseif(is_array($mix)){ //creteria方式
			$where = \yoka\DB::_buildQuery($mix);
			$sql = str_replace('%_creteria_%', $where, "select * from {$table} where %_creteria_%");
		}elseif (strpos(strtolower(trim($mix)), 'select') === 0) {
			//普通sql方式
			$sql = str_replace('%_table_%', "`{$table}`", $mix);
		}else{
			//参数是where
			$sql = "select * from {$table} where $mix";
		}
		if($get_retrun){
			return $sql;
		}else{
			$this->db->fquery($sql);
			return true;
		}
	}

	/**
	 * 根据条件获取某一页的数据库记录
	 * @author bandry
	 * @date 2017-04-19
	 * @param mixed $mix null|string|array('col'=>'val', 'col = 1', 'col'=>array(val1,val2))
	 * @param number $page 页码
	 * @param number $num  返回数量
	 * @param string $orderby 排序，不加 order by
	 * @throws \Exception
	 * @return array|bool 数据或false
	 */
	public function fetchPage($mix = null, $page = 1, $num = 20, $orderby = '') {
		$table = static::$table;
		$start = ($page - 1) * $num;
		if ($orderby && strpos($orderby, 'order by') === false) {
			$orderby = ' order by ' . $orderby;
		}

		if (null == $mix) {
			$sql = "select * from {$table} " . " {$orderby} limit {$start}, {$num}";
		} else if (is_array($mix)){ // creteria 方式
			$where = [];
			foreach ($mix as $key=>$val) {
				if (is_numeric($key)) {
					$where[] = $val;
				} else {
					if (is_array($val)) {
						$value = "'" . implode("','", $val) . "'";
						$where[] = "{$key} in ({$value})";
					} else {
						$where[] = "{$key} = '{$val}'";
					}
				}
			}
			$where = implode(' and ', $where);
			$sql = "select * from {$table} where {$where} " . " {$orderby} limit {$start}, {$num}";
		} else if (strpos(strtolower($mix), 'select') === 0) {
			// 普通sql方式
			$sql = str_replace('%_table_%', "`{$table}`", $mix);
			if ($orderby) {
				$sql .= " {$orderby} ";
			}
			$sql .= " limit {$start}, {$num} ";
		} else {
			if (strpos($mix, 'where ') === false) {
				$mix = " where " . $mix;
			}
			$sql = "select * from {$table} " . $mix . " {$orderby} limit {$start}, {$num} ";
		}
		$re = $this->db->fetchAll($sql);
		return $re;
	}
	/**
	 * 批量查询
	 * 【注意】
	 * 		对返回值进行了重整，id为主键。
	 * 		如果不需要主键排序，请使用 fetchAllRaw
	 * 		如果数据量非常大，请使用 query($sql, true)方式
	 * @param mixed $mix 数组（creteria格式）或 where条件 或 %_table_%的全SQL
	 * @param array $assist [order, limit]  eg: ['order'=>'id desc', 'limit'=>100]
	 * 如果在普通SQL使用了order by/limit，则不应设置assist，避免冲突
	 * @return array(array, array ...)
	 */
	public function fetchAll($mix = null, $assist = []){
		$table = static::$table;
		if($assist['order'])$order = ' order by '. $assist['order'];
		else $order = '';
		if($assist['limit'])$limit = ' limit ' . $assist['limit'];
		else $limit = '';
		
		$pkey = isset(static::$pkey)?static::$pkey:'id';
		if(null == $mix){ //获取全部内容 【注意：强制切断1000行，防止崩溃！】
			if($limit == '') $limit = ' limit 1000';
			$re = $this->db->fetchAll("select * from {$table} {$order} {$limit}");
		}elseif(is_array($mix)){ //creteria方式
			$re = $this->db->fetchAll("select * from {$table} where %_creteria_% {$order} {$limit}", $mix);
		}elseif (strpos(strtolower($mix), 'select') === 0) {
			//普通sql方式
			$sql = str_replace('%_table_%', "`{$table}`", $mix);
			$re = $this->db->fetchAll($sql . " {$order} {$limit}");
		}else{
			$re = $this->db->fetchAll("select * from {$table} where $mix {$order} {$limit}");
		}


		$t = current($re);
		if($t[$pkey]){
			//用id作为每行数据的主键
			foreach($re as $v){
				$new_re[$v[$pkey]] = $v;
			}
			return $new_re;
		}else{
			return $re;
		}

	}
	/**
	 * 功能与fetchAll相同，区别在于不做主键重整提高效率
	 * 【注意】
	 * 		一次读出全部数据，不能处理量非常大的数据
	 * 		如果数据量非常大，请使用 query($sql, true)方式
	 * @param mixed $mix 数组（creteria格式）或 where条件 或 %_table_%的全SQL
	 * @param array $assist [order, limit]  eg: ['order'=>'id desc']
	 * 如果在普通SQL使用了order by/limit，则不应设置assist，避免冲突
	 * @return array(array)
	 */
	public function fetchAllRaw($mix = null, $assist = []){
		$table = static::$table;
		if($assist['order'])$order = ' order by '. $assist['order'];
		else $order = '';
		if($assist['limit'])$limit = ' limit ' . $assist['limit'];
		else $limit = '';
		
		if(null == $mix){ //获取全部内容 【注意：强制切断1000行，防止崩溃！】
			if($limit == '') $limit = ' limit 1000';
			$re = $this->db->fetchAll("select * from {$table} limit 1000");
		}elseif(is_array($mix)){ //creteria方式
			$re = $this->db->fetchAll("select * from {$table} where %_creteria_% {$order} {$limit}", $mix);
		}elseif (strpos(strtolower($mix), 'select') === 0) {
			//普通sql方式
			$sql = str_replace('%_table_%', "`{$table}`", $mix);
			$re = $this->db->fetchAll($sql . " {$order} {$limit}");
		}else{
			$re = $this->db->fetchAll("select * from {$table} where $mix {$order} {$limit}");
		}

		return $re;
	}
	/**
	 * 带缓存的批量查询
	 * 【注意】
	 *		$this->_DefaultCacheTime 控制缓存有效时间
	 *		强制使用从库，请确保从库数据可用
	 *		仅对多次重复查询有效
	 *		如果数据量大，请使用 query($sql, true) 方式，防止堵塞Cache
	 * @param mixed $mix 数组（creteria格式）或 where条件 或 %_table_%的全SQL
	 * @param array $assist [order, limit]  eg: ['order'=>'id desc']
	 * 如果在普通SQL使用了order by/limit，则不应设置assist，避免冲突
	 * @return array(array)
	 */
	public function fetchAllCached($mix = null, $assist = []){
		$table = static::$table;
		$key = 'fetchAll_' . $table  . '_' . md5(json_encode($mix).json_encode($assist));
		$cache = \yoka\Cache::getInstance(self::$cacheName);
		if(!SiteCacheForceRefresh){
			$re = $cache->get($key);
			if($re)return $re;
		}
		// 强制使用从库
		$this->db = DB::getInstance('default', true);
		$re = $this->fetchAll($mix, $assist);
		$cache->set($key, $re, self::$_DefaultCacheTime);
		// 恢复
		$this->db = DB::getInstance('default', $this->ismaster);
		return $re;
	}

	/**
	 * 对fetchAll返回结果进行排序
	 * @param array $data 待排序二维数组
	 * @param string $col 排序的字段
	 * @param mix $sort 默认正序。倒序： -1,DESC,SORT_DESC
	 * @param string $type SORT_REGULAR, SORT_NUMBER, SORT_STRING
	 */
	public static function sort($data, $col='id', $sort='', $type=''){
		if($sort == -1 || strtolower($sort) == 'desc' || $srot == SORT_DESC || strtolower($sort) == 'sort_desc')$sort = SORT_DESC;
		else $sort = SORT_ASC;
		foreach($data as $key=>$val){
			$tmp[$key] = $val[$col];
		}
		if($type){
			if(array_multisort($tmp,$sort,$type,$data))return $data;
			else return false;
		}else{
			if(array_multisort($tmp,$sort,$data))return $data;
			else return false;
		}
	}
	/**
	 * 从游标取一条数据
	 * @param unknown $mix
	 */
	public function fetch(){
		return $this->db->fetch();
	}

	/**
	 * 查询（默认返回true/false）
	 * 注意： 传入$return_statement=true，其结果foreach迭代出的结果数组，不是对象本身
	 * $admin = new User();
	 * foreach($db->query( array('username'=>array('like'=>'test')) , true) as $row){ ... }
	 */
	public function query($mix, $return_statement = false){
		$table = static::$table;
		if(is_array($mix)){
			$where = \yoka\DB::_buildQuery($creteria, $trim, $strict, $connector, $addslashes);
			$sql = "select * from `{$table}` " . $where;
		}else{
			$sql = str_replace('%_table_%', "`{$table}`", $mix);
		}
		$re = $this->db->query($sql, $return_statement);
		return $re;
	}

	/**
	 * 魔术方法
	 */
	public function __get($key){
		return $this->entity[$key];
	}
	public function __set($key, $value){
		$this->entity[$key] = $value;
		return true;
	}

	/**
	 * 快捷填写
	 */
	public function autoFit($list, $request){
		foreach($list as $k){
			if($request->$k) $this->__set($k, $request->$k);
		}
	}

	/**
	 * 根据配置，进行信息精简，去掉已设置的不输出的信息
	 * @param array $info 对应对象的数据
	 */
	public static function simpleinfo($info) {
		if (isset(static::$simple_cols)) {
			//\yoka\Debug::log('static::$simple_cols', static::$simple_cols);
			foreach (static::$simple_cols as $col) {
				unset($info[$col]);
			}
		}
		return $info;
	}
	/**
	 * 从model的query方法结果集中获取数组结果
	 * @param unknown $result
	 * @return multitype:multitype:unknown
	 */
	public static function getListFromResult($result) {
		$list = array ();
		foreach ( $result as $row ) {
			$data = array ();
			foreach ( $row as $key => $val ) {
				if (is_numeric ( $key )) {
					continue;
				}
				$data [$key] = $val;
			}
			$list[] = $data;
		}
		return $list;
	}
	/**
	 * 获取快照【注意：与slim的区别】
	 */
	public function snapshot($id = null){
		$class = get_called_class();
		$pkey = isset(static::$pkey)?static::$pkey:'id';
		if($id){
			$this->fetchOne(array($pkey=>$id));
			return $class::slim($this->entity);
		}elseif($this->entity){
			return $class::slim($this->entity);
		}else{
			return false;
		}
	}

	/**
	 * 获取摘要信息
	 * @param array $info
	 * @param array $slim 保留的项
	 */
	public static function slim($info, $slim = null){
		$class = get_called_class();
		if($slim == null){
			if(isset($class::$default_slim))$slim = $class::$default_slim;
			else return $info;
		}
		foreach($slim as $key){
			$re[$key] = $info[$key];
		}
		return $re;
	}

}
