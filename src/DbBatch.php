<?
namespace Grithin;
use Grithin\Db;
use Grithin\Debug;
///for getting and putting batches
class DbBatch implements \Iterator{
	use \Grithin\testCall;
	public $position, $step;
	public $sql;
	public $currentRows = array();

	public $db;
	function __construct($db=null){
		if(!$db){
			$db = Db::primary();
		}
		$this->db = $db;
		$this->position = 0;
	}
	/// used to translate static calls to the primary database instance
	static function __callStatic($name,$arguments){
		if(!method_exists(__class__,$name)){
			Debug::toss('DbBatch method not found: '.$name);
		}
		$class = __class__;
		$that = new $class();
		return call_user_func_array(array($that,$name),$arguments);
	}


//+	for getting batches {
/*
	@param	step	number of records to pull for each batch step
	@param	sql		overloadable sql (see Db class method parameters)

	Method can be overloaded to:
		@param	db	db object to use
		@param	step	step to batch
		@param	sql	overloadable sql

	example
		$batcher = DbBatch::get($db,'500','select * from user');
		foreach($batcher as $batch){
			foreach($batch as $row){

			}
		}

		$batcher = DbBatch::get('1000','isp','1=1','id,name');
		foreach($batcher as $batch){
			foreach($batch as $row){

			}
		}
	*/
	private function get($step,$sql){
		if(is_a($step,'Db')){
			$class = __class__;
			$that = new $class($step);
			return call_user_func_array(array($that,'get'),array_slice(func_get_args(),1));
		}
		$this->sql = $this->db->getOverloadedSql(2,func_get_args());
		$this->step = $step;
		return $this;
	}
	function rewind(){
		$this->position = 0;
	}

	function current(){
		return $this->currentRows;
	}

	function key(){
		return $this->position;
	}

	function next(){
		$this->position++;
	}

	function valid() {
		$limit = "\nLIMIT ".$this->position * $this->step.', '.$this->step;
		$this->currentRows = $this->db->rows($this->sql.$limit);
		return (bool)$this->currentRows;
	}
//+	}
//+	for putting batches {
	///insert multiple rows
	private function put($table,$rows){
		if($rows){
			return $this->db->intos('INSERT',$table,$rows);
		}
	}
	private function putIgnore($table,$rows){
		if($rows){
			return $this->db->intos('INSERT IGNORE',$table,$rows);
		}
	}
	private function putReplace($table,$rows){
		if($rows){
			return $this->db->intos('REPLACE',$table,$rows);
		}
	}
	///currently just executes insertUpdate for all rows
	private function putUpdate($table,$rows){
		if($rows){
			foreach($rows as $row){
				$this->db->insertUpdate($table,$row);
			}
		}
	}
//+	}
}
