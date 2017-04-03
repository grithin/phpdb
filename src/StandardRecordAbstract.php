<?
/* About
\Grithin\Record that uses Db and transformers (like for handling __json columns)

*/

namespace Grithin;

use \Exception;

abstract class StandardRecordAbstract extends \Grithin\Record{
	public $transformers = ['get'=>null, 'set'=>null];
	public $json_mapped_columns = [];

	public function getter($self){
		$row = $this->db->row($this->table, $this->identifier);
		if($this->transformers['get']){
			$row = $this->transformers['get']($row);
		}
		return $row;
	}
	public function json_transform_on_get($row){
		$decoded = self::static_json_decode($row);
		if($this->json_mapped_columns){
			if(!is_array($this->json_mapped_columns)){
				$this->json_mapped_columns = self::json_columns_extract_by_affix($decoded);
			}
			foreach($this->json_mapped_columns as $column){
				if(array_key_exists($column.'__json', $decoded)){
					$decoded[$column] = $decoded[$column.'__json'];
					unset($decoded[$column.'__json']);
				}
			}
		}
		return $decoded;
	}
	public function json_transform_on_set($changes){
		$detransformed_changes = (array)$changes;
		if($this->json_mapped_columns){
			foreach($this->json_mapped_columns as $column){
				$detransformed_changes[$column.'__json'] = $detransformed_changes[$column];
				unset($detransformed_changes[$column]);
			}
		}
		return self::static_json_encode($detransformed_changes);
	}
	public function setter($self, $changes){
		if($changes){
			$changes_to_apply = Arrays::from($changes);
			if($this->transformers['set']){
				$changes_to_apply = $this->transformers['set']($changes_to_apply);
			}
			$this->db->update($this->table, $changes_to_apply, $this->identifier);
		}
		return Arrays::merge($this->stored_record, $changes);
	}
	static function json_columns_extract_by_affix($record){
		$columns = [];
		foreach($record as $column=>$value){
			if(substr($column, -6) == '__json'){
				$columns[] = substr($column, 0, -6);
			}
		}
		return $columns;
	}
}

/* Example, normal usage
$test = new StandardRecord(2, 'test', ['db'=>$db]);

$test->before_change($print);
$test->after_change($print);
$test->before_update($print);
$test->after_update($print);
$test['name'] = 'test5';
$test['name'] = 'test6';
$test['name'] = 'test6'; # won't trigger any events
$test->update(['name'=>'test4']);

$test['test__json'] = ['bob'=>'12dd43'];
pp('next');
$test->apply();
*/

/* Example, JSON mapping
$test = new StandardRecord(2, 'test', ['db'=>$db, 'json_mapped_columns'=>true]);
$test->before_change($print);
$test['test'] = ['bob'=>'12dd43iss'];
*/

/* Example, JSON mapping, preloaded record, encoded
$test = new StandardRecord(
		2,
		'test',
		[
			'db'=>$db,
			'json_mapped_columns'=>true,
			'initial_record'=>[
				'id'=>2,
				'test__json'=>json_encode(['bob'=>'12dd43iss'])
]]);
$test->before_change($print);
$test['test'] = ['bob'=>'12dd43iss'];
# no change
*/

/* Example, JSON mapping, preloaded record, decoded
$test = new StandardRecord(
		2,
		'test',
		[
			'db'=>$db,
			'json_mapped_columns'=>['test'],
			'initial_record'=>[
				'id'=>2,
				'test'=>['bob'=>'12dd43is3']
]]);
$test->before_change($print);
$test['test'] = ['bob'=>'12dd43is3s'];
$test->apply();
*/

/* Example, create new record
$test = StandardRecord::create('test',['name'=>'bul', 'test__json'=>''], $db, ['json_mapped_columns'=>true]);
$test->before_change($print);
$test['test'] = ['bob'=>'12dd43is3s'];
$test->apply();
*/
