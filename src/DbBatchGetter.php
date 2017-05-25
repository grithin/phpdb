<?
namespace Grithin;

use \Grithin\Db;

class DbBatchGetter implements \Iterator{
	public $position = 0;
	public $step, $sql, $db;
	public $currentRows = [];

	function __construct($sql, $step=100, $db=null){
		if(!$db){
			$db = Db::primary();
		}
		$this->db = $db;
		$this->sql = $sql;
		$this->step = $step;
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
		$sql = $this->db->limit_apply($this->sql, $this->position * $this->step.', '.$this->step);
		$this->currentRows = $this->db->rows($sql);
		return (bool)$this->currentRows;
	}
}
/* Example
$sql = Db::sql('user', ['id?>'=>1], 'id');
$db = Db::instance('instance name');

$batches = (new \Grithin\DbBatchGetter($sql, 50, $db));
foreach($batches as $batch){
	foreach($batch as $row){
		$i++;
	}
	ppe($i);
}
*/