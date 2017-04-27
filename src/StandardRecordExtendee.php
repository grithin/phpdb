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


		# transform initial_record, as thought it were from the database
		if($options['initial_record'] && $this->transformers['get']){
			$options['initial_record'] = $this->transformers['get']($options['initial_record']);
		}elseif($options['transformed_initial_record']){ # bypass get transformation
			$options['initial_record'] = $options['transformed_initial_record'];
		}

		# identifier is an id, use the id column (which might not be named `id`)
		if(Tool::isInt($identifier)){
			$identifier = [static::$id_column => $identifier];
		}

		parent::__construct($identifier, [$this, 'getter'], [$this, 'setter'], $options);
	}

	static function from_initial($initial_record=[], $identifier=null){
		return new static($identifier, ['initial_record'=>$initial_record]);
	}
	static function from_transformed($transformed_record=[], $identifier=null){
		return new static($identifier, ['transformed_initial_record'=>$transformed_record]);
	}
	static function transform_on_get_apply($row){
		return static::from_initial()->transformers['get']($row);
	}
	static function transform_on_set_apply($row){
		return static::from_initial()->transformers['set']($row);
	}

	/*
	Allow for variable input to be transformed into a record, using the cases of:
	-	already a record, return it
	-	a scalar, use as identifier
	-	an array without the id_column, use as identifier
	-	an array with the id column
		-	if include other keys, use as initial record value
		-	if only one key, use as identifier
	*/
	static function construct_from($thing){
		if(\Grithin\Tool::is_scalar($thing)){
			return new static([static::$id_column=>$thing]); # identifier value
		}else{
			if(is_array($thing)){
				if(array_key_exists(static::$id_column, $thing)){
					if(count($thing) == 1){
						return new static($thing); # identifier array
					}else{
						return new static([static::$id_column=>$thing[static::$id_column]], ['initial_record'=>$thing]); # initial record
					}
				}else{
					return new static($thing); # identifier array
				}
			}elseif($thing instanceof static){
				return $thing;
			}else{
				throw new Exception('Could not construct from unknown thing');
			}
		}
	}

	# utility function to create a new record and return it as this class
	static function static_creates($record, $db=null, $options=[]){
		$db = $db ? $db : \Grithin\Db::primary();
		$id = $db->insert(static::$table, $record);
		$record[static::$id_column] = $id;
		$options['db'] = $db;
		$options['initial_record'] = $record;
		return new static([static::$id_column => $id], $options);
	}
	static function json_columns_extract_from_db($db){
		return self::json_columns_extract_from_db_by_table_and_db(static::$table, $db);
	}
}
