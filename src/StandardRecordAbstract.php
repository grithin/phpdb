<?
/* About
\Grithin\Record that uses Db and transformers (like for handling __json columns)

*/

namespace Grithin;


use \Exception;

abstract class StandardRecordAbstract extends \Grithin\Record{
	public $transformers = ['get'=>null, 'set'=>null];
	# To be compatible with the  `StandardRecord`, which requires a instance based `json_mapped_columns`, this variable is thus not static
	# note, this confines the operations with this class. For normally static functions, it is now necessary to create a dummy instance
	public $json_mapped_columns = [];

	public function getter($self){
		$row = $this->db->row($this->table, $this->identifier);
		if(!$row){
			throw new Exception('Getter did not find row in table "'.$this->table.'" on identifier: '.Tool::json_encode($this->identifier));
		}
		if($this->transformers['get']){
			$row = $this->transformers['get']($row);
		}
		return $row;
	}


	# clear `__json` affix from column name
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
	# add `__json` affix to column name
	public function json_transform_on_set($changes){
		$detransformed_changes = (array)$changes;
		if($this->json_mapped_columns){
			foreach($this->json_mapped_columns as $column){
				if(array_key_exists($column, $detransformed_changes)){
					$detransformed_changes[$column.'__json'] = $detransformed_changes[$column];
					unset($detransformed_changes[$column]);
				}
			}
		}
		return self::static_json_encode($detransformed_changes);
	}
	public function setter($self, $changes){
		if($changes){
			# $this->record already has diff applied, which means removed columns will not be present
			# also, we deal with columns, so can't make sub-column changes, must apply to full column
			$based_changes = Arrays::pick($this->record, array_keys(Arrays::from($changes)));

			if($this->transformers['set']){
				$based_changes = $this->transformers['set']($based_changes);
			}
			if($based_changes){
				if($this->identifier){
					$this->db->update($this->table, $based_changes, $this->identifier);
				}
			}
		}

		return $this->record;
	}
	# record as it exists prior to the get transformer
	public function record_untransformed(){
		return $this->transformers['set']($this->record);
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
	static function json_columns_extract_from_db_by_table_and_db($table, $db){
		$names = $db->column_names($table);
		return self::json_columns_extract_by_affix(array_flip($names));
	}
}
