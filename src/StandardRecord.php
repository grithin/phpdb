<?
/* About
Record observer with standard handling for getter and setter using a db table
*/

namespace Grithin;

class StandardRecord extends \Grithin\Record{
	function __construct($identifier, $table, $options=[]){
		$this->db = $options['db'] ? $options['db'] : \Grithin\Db::primary();
		$this->table = $table;
		parent::__construct($identifier, [$this, 'getter'], [$this, 'setter'], $options);
	}
	function getter($self){
		$row = $this->db->row($this->table, $this->identifier);
		return self::static_decode_json($row);
	}
	function setter($self, $changes){
		if($changes){
			$encoded_changes = self::static_encode_json($changes);
			pp([$this->table, $encoded_changes, $this->identifier]);
			$this->db->update($this->table, $encoded_changes, $this->identifier);
		}
		return array_merge($this->stored_record, $changes);
	}
}


/* Testing

$assert = function($ought, $is){
	if($ought != $is){
		throw new Exception('ought is not is : '.\Grithin\Debug::pretty([$ought, $is]));
	}
};

$test = new StandardRecord(2, 'test', ['initial_record'=>['id'=>2, 'name'=>'test1', 'test__json'=>null]]);
$test['name'] = 'test2';
$test->apply();
$assert('test2', $test['name']);
$test->update(['name'=>'test4']);
$assert('test4', $test['name']);
$test->attach(function($instance, $event_name, $details){ pp($event_name); });
$test['name'] = 'test2';
$test->apply();
$test->attach(function($instance, $event_name, $details){
	if($event_name == 'before_update'){
		$instance->record['name'] = 'intervene';
	}
});
$test['name'] = 'test5';
$test->apply();
$assert('intervene', $test['name']);


$test['test__json'] = ['bob'=>'124'];
$test->apply();
$assert(['bob'=>'124'], $test['test__json']);

*/