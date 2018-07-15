<?
namespace Grithin;

class DbLock{
	public function __construct($db, $name, $options=[]){
		$this->db = $db;
		$this->name = $name;
		$this->name_quoted = $this->db->quote($this->name);
		$this->options = $options;
	}
	public function __destruct(){
		if($this->options['unlock_on_exit']){
			$this->unlock();
		}
	}
	public function lock($options=[]){
		$options = array_merge(['timeout'=>10], $this->options, $options);
		$this->locked = Db::value(["select GET_LOCK(?, ?)", [$this->name, $options['timeout']]]);
		return $this->locked;
	}
	public function locked(){
		return $this->locked;
	}
	# checks against database regardless of local locked variable
	public function is_free(){
		return Db::value(["SELECT IS_FREE_LOCK(?)", [$this->name]]);
	}
	public function release(){
		if($this->locked){
			$this->unlock();
		}else{
			return false;
		}
	}
	public function unlock(){
		$this->locked = false;
		return Db::value(["SELECT RELEASE_LOCK(?)", [$this->name]]);
	}
}
