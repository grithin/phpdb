<?php
namespace Grithin;
use Grithin\Arrays;
use Grithin\Tool;
use Grithin\Debug;
/**Class details:
	- @warning Class sets sql_mode to ansi sql if mysql db to allow interroperability with postgres.	As such, double quotes " become table and column indicators, ` become useless, and single quotes are used as the primary means to quote strings

	@NOTE Most of the querying methods are overloaded; there are two forms of possible input:
		-	straight sql: $x = Db::row('select * from inquiry where id = 6');
		-	prepared statement: $x = Db::row(['select * from inquiry where id = :id',['id'=>7]]);
			-	see self::prepare
		-	table and dictionary: #$x = Db::row('inquiry', ['id'=>7]);
			-	see self::select

	@NOTE public $under, the underlying PDO instance, set on lazy load

	Example, Static Use
		Db::init(null,$config);
		Db::row('select * from user');
	Example, Instance Use
		$db = Db::init(null,$config);
		$db->row('select * from user');

	Examples, external instance
		$db = Db::singleton($config);

		# Case 1, using a separate loader
		$loader = function() use ($db){
			$db->load_once(); # ensure 1st db is loaded
			return $db->under; # return the PDO instance of 1st db
		};
		$db2 = Db::init('num 2')->with_loader($loader);
		$x = $db2->row('select * from inquiry');

		# Case 2, using an existing PDO instance
		$db3 = Db::init('num 3')->with_pdo($db2->under);
		$x = $db3->row('select * from inquiry');
*/
/* Usage Notes
	On the methods executing an input and formating results, they accept multiple types of input (see doc on `row`).  There are 3 primary types:
	1.	plain full sql
	2.	prepared statement (wrapped in an array with variables like `['select bob from bob where bob = ?', [1]]`)
	3.	interpretted variables (see `select`), of the parameter form `($from,$where=null,$columns='*',$order=null,$limit=null)`
*/

