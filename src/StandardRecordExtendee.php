<?
/* About
For extending StandardRecordAbstract
*/

namespace Grithin;

use \Exception;

abstract class StandardRecordExtendee extends StandardRecordAbstract{
	static $table;
	static $id_column = 'id';
	public $transformers = ['get'=>null, 'set'=>null];
	public $json_mapped_columns = [];
	# see Grithin\Record for other option values
	/*	params
		options; {
			db: < db object >
			initial_record: < preloaded record >
		}
	*/
	public function __construct($identifier, $options=[]){
		$this->db = $options['db'] ? $options['db'] : \Grithin\Db::primary();

		if(!static::$table){
			throw new Exception('Must provide table');
		}
		$this->table = static::$table;

		#+ handle record transformer options {
		if(method_exists($this, 'tranformer_on_get')){
			$this->transformers['get'] = [$this, 'tranformer_on_get'];
		}
		if(method_exists($this, 'tranformer_on_set')){
			$this->transformers['set'] = [$this, 'tranformer_on_set'];
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
		}

		parent::__construct($identifier, [$this, 'getter'], [$this, 'setter'], $options);
	}
	# utility function to create a new record and return it as this class
	static function create($record, $db=null, $options=[]){
		$db = $db ? $db : \Grithin\Db::primary();
		$id = $db->insert(static::$table, $record);
		$record[static::$id_column] = $id;
		$options['db'] = $db;
		$options['initial_record'] = $record;
		return new static([static::$id_column => $id], $options);
	}

}