<?
/* About
Generic instance use of StandardRecordAbstract
*/

namespace Grithin;

use \Exception;

class StandardRecord extends StandardRecordAbstract{
	public $transformers = ['get'=>null, 'set'=>null];
	public $json_mapped_columns = [];
	# see Grithin\Record for other option values
	/*	params
		options; {
			db: < db object >
			json_mapped_columns: (
				< array of columns without affix > []
				||
				< boolean, indicated getter should build list from gotten >
			)
			initial_record: < preloaded record >
		}
	*/
	public function __construct($identifier, $table, $options=[]){
		$this->db = $options['db'] ? $options['db'] : \Grithin\Db::primary();

		$this->table = $table;


		if(!empty($options['json_mapped_columns'])){
			$this->json_mapped_columns = $options['json_mapped_columns'];
		}

		#+ handle record transformer options {
		if(!empty($options['transformers'])){
			$this->transformers = Arrays::replace($this->transformers, $options['transformers']);
		}
		if($this->json_mapped_columns){
			if(!$this->transformers['get']){
				$this->transformers['get'] = [$this, 'json_transform_on_get'];
			}
			if(!$this->transformers['set']){
				$this->transformers['set'] = [$this, 'json_transform_on_set'];
			}
		}
		#+ }


		if($options['initial_record'] && $this->transformers['get']){
			$options['initial_record'] = $this->transformers['get']($options['initial_record']);
		}elseif(!empty($options['transformed_initial_record'])){ # bypass get transformation
			$options['initial_record'] = $options['transformed_initial_record'];
		}

		parent::__construct($identifier, [$this, 'getter'], [$this, 'setter'], $options);
	}

	# utility function to create a new record and return it as this class
	static function create($table, $record, $options=[]){
		$db = $options['db'] ? $options['db'] : \Grithin\Db::primary();
		$id = $db->insert($table, $record);
		$id_column = $options['id_column'] ? $options['id_column'] : 'id';
		$record[$id_column] = $id;
		$options['db'] = $db;
		$options['initial_record'] = $record;
		$class = __CLASS__;
		return new $class($id, $table, $options);
	}
}