Class Db{
	use \Grithin\Traits\SDLL;
	/// latest result set returning from $db->query()
	public $result;
	/// last method call, args, and last sql thing (which might be SQL string + variables, or just SQL string). Ex  [call:[fn,args],sql:sql]
	public $last;
	/// boolean indicating whether the last statement failed, and currently attempting to reconnect to database and run again
	public $reconnecting = false;

	/*
	@param	connectionInfo	{driver:, database:, host:, user:, password:,}
	@param	options	{
			loader: < external loader function that returns a PDO instance and is given params ($dsn, $user, $password)  >
			sql_mode: < blank or `ANSI`.  defaults to `ANSI`.  Controls quote style of ` or " >
		}
	*/
	function __construct($connectionInfo=[], $options=[]){
		$this->connectionInfo = $connectionInfo;
		$this->quote_style = '`';
		$this->options = array_merge(['sql_mode'=>'ANSI'], $options);;
	}

	function load(){
		if(!$this->connectionInfo['dsn']){
			$this->connectionInfo['dsn'] =  $this->makeDsn($this->connectionInfo);
		}
		try{
			if($this->options['loader']){
				$this->under = $this->options['loader']($this->connectionInfo);
				if(!$this->under || !($this->under instanceof \PDO)){
					throw new \Exception('Loader function did not provide PDO instance');
				}
			}else{
				$this->under = new \PDO($this->connectionInfo['dsn'], $this->connectionInfo['user'], $this->connectionInfo['password']);
				$this->under->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

			}
		}catch(\PDOException $e){
			if($this->connectionInfo['backup']){
				$this->connectionInfo = $this->connectionInfo['backup'];
				$this->load();
				return;
			}
			throw $e;
		}
		$this->driver = $this->under->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if($this->driver=='mysql'){
			if($this->options['sql_mode'] == 'ANSI'){
				$this->query('SET SESSION sql_mode=\'ANSI\'');
				$this->quote_style = '"';
			}

			$this->query('SET SESSION time_zone=\'+00:00\'');
			#$this->under->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
		}
	}
	# load db with existing pdo instance
	function with_pdo($pdo){
		$this->loaded = true;
		$this->under = $pdo;
		return $this;
	}
	# load db with separate loader
	function with_loader($loader){
		$this->options['loader'] = $loader;
		return $this;
	}

	function makeDsn($connectionInfo){
		$connectionInfo['port'] = $connectionInfo['port'] ? $connectionInfo['port'] : '3306';
		return $connectionInfo['driver'].':dbname='.$connectionInfo['database'].';host='.$connectionInfo['host'].';port='.$connectionInfo['port'];
	}
	# the overhead is worth the expectation
	function make_dsn(){
		return call_user_func_array([$this,'makeDsn'], func_get_args());
	}

	function public_info(){
		$info = Arrays::pick($this->connectionInfo, ['driver', 'database', 'host', 'port']);
		$info['driver'] = $this->driver;
		$info['class'] = __CLASS__;
		return $info;
	}
	function __toArray(){
		return $this->public_info();
	}
	function __toString(){
		return var_export($this->public_info(),true);
	}

	function __testCall($fnName, $args){
		if(!method_exists($this,$fnName)){
			Debug::toss(get_called_class().' Method not found: '.$fnName);
		}
		$this->last['call'] = [$fnName,$args];
		return call_user_func_array(array($this,$fnName),$args);
	}

	public $quote_cache = []; #< since quote with Db function may involve request to Db, to minimize requests, cache these
	/// returns escaped string with quotes.	Use on values to prevent injection.
	/**
	@param	v	the value to be quoted
	*/
	protected function quote($v, $use_cache=true){
		if(is_numeric($v)){ # numerics don't need quoting
			return $v;
		}
		if(!Tool::is_scalar($v)){
			$v = (string)$v;
		}

		# caching
		if($use_cache && strlen($v)<=250){ # no reason to expect multiple occurrence of same long text quotes
			if(!$this->quote_cache[$v]){
				$this->quote_cache[$v] = $this->under->quote($v);
			}
			return $this->quote_cache[$v];
		}
		return $this->under->quote($v);
	}
	# handles [a-z9-9_] style identities without asking Db to do the quoting
	protected function quoteIdentity($identity,$separation=true){
		if($this->driver == 'sqlite'){ # doesn't appear to accept seperation
			if(strpos($identity,'.')!==false){
				# sqlite doesn't handle assigning . quoted columns on results, so just ignore and hope nothing cause syntax error
				return $identity;
			}
		}
		$quote = $this->quote_style;
		$identity = $quote.$identity.$quote;
		#Fields like user.id to "user"."id"
		if($separation && strpos($identity,'.')!==false){
			$identity = implode($quote.'.'.$quote,explode('.',$identity));
		}
		return $identity;
	}
	# alias
	protected function quote_identity(){
		$alias = 'quoteIdentity';
		return call_user_func_array([$this,$alias], func_get_args());
	}
	/// return last run sql
	protected function lastSql(){
		if(!is_string($this->last['sql'])){
			return json_encode($this->last['sql']);
		}else{
			return $this->last['sql'];
		}
	}
	# the overhead is worth the expectation
	protected function last_sql(){
		return call_user_func_array([$this,'lastSql'], func_get_args());
	}
	/// perform database query
	/**
	@param	sql	the sql to be run
	@return the executred PDOStatement object
	*/
	protected function query($sql){
		# clear opened, unclosed cursors, if any
		if($this->result){
			$this->result->closeCursor();
		}

		# Generate a prepared statement
		if(is_array($sql)){
			$sql = $this->prepare($sql);
		}

		if(is_a($sql, \PDOStatement::class)){
			$this->last['sql'] = [$sql->queryString, $sql->variables];
			try{
				$success = $sql->execute($sql->variables);
			}catch(\Exception $e){}

			if(!$success){
				$this->handle_error($sql);
			}
			$this->result = $sql;
		}else{
			$this->last['sql'] = $sql;
			$this->result = $this->under->query($sql);
		}

		$this->handle_error($this->under);

		if(!$this->result){
			$this->result = $this->retry(__FUNCTION__, [$sql]);
		}
		return $this->result;
	}

	# Conform some inptu to a psql
	/* params
	< input >
		< sql string >
		|
		[ (< sql string > | < variables >), ... ]
	*/
	static function psql($input, $combine = "\n"){
		if(is_string($input)){
			return [$input, []];
		}elseif(is_array($input)){
			$sql = [];
			$variables = [];
			foreach($input as $v){
				if(is_string($v)){
					$sql[] = $v;
				}else{
					if(!is_array($v)){
						throw new \Exception('Non-conforming psql input');
					}
					$variables = array_merge($variables, $v);
				}
			}
			$sql = implode($combine, $sql);
			return [$sql, $variables];
		}else{
			throw new \Exception('Unrecognized input');
		}
	}

	# Combined psqls (`[< sql >, < variables >]`) into a single psql
	/* params
	< psqls > [
			(< psql > | < sql >), ...
		]
	< combine > < "" | "OR" | "AND" > < the way to combined the SQL string >
	*/
	/* definitions
	psql: [
			< sql >
			< variables > [
				< variable >, ...
			]
		]
	*/

	/* notes
	-	Can use `:x` or `?`
	-	function does not prefix dictionary keys with `:` since a statement with `where id = :id` will accept either [':id'=>1] or ['id'=>1]
	-	on nulls: `null` is properly filled, but still will not work in conventional parts:
		`['id is ?', [null]]` works
		`['id = ?', [null]]` works, but fails to find anything
		`['id in (?)', [null]]` works, but fails to find anything
		-	for lists including null, must separate:
			`['id in (?, ?) or id is ?', [1, 2, null]]`
	*/
	/* Example: combining wheres
	(	[psql, psql], ' AND ' 	)
	*/
	static function psqls($psqls, $combine="\n"){
		$sql = [];
		$variables = [];
		foreach($psqls as $psql){
			list($psql_sql, $psql_variables) = self::psql($psql);
			if($psql_sql){
				$sql[] = $psql_sql;
			}
			if($psql_variables){
				$variables = array_merge($variables, $psql_variables);
			}
		}
		$sql = implode($combine, $sql);
		# ensure no non-scalar variables

		foreach($variables as $variable){
			# if this is a non-scalar and does not have a __toString method, error
			if(!Tool::is_scalar($variable) &&  ! (is_object($variable) && method_exists($variable, '__toString')) ){
				throw new \Exception('Non scalar SQL statement variable: '.var_export($variable, true));
			}
		}
		return [$sql, $variables];
	}

	# Generally used for combining WHERE psql sets
	static function psqls_anded($psqls){
		return self::psqls($psqls, "\n\tAND ");
	}

	# @deprecated, use psqls
	static function compile_where_sets($sets){
		return self::psqls_anded($sets);
	}

	# @deprecated, use psqls
	static function sql_and_variables(){
		return call_user_func([__CLASS__, 'psqls'], func_get_args());
	}

	# @deprecated, use psqls
	static function psql_combine($psqls, $combine = ''){
		return call_user_func([__CLASS__, 'psqls'], $psqls, $combine);
	}
	# @deprecated, use psqls
	static function sql_and_variable_sets_combine(){
		$args = func_get_args();
		if(count($args) == 1 && is_array($args[0])){
			$args = $args[0];
		}
		return call_user_func([__CLASS__, 'psqls'], $args);
	}

	# runs self::psqls, creats a PDOStatement, sets a custom `variables` attribute of the PDOStatement object, returning that PDOStatement
	protected function prepare(){
		list($sql, $variables) = call_user_func([$this, 'psqls'], func_get_args());

		if($this->result){
			$this->result->closeCursor();
		}

		$this->last['sql'] = $sql;
		$prepared = $this->under->prepare($sql, array(\PDO::ATTR_CURSOR => \PDO::CURSOR_FWDONLY));

		$this->handle_error($this->under);

		if(!$prepared){
			$prepared = $this->retry(__FUNCTION__, func_get_args());
		}
		$prepared->variables = $variables; # custom attribute for later binding
		return $prepared;
	}
	/*
		PDOStatement and PDO object both have `errorCode` and `errorInfo`, and a statement may have an error without showing up in the PDO object.
	*/
	protected function handle_error($errorable=null, $additional_info=false){
		if((int)$errorable->errorCode()){
			$error = $errorable->errorInfo();
			$error = "--DATABASE ERROR--\n".' ===ERROR: '.$error[0].'|'.$error[1].'|'.$error[2];
			if($additional_info){
				$error .= "\n===ADDITIONAL: ".$additional_info;
			}
			$error .= "\n ===SQL: ".$this->lastSql();
			Debug::toss($error, 'DbException');
		}
	}
	protected function retry($function, $arguments){
		if($this->reconnecting){
			Debug::toss("--DATABASE ERROR--\nNo result, likely connection timeout", 'DbException');
		}
		$this->reconnecting = true;
		$this->load();
		$return = call_user_func_array([$this, $function], $arguments);
		$this->reconnecting = false;
		return $return;
	}

	/// Used for prepared statements, returns raw PDO result
	// Ex: $db->as_rows($db->exec('select * from languages where id = :id', [':id'=>181])
	/*

	@return	executed PDOStatement

	Takes a mix of sql strings and variable arrays, as either a single array parameter, or as parameters
	Examples
		-	single array: $db->exec(['select * from user where id = :id',[':id'=>1]])
		-	as params: $db->exec('select * from','user where id = :id',[':id'=>1],'and id = :id2',[':id2'=>1] );
	*/
	protected function exec(){
		return $this->query(call_user_func_array([$this,'prepare'], func_get_args()));
	}


	/// Used internally.	Checking number of arguments for functionality
	protected function getOverloadedSql($expected, $actual){
		$count = count($actual);
		$overloaded = $count - $expected;

		if($overloaded > 0){
			//$overloaded + 1 because the expected $sql is actually one of the overloading variables
			$overloaderArgs = array_slice($actual,-($overloaded + 1));
			$sql = call_user_func_array(array($this,'select'),$overloaderArgs);
		}else{
			$sql = end($actual);
			if(is_string($sql) && preg_match('@^[^\s]+$@', $sql)){
				# appears to be just a table name, so format it
				$sql = 'select * from '.self::quote_identity($sql);
			}
		}
		return $sql;
	}

	static function sql_is_limited($sql){
		return preg_match('@[\s]*show|limit\s*[0-9]+(,\s*[0-9]+)?@i',$sql);
	}

	protected function applyLimitOne($sql){
		return self::limit_apply($sql, 1);
	}
	protected function limit_apply($sql, $limit){
		#++ handle prepared statement type sql argument {
		if(is_array($sql)){
			$sql = $this->psql($sql);
			if(!self::sql_is_limited($sql[0])){
				$sql[0] .= "\nLIMIT ".$limit;
			}
			return $sql;
		}
		#++ }
		# handle normal string sql argument {
		if(!self::sql_is_limited($sql)){
			$sql .= "\nLIMIT ".$limit;	}
		return $sql;
		#++ }
	}

	protected function as_conform_res($args){
		if(!is_a($args[0], \PDOStatement::class)){
			$args[0] = call_user_func_array([$this,'exec'], $args);
		}
		return $args[0];
	}


	/// query returning a column value
	/**See class note for input
	@warning "limit 1" is appended to the sql input
	@return	one column
	*/
	#@note	returns `false` if no match
	protected function value(){
		$sql = $this->getOverloadedSql(1,func_get_args());
		#function implies only 1 retured row
		$sql = self::applyLimitOne($sql);

		return $this->as_value($this->query($sql));
	}
	protected function as_value($res){
		$res = $this->as_conform_res(func_get_args());
		return	$res->fetchColumn();
	}

	/// query returning a row
	/**See class note for input
	@warning "limit 1" is appended to the sql input
	@return	a single row

	Examples:
	-	straight sql: $x = Db::row('select * from inquiry where id = 6');
	-	prepared statement: $x = Db::row(['select * from inquiry where id = :id',['id'=>7]]);
	-	prepared statement: $x = Db::row(['select * from inquiry where id = :id',[':id'=>7]]);
	-	prepared statement: $x = Db::row(['select * from inquiry where id = ?',[7]]);
	-	table and dictionary: #$x = Db::row('inquiry', ['id'=>7]);
	*/
	#@note	returns `false` if no match
	protected function row(){
		$sql = $this->getOverloadedSql(1,func_get_args());
		#function implies only 1 retured row
		$sql = self::applyLimitOne($sql);

		return $this->as_row($this->query($sql));
	}
	protected function as_row($res){
		$res = $this->as_conform_res(func_get_args());
		return $res->fetch(\PDO::FETCH_ASSOC);
	}
	/// query returning multiple rows
	/**See class note for input
	@return	a sequential array of rows
	*/
	#@note	returns `[]` if no match
	protected function rows($sql){
		$sql = $this->getOverloadedSql(1,func_get_args());
		return $this->as_rows($this->query($sql));
	}
	protected function as_rows($res){
		$res = $this->as_conform_res(func_get_args());
		$res2 = array();
		$i = 0;
		while($row=$res->fetch(\PDO::FETCH_ASSOC)){
			foreach($row as $k=>$v){
				$res2[$i][$k]=$v;	}
			$i++;	}
		return $res2;	}

	# get all records
	protected function all($table){
		$args = func_get_args();
		array_splice($args, 1, 0, '1=1');
		return call_user_func_array([$this,'rows'], $args);
	}

	/// query returning a column
	/**
	See class note for input
	@return	array where each element is the column value of each row.  If multiple columns are in the select, just uses the first column
	*/
	protected function column($sql){
		$sql = $this->getOverloadedSql(1,func_get_args());
		return $this->as_column($this->query($sql));
	}
	protected function as_column($res){
		$res = $this->as_conform_res(func_get_args());
		while($row=$res->fetch(\PDO::FETCH_NUM)){$res2[]=$row[0];}
		if(!is_array($res2)){
			return array();
		}
		return $res2;
	}

	/// query returning columns keyed their numeric position
	/**
	See class note for input
	@return	array where each element is the column value of each row.  If multiple columns are in the select, just uses the first column
	*/
	protected function columns($sql){
		$sql = $this->getOverloadedSql(1,func_get_args());
		return $this->as_columns($this->query($sql));
	}
	protected function as_columns($res){
		$res = $this->as_conform_res(func_get_args());
		while($row=$res->fetch(\PDO::FETCH_NUM)){$res2[]=$row;}
		if(!is_array($res2)){
			return array();
		}
		return $res2;
	}

	/// query returning number indexed array
	/**See class note for input
	@return	row as numerically indexed array for potential use by php list function
	*/
	protected function enumerate($sql){
		$sql = $this->getOverloadedSql(1,func_get_args());
		$sql .= "\nLIMIT 1";
		return $this->as_enumerate($this->query($sql));
	}
	protected function as_enumerate($res){
		$res = $this->as_conform_res(func_get_args());
		return $res->fetch(\PDO::FETCH_NUM);
	}


	/// query returning a column with keys
	/**See class note for input
	@param	key	the column key to be used for each element.	If they key is an array, the first array element is taken as the key, the second is taken as the mapped value column
	@return	array where one column serves as a key pointing to either another column or another set of columns
	*/

	protected function columnKey($key,$sql){
		$arguments = func_get_args();
		array_shift($arguments);
		$rows = call_user_func_array(array($this,'rows'),$arguments);
		if(is_array($key)){
			$key_name = $key['key'] ? $key['key'] : $key[0];
			$only_value = $key['value'] ? $key['value'] : $key[1];
			return Arrays::key_on_sub_key_to_remaining($rows, $key_name, ['only'=>$only_value]);
		}else{
			return Arrays::key_on_sub_key($rows, $key);
		}
	}
	# alias; the overhead is worth the expectation
	protected function column_key(){
		return call_user_func_array([$this,'columnKey'], func_get_args());
	}

	protected function rows_by_column($key, $sql){
		$arguments = func_get_args();
		array_shift($arguments);
		$rows = call_user_func_array(array($this,'rows'),$arguments);
		return Arrays::key_on_sub_key($rows, $key);
	}

	///Key to value formatter (used for where clauses and updates)
	/**
	@param	kvA	various special syntax is applied:
		normally, sets key = to value, like "key = 'value'" with the value escaped
		if key starts with '"', unescaped value taken as entire where line
			ex: ['"':'1=1']
		if "?" is in the key, the part after the "?" will serve as the "equator", ("bob?<>"=>'sue') -> "bob <> 'sue'"
		if key starts with ":", value is not escaped
			if value is null, set string to "null"
			if value is string "null", where is prefixed with "is".
		if value = null, set value to unescaped "null"
	@param	type	1 = where, 2 = update
	*/
	protected function ktvf($kvA,$type=1){
		foreach($kvA as $k=>$v){
			$line = $this->ftvf($k,$v,$type);
			 if($line){
				 $kvtA[] = $line;
			 }
		}
		return (array)$kvtA;
	}
	///Field to value formatter (used for where clauses and updates)
	protected function ftvf($field,$value,$type=1){
		if($field[0]=='"'){//quote v exactly (don't escape),
			return $value;
		}elseif(is_int($field)){//the key is auto-generated, don't quote
			return $value;
		}elseif(is_array($value)){
			$equator = 'IN';
			$values = implode(', ', array_map([$this, 'quote'], $value));
			return self::quoteIdentity($field).' IN ('.$values.')';
		}else{
			if($field[0]=='?'){//optional pair, dependent on there being a value
				if(!$value){
					return;
				}
				$field = substr($field,1);
			}
			if(strpos($field,'?')!==false){
				preg_match('@(^[^?]+)\?([^?]+)$@',$field,$match);
				$field = trim($match[1]);
				$equator = $match[2];
			}else{
				$equator = '=';
			}

			if($field[0]==':'){
				$field = substr($field,1);
				if($value == 'null' || $value === null){
					if($type == 1 && $equator == '='){
						$equator = 'is';
					}
					$value = 'null';
				}
			}elseif($value === null){
				if($type == 1 && $equator == '='){
					$equator = 'is';
				}
				$value = 'null';
			}else{
				$value = $this->quote($value);
			}
			$field = self::quoteIdentity($field);
			return $field.' '.$equator.' '.$value;
		}
	}



	/// construct where clause prefixed withe `WHERE`
	protected function where($where, $gauranteed_where=true){
		$conditions_sql = $this->conditions($where);

		if($conditions_sql || $gauranteed_where){
			return "\nWHERE ".$conditions_sql;
		}
	}
	/// construct where clause from array or string
	/**
	@param	where	various forms:
		- either plain sql statement "bob = 'sue'"
		- single identifier "fj93" translated to "id = 'fj93'"
		- key to value array.	See self::ktvf()
	@return	where string
	@note if the where clause does not exist, function will just return nothing; this generally leads to an error
	*/
	protected function conditions($where){
		if(!$where){
			return;
		}elseif(is_array($where)){
			$where = implode("\n\tAND ",$this->ktvf($where));
		}elseif(!preg_match('@[ =<>]@',$where)){//ensures where is not long where string (bob=sue, bob is null), but simple item.
			if((string)(int)$where != $where){
				$where = $this->quote($where);
			}
			$where = 'id = '.$where;
		}
		return $where;
	}

	# does single query for multiple inserts.  Uses first row as key template
	/* params
	< command > < SQL insert command, like "INSERT" or "REPLACE" >
	< table > < the db table name >
	< rows > {
			< column_name > : < value >
			...
		}
	*/
	protected function intos($command,$table,$rows){
		//use first row as template
		list($keys) = self::kvp($rows[0]);
		$insertRows = array();
		foreach($rows as $row){
			list(,$values) = self::kvp($row);
			$insertRows[] = '('.implode(',',$values).')';
		}
		$this->query($command.' INTO '.$this->quoteIdentity($table).' ('.implode(',',$keys).")\t\nVALUES ".implode(',',$insertRows));
	}

	/// Key value parser
	protected function kvp($kvA){
		foreach($kvA as $k=>$v){
			if($k[0]==':'){
				$k = substr($k,1);
				if($v === null){
					$v = 'null';
				}
			}elseif($v === null){
				$v = 'null';
			}else{
				$v = $this->quote($v);
			}
			$keys[] = $this->quoteIdentity($k);
			$values[] = $v;
		}
		return array($keys,$values);
	}

	/// Key value formatter (used for insert like statements)
	/**
	@param	kva	array('key' => 'value',...)	special syntax is applied:
		- normally, sets (key) values (value) with the value escaped
		- if key starts with ":", value is not escaped
		- if value = null (php null), set string to null
	*/
	protected function kvf($kvA){
		list($keys,$values) = self::kvp($kvA);
		return ' ('.implode(',',$keys).")\t\nVALUES (".implode(',',$values).') ';
	}


	/// Insert into a table
	/**
	@param	table	table to insert on
	@param	kva	see self::kvf() function
	@return	see self::into
	*/
	protected function insert($table,$kvA){
		return $this->into('INSERT',$table,$kvA);
	}
	/// Insert with a table and ignore if duplicate key found
	/**
	@param	table	table to insert on
	@param	kva	see self::kvf() function
	@return	see self::into
	*/
	protected function insertIgnore($table,$kvA,$matchKeys=null){
		if($this->driver == 'sqlite'){
			$type = 'INSERT OR IGNORE';
		}else{
			$type = 'INSERT IGNORE';
		}
		return $this->into($type, $table, $kvA, '', $matchKeys);
	}
	# the overhead is worth the expectation
	protected function insert_ignore(){
		return calL_user_func_array([$this,'insertIgnore'], func_get_args());
	}
	/// insert into table; on duplicate key update
	/*
	@param	table	table to insert on
	@param	kva	see self::kvf() function
	@param	update	either plain sql or null; if null, defaults to updating all values to $kvA input
	@param	matchKeys	keys used to identify row to get the id
	@return	see self::into
	*/
	protected function insertUpdate($table,$kvA,$update=null,$matchKeys=null){
		if(!$update){
			$update .= implode(', ',$this->ktvf($kvA,2));
		}elseif(is_array($update)){
			$update = implode(', ',$this->ktvf($update,2));
		}
		return $this->into('INSERT',$table,$kvA,"\nON DUPLICATE KEY UPDATE\n".$update,$matchKeys);
	}
	# the overhead is worth the expectation
	protected function insert_update(){
		return call_user_func_array([$this,'insertUpdate'], func_get_args());
	}

	/// replace on a table
	/*
	@param	table	table to replace on
	@param	kva	see self::kvf() function
	@param	matchKeys	keys used to identify row to get the id
	@return	see Db::into
	*/
	protected function replace($table,$kvA,$matchKeys=null){
		if($this->driver == 'sqlite'){
			$type = 'INSERT OR REPLACE';
		}else{
			$type = 'REPLACE';
		}
		return $this->into($type,$table,$kvA,'',$matchKeys);
	}

	/// internal use; perform insert into [called from in(), inUp()]
	/*
	@note	insert ignore and insert update do not return a row id, so, if the id is not provided and the matchKeys are not provided, may not return row id
	@return will attempt to get row id, otherwise will return count of affected rows
	*/
	protected function into($type,$table,$kvA,$update='',$matchKeys=null){
		$res = $this->query($type.' INTO '.$this->quoteIdentity($table).$this->kvf($kvA).$update);
		if($this->under->lastInsertId()){
			return $this->under->lastInsertId();
		}elseif(!empty($kvA['id'])){
			return $kvA['id'];
		}elseif($matchKeys){
			$matchKva = Arrays::extract($matchKeys,$kvA);
			return $this->value($table,$matchKva,'id');
		}else{
			return $res->rowCount();
		}
	}

	/// perform update, returns number of affected rows
	/*
	@param	table	table to update
	@param	update	see self::ktvf() function
	@param	where	see self::where() function
	@return	row count
	*/
	protected function update($table,$update,$where){
		if(!$where){
			Debug::toss('Unqualified update is too risky.  Use 1=1 to verify');
		}

		$vf=implode(', ',$this->ktvf($update,2));
		$res = $this->query('UPDATE '.$this->quoteIdentity($table).' SET '.$vf.$this->where($where));
		return $res->rowCount();
	}

	/// perform delete
	/*
	@param	table	table to replace on
	@param	where	see self::where() function
	@return	row count
	@note as a precaution, to delete all must use $where = '1 = 1'
	*/
	protected function delete($table,$where){
		if(!$where){
			Debug::toss('Unqualified delete is too risky.  Use 1=1 to verify');
		}
		return $this->query('DELETE FROM '.$this->quoteIdentity($table).$this->where($where))->rowCount();
	}

	///generate sql
	/*
	Ex:
		- row('select * from user where id = 20') vs row('user',20);
		- rows('select name from user where id > 20') vs sRows('user',array('id?>'=>20),'name')
	@param	from	table, array of tables, or from statement
	@param	where	see self::$where()
	@param	columns	list of columns; either string or array.	"*" default.
	@param	order	order by columns
	@param	limit	result limit
	@return sql string
	@note	this function is just designed for simple queries
	*/
	protected function select($from,$where=null,$columns='*',$order=null,$limit=null){
		if(!$columns){
			$columns = '*';
		}
		if(is_array($from)){
			implode(', ', array_map([$this,'quoteIdentity'],$from));
		}elseif(strpos($from,' ') === false){//ensure no space; don't quote a from statement
			$from = $this->quoteIdentity($from);
		}
		if(is_array($columns)){
			$columns = implode(', ',array_map([$this,'quoteIdentity'],$columns));
		}
		$select = 'SELECT '.$columns."\nFROM ".$from.$this->where($where, false);
		if($order){
			if(!is_array($order)){
				$order = Arrays::toArray($order);
			}
			$orders = array();
			foreach($order as $part){
				$part = explode(' ',$part);
				if(empty($part[1])){
					$part[1] = 'ASC';
				}
				//'"' works with functions like "sum(cost)"
				$orders[] = $this->quoteIdentity($part[0]).' '.$part[1];
			}
			$select .= "\nORDER BY ".implode(',',$orders);
		}
		if($limit){
			$select .= "\nLIMIT ".$limit;
		}
		return $select;
	}
	# alias for `select`
	protected function sql(){
		return call_user_func_array([$this, 'select'], func_get_args());
	}
//+ helper tools {
	/// query check if there is a match
	/**See class note for input
	@return	true if match, else false
	*/
	protected function check($table,$where){
		$sql = $this->select($table,$where,'1');
		return $this->value($sql) ? true : false;
	}
	# alias for "check"
	protected function exists($table, $where){
		$alias = 'check';
		return call_user_func_array([$this,$alias], func_get_args());
	}



	/// get the id of some row, or make it if the row doesn't exist
	/**
	@param	additional	additional fields to merge with where on insert
	*/
	protected function id($table,$where,$additional=null){
		$sql = $this->select($table,$where,'id');
		$id = $this->value($sql);
		if(!$id){
			if($additional){
				$where = Arrays::merge($where,$additional);
			}
			$id = $this->insert($table,$where);
		}
		return $id;
	}

	///get id based on name if non-int, otherwise return int
	/**
		@param	dict	dictionary to update on query
	*/
	protected function namedId($table,$name,&$dict=null){
		if(Tool::is_int($name)){
			return $name;
		}
		$id = $this->value($table,['name'=>$name],'id');
		if($dict !== null){
			$dict[$name] = $id;
		}
		return $id;
	}
	# the overhead is worth the expectation
	protected function named_id(){
		return call_user_func_array([$this,'namedId'], func_get_args());
	}

	/// perform a count and select rows; doesn't work with all sql
	/**
	Must have "ORDER" on separate and single line
	Must have "LIMIT" on separate line
	@return	array($count,$results)
	*/
	protected function countAndRows($countLimit,$sql){
		$sql = $this->getOverloadedSql(2,func_get_args());
		$countSql = $sql;
		//get sql limit if exists from last part of sql
		$limitRegex = '@\sLIMIT\s+([0-9,]+( [0-9,]+)?)\s*$@i';
		if(preg_match($limitRegex,$countSql,$match)){
			$limit = $match[1];
			$countSql = preg_replace($limitRegex,'',$countSql);
		}

		//order must be on single line or this will not work
		$orderRegex = '@\sORDER BY[\t ]+([^\n]+)\s*$@i';
		if(preg_match($orderRegex,$countSql,$match)){
			$order = $match[1];
			$countSql = preg_replace($orderRegex,'',$countSql);
		}

		$countSql = array_pop(preg_split('@[\s]FROM[\s]@i',$countSql,2));
		if($countLimit){
			$countSql = "SELECT COUNT(*)\n FROM (\nSELECT 1 FROM \n".$countSql."\nLIMIT ".$countLimit.') t ';
		}else{
			$countSql = "SELECT COUNT(*)\nFROM ".$countSql;
		}
		$count = $this->value($countSql);
		$results = $this->rows($sql);
		return array($count,$results);
	}
	# the overhead is worth the expectation
	protected function count_and_rows(){
		return calL_user_func_array([$this,'countAndRows'], func_get_args());
	}
//+ }

//+	db information {
	protected function tableExists($table){
		if($this->tablesInfo[$table]){
			return true;
		}
		return (bool) count($this->rows('show tables like '.$this->quote($table)));
	}
	# the overhead is worth the expectation
	protected function table_exists(){
		return call_user_func_array([$this,'tableExists'], func_get_args());
	}
	//Get database tables
	protected function tables(){
		$driver = $this->under->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if($driver == 'mysql'){
			return $this->column('show tables');
		}elseif($driver == 'sqlite'){
			return $this->column('SELECT name FROM sqlite_master WHERE type='.$this->quote('table'));
		}
		throw new \Exception('Unsupported driver "'.$driver.'" for function');
	}

	public $tablesInfo = [];
	//get database table column information
	protected function tableInfo($table){
		if(!$this->tablesInfo[$table]){
			$columns = array();
			$keys = array();
			$driver = $this->under->getAttribute(\PDO::ATTR_DRIVER_NAME);
			if($driver == 'mysql'){
				//++ get the columns info {
				$rows = $this->rows('describe '.$this->quoteIdentity($table));
				foreach($rows as $row){
					$column =& $columns[$row['Field']];
					$column['type'] = self::parseColumnType($row['Type']);
					$column['limit'] = self::parseColumnLimit($row['Type']);
					$column['nullable'] = $row['Null'] == 'NO' ? false : true;
					$column['autoIncrement'] = preg_match('@auto_increment@',$row['Extra']) ? true : false;
					$column['default'] = $row['Default'];
					$column['key'] = $row['Key'] == 'PRI' ? 'primary' : $row['Key'];
				}
				//++ }

				//++ get the unique keys info {
				$rows = $this->rows('show index in '.$this->quoteIdentity($table));
				foreach($rows as $row){
					if($row['Non_unique'] === '0'){
						$keys[$row['Key_name']][] = $row['Column_name'];
					}
				}
				//++ }
			}elseif($driver == 'sqlite'){
				$statement = $this->value('SELECT sql FROM sqlite_master WHERE type='.$this->quote('table').' and tbl_name = '.$this->quote($table));
				if($statement){
					$info = self::create_statement_parse($statement);
					$columns = $info['columns'];
				}
			}
			$this->tableInfo[$table] = ['columns'=>$columns,'keys'=>$keys];
		}
		return $this->tableInfo[$table];
	}
	static function create_statement_parse($statement){
		preg_match('/create .*?[`"](.*?)[`"].*?\((.*)\)/sim', $statement, $match);
		$table = $match[1];
		$content = $match[2];
		$lines = preg_split('/\n/', $content);
		$columns = [];
		foreach($lines as $line){
			preg_match('/[`"`](.*?)[`"`]([^\n]+)/', $line, $match);
			if($match){
				$columns[$match[1]] = ['type'=>self::parseColumnType($match[2])];
			}
		}
		return ['table'=>$table, 'columns'=>$columns];
	}
	# the overhead is worth the expectation
	protected function table_info(){
		return call_user_func_array([$this,'tableInfo'], func_get_args());
	}
	protected function column_names($table){
		return array_keys($this->table_info($table)['columns']);
	}
	//take db specific column type and translate it to general
	static function parseColumnType($type){
		$type = trim(strtolower(preg_replace('@\([^)]*\)|,@','',$type)));
		if(preg_match('@int@i',$type)){//int,bigint
			return 'int';
		}elseif(preg_match('@decimal@i',$type)){
			return 'decimal';
		}elseif(preg_match('@float@i',$type)){
			return 'float';
		}elseif(preg_match('@datetime|date|timestamp@i',$type)){
			return $type;
		}elseif(preg_match('@varchar|text@i',$type)){
			return 'text';
		}
	}
	static function parseColumnLimit($type){
		preg_match('@\(([0-9,]+)\)@',$type,$match);
		if(!empty($match[1])){
			$limit = explode(',',$match[1]);
			return $limit[0];
		}
	}
	public $indices;
	///get all the keys in a table, including the non-unique ones
	protected function indices($table){
		if(!$this->indices[$table]){
			$rows = $this->rows('show indexes in '.$this->quoteIdentity($table));
			foreach($rows as $row){
				if(empty($keys[$row['Key_name']])){
					$keys[$row['Key_name']] = ['unique'=>!(bool)$row['Non_unique']];
				}
				$keys[$row['Key_name']]['columns'][$row['Seq_in_index']] = $row['Column_name'];
			}
			$this->indices[$table] = $keys;
		}
		return $this->indices[$table];
	}
//+ }
	protected function startTransaction(){
		$this->under->beginTransaction();
	}
	# the overhead is worth the expectation
	protected function start_transaction(){
		return call_user_func_array([$this,'startTransaction'], func_get_args());
	}
	# to exit a transaction, you either commit it or roll it back
	protected function commitTransaction(){
		$this->under->commit();
	}
	# the overhead is worth the expectation
	protected function commit_transaction(){
		return call_user_func_array([$this,'commitTransaction'], func_get_args());
	}
	# to exit a transaction, you either commit it or roll it back
	protected function rollbackTransaction(){
		$this->under->rollBack();
	}
	# the overhead is worth the expectation
	protected function rollback_transaction(){
		return call_user_func_array([$this,'rollbackTransaction'], func_get_args());
	}

	protected function lock_create($name, $options=[]){
		# assume this indirect use is simple and intends for lock to be released before/at end of script
		$options = array_merge(['unlock_on_exit'=>true], $options);
		return new DbLock($this, $name, $options);
	}


	protected function records_copy_over($table_from, $table_to, $where, $options=[]){
		$defaults = ['type'=>'ignore'];
		$options = array_merge($defaults, $options);

		$from_columns = $this->column_names($table_from);
		$to_columns = $this->column_names($table_to);
		$matching_columns = array_intersect($from_columns, $to_columns);

		$column_list = implode(', ', array_map([$this,'quote_identity'], $matching_columns));

		$driver = $this->under->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if($driver == 'mysql'){
			if($options['type']=='ignore'){
				$type = 'insert ignore';
			}else{
				$type = $options['type'];
			}
		}elseif($driver == 'sqlite'){
			if($options['type']=='ignore'){
				$type = 'insert or ignore';
			}else{
				$type = $options['type'];
			}
		}

		return $this->query($type.' INTO '.$this->quote_identity($table_to).' ('.$column_list.') select '.$column_list.' from '.$this->quote_identity($table_from).' '.$this->where($where, false).' ');
	}
}
